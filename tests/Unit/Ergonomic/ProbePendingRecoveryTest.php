<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Ergonomic;

use Gisl\Generated\OpenApi\Model\LongFormConcurrencyLimitResponse;
use Gisl\Generated\OpenApi\Model\ProbePendingResponse;
use Gisl\Generated\OpenApi\Model\UploadProbeResponse;
use Gisl\Generated\OpenApi\Model\WorkflowCreateResponse;
use Gisl\Sdk\Cancellation;
use Gisl\Sdk\Ergonomic\BuilderInternals;
use Gisl\Sdk\Ergonomic\Merge;
use Gisl\Sdk\Ergonomic\MergeOptions;
use Gisl\Sdk\Ergonomic\ProbePendingRecovery;
use Gisl\Sdk\Ergonomic\RunOptions;
use Gisl\Sdk\Errors\GislAbortError;
use Gisl\Sdk\Errors\GislApiError;
use Gisl\Sdk\Errors\GislLongFormConcurrencyError;
use Gisl\Sdk\Errors\GislProbePendingError;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\Gisl;
use Gisl\Sdk\GislAnonymousClient;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use Gisl\Sdk\Http\Sleeper;
use Gisl\Sdk\JobDefinitionPayload;
use Gisl\Sdk\ProbeWaitOptions;
use Gisl\Sdk\ProbeWaitResult;
use Gisl\Sdk\Sources;
use Gisl\Sdk\WorkflowCreatePayload;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * dql51via: a `422 probe_pending` on create is recovered per the contract -
 * wait for the named job's upload probe, then re-create the SAME payload.
 * Mirrors TS tests/unit/probe-pending.test.ts.
 *
 * TIME (4v7Vwvcw): every test that waits runs on ONE fake clock, `$nowNs`. The
 * recovery reads it through BuilderInternals::nowMs() and sleeps through the
 * BuilderInternals per-slice primitive; the real waitForProbe reads it through
 * Http\Sleeper. Only the primitives are swapped, so the production slicing,
 * clamps and cancellation checks run. Recovery-LOGIC tests use a GislClient
 * test double whose createWorkflow / waitForProbe are scripted
 * ({@see scripted()}); end-to-end cases use the real client over stubbed
 * PSR-18, so the real patient waitForProbe runs.
 */
#[CoversClass(ProbePendingRecovery::class)]
final class ProbePendingRecoveryTest extends TestCase
{
    private const FID = '01936fb1-7bb3-7000-8000-000000000010';

    /** The test-side mirror of the SDK's (private) boundary margin. */
    private const BOUNDARY_MARGIN_MS = 2_000;

    /** Starts at 1_000_000 ms: an epoch-like ms value whose ns still fits an int. */
    private const CLOCK_START_NS = 1_000_000 * 1_000_000;

    private int $nowNs = self::CLOCK_START_NS;

    /** @var (\Closure(): void)|null runs after every fake sleep slice */
    private ?\Closure $onSlice = null;

    /** @var list<int> the clock (ms) at every scripted / recorded create */
    private array $createdAt = [];

    /** @var list<array{0: string, 1: ?ProbeWaitOptions}> every scripted waitForProbe call */
    private array $waitCalls = [];

    /** @var list<array{0: string, 1: int}> every recorded HTTP request: "METHOD path", clock ms */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->useFakeClock();
    }

    protected function tearDown(): void
    {
        BuilderInternals::setClockForTesting(null);
        BuilderInternals::setSleepForTesting(null);
        Sleeper::setForTesting(null, null);
    }

    /** Binds the BuilderInternals clock, its sleep AND Sleeper to the one `$nowNs`. */
    private function useFakeClock(): void
    {
        $this->nowNs = self::CLOCK_START_NS;
        $sleep = function (int $us): void {
            $this->nowNs += $us * 1_000;
            if ($this->onSlice !== null) {
                ($this->onSlice)();
            }
        };
        BuilderInternals::setClockForTesting(fn (): int => $this->nowMs());
        BuilderInternals::setSleepForTesting($sleep);
        Sleeper::setForTesting(fn (): int => $this->nowNs, $sleep);
    }

    private function nowMs(): int
    {
        return \intdiv($this->nowNs, 1_000_000);
    }

    private function advanceMs(int $ms): void
    {
        $this->nowNs += $ms * 1_000_000;
    }

    /** @return list<int> the create clocks relative to the first */
    private function relativeCreates(): array
    {
        $first = $this->createdAt[0] ?? 0;
        return \array_map(static fn (int $t): int => $t - $first, $this->createdAt);
    }

    public function testUploadFileIdsForJobMatchesIdsAndServerTokens(): void
    {
        $payload = new WorkflowCreatePayload(jobs: [
            new JobDefinitionPayload(operations: [], source: Sources::upload('f0')),
            new JobDefinitionPayload(operations: [], id: 'named', source: Sources::upload('f1')),
            new JobDefinitionPayload(operations: [], id: 'merge', inputs: [
                ['source' => Sources::upload('f2')],
                ['source' => ['type' => 'job_output', 'from' => 'named']],
                ['source' => Sources::upload('f2')],
            ]),
        ]);

        self::assertSame(['f1'], ProbePendingRecovery::uploadFileIdsForJob($payload, 'named'));
        self::assertSame(['f0'], ProbePendingRecovery::uploadFileIdsForJob($payload, 'job_0'));
        self::assertSame([], ProbePendingRecovery::uploadFileIdsForJob($payload, 'job_1'), 'job_1 carries its own id');
        self::assertSame(['f2'], ProbePendingRecovery::uploadFileIdsForJob($payload, 'merge'));
        self::assertSame([], ProbePendingRecovery::uploadFileIdsForJob($payload, 'nope'));
        self::assertSame([], ProbePendingRecovery::uploadFileIdsForJob($payload, null));
    }

    // -----------------------------------------------------------------------
    // End to end: the real client over stubbed PSR-18, through run() where the
    // public entry point is the point.
    // -----------------------------------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function retryableStatuses(): iterable
    {
        yield 'ok' => ['ok'];
        yield 'missing_metadata' => ['missing_metadata'];
    }

    #[DataProvider('retryableStatuses')]
    public function testRunRecoversWithoutTheCallerWritingTheLoop(string $probeStatus): void
    {
        $captured = [];
        $client = self::makeClient(self::stubClient([
            self::jsonResponse(200, self::uploadOk()),
            self::refusal(),
            self::probe($probeStatus),
            self::jsonResponse(201, self::createOk()),
            self::jsonResponse(200, self::statusCompleted()),
            self::jsonResponse(200, self::downloadsOk()),
        ], $captured));

        $result = $client->compress(self::writeTempFile('bytes'))
            ->run(new RunOptions(maxWait: '5m', useSSE: false, pollIntervalMs: 100));

        self::assertSame('completed', $result->status);
        self::assertCount(6, $captured);
        self::assertSame('/api/uploads/' . self::FID . '/probe', $captured[2]->getUri()->getPath());
        self::assertSame((string) $captured[1]->getBody(), (string) $captured[3]->getBody(), 'the SAME payload is re-created');
    }

    /** @return iterable<string, array{string}> */
    public static function terminalRejections(): iterable
    {
        yield 'corrupt' => ['corrupt'];
        yield 'unsupported_codec' => ['unsupported_codec'];
    }

    #[DataProvider('terminalRejections')]
    public function testARejectedProbeIsNotRetried(string $probeStatus): void
    {
        $captured = [];
        $client = self::makeClient(self::stubClient([
            self::jsonResponse(200, self::uploadOk()),
            self::refusal(),
            self::probe($probeStatus),
        ], $captured));

        $this->expectException(GislProbePendingError::class);
        try {
            $client->compress(self::writeTempFile('bytes'))->run(new RunOptions(maxWait: '5m', useSSE: false));
        } finally {
            self::assertCount(3, $captured);
        }
    }

    public function testAProbeThatNeverLandsGivesUpWithTheTypedRefusal(): void
    {
        $captured = [];
        $client = self::makeClient(self::stubClient([
            self::jsonResponse(200, self::uploadOk()),
            self::refusal(),
        ], $captured));

        $this->expectException(GislProbePendingError::class);
        try {
            // A zero probe budget fires no probe request: the wait gives up at once.
            $client->compress(self::writeTempFile('bytes'))
                ->run(new RunOptions(maxWait: '5m', useSSE: false, probeTimeoutMs: 0));
        } finally {
            self::assertCount(2, $captured);
        }
    }

    public function testCreatesTheServerKeepsRefusingAreBoundedByTheBudgetAndPaced(): void
    {
        // Hal5c41E / 4v7Vwvcw: no create-count cap. The probe lands at once every
        // time, so the pacing is the only gap: 1 s doubling from each create's
        // start, until the next start would cross the 60 s budget.
        $client = self::makeClient($this->recordingHttp(static fn (RequestInterface $r): ResponseInterface => \str_ends_with($r->getUri()->getPath(), '/probe')
            ? self::probe('ok')
            : self::refusal('op', null, 'refusal')));

        try {
            ProbePendingRecovery::create($client, self::payload(), probeTimeoutMs: 60_000);
            self::fail('expected the refusal');
        } catch (GislProbePendingError) {
            self::assertSame([0, 1_000, 3_000, 7_000, 15_000, 31_000], $this->relativeCreates());
            self::assertSame(31_000, $this->nowMs() - 1_000_000, 'no sleep past the last create');
        }
    }

    public function testACancelledTokenCreatesNothing(): void
    {
        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));
        $token = new Cancellation();
        $token->cancel();

        try {
            ProbePendingRecovery::create($client, self::payload(), cancellation: $token);
            self::fail('a cancelled create must throw');
        } catch (GislAbortError) {
            self::assertCount(0, $captured);
        }
    }

    public function testTheRefusalsRetryAfterIsHonouredBeforePolling(): void
    {
        $queue = [self::refusal('op', '2'), self::probe('ok'), self::jsonResponse(201, self::createOk())];
        $client = self::makeClient($this->recordingHttp(static function () use (&$queue): ResponseInterface {
            return \array_shift($queue) ?? throw new \RuntimeException('queue exhausted');
        }));

        ProbePendingRecovery::create($client, self::payload());

        self::assertSame([
            ['POST /api/workflows', 0],
            ['POST /api/uploads/' . self::FID . '/probe', 2_000],
            ['POST /api/workflows', 2_000],
        ], $this->relativeRequests());
    }

    public function testARetryAfterPastTheDeadlineIsATimeoutNow(): void
    {
        $captured = [];
        $client = self::makeClient(self::stubClient([self::refusal('op', '120')], $captured));

        try {
            ProbePendingRecovery::create($client, self::payload(), deadlineMs: BuilderInternals::nowMs() + 5_000);
            self::fail('expected a timeout');
        } catch (GislTimeoutError) {
            self::assertSame(1_000_000, $this->nowMs(), 'no wait at all');
            self::assertCount(1, $captured);
        }
    }

    public function testARetryAfterThatDoesNotFitTheBudgetRethrowsWithNoDeadline(): void
    {
        $captured = [];
        $client = self::makeClient(self::stubClient([self::refusal('op', '120')], $captured));

        try {
            ProbePendingRecovery::create($client, self::payload(), probeTimeoutMs: 1_000);
            self::fail('expected the refusal');
        } catch (GislProbePendingError) {
            self::assertSame(1_000_000, $this->nowMs(), 'no wait at all');
            self::assertCount(1, $captured);
        }
    }

    public function testANotApplicableUploadIsReCreatedWithoutAProbeWait(): void
    {
        // 8L4JJMx6: the probe says this upload is never probed, so there is nothing
        // to wait for — re-create instead of giving up with the refusal. The only
        // gap is the 1 s create pacing (4v7Vwvcw).
        $queue = [self::refusal(), self::probeNotApplicable(), self::jsonResponse(201, self::createOk())];
        $client = self::makeClient($this->recordingHttp(static function () use (&$queue): ResponseInterface {
            return \array_shift($queue) ?? throw new \RuntimeException('queue exhausted');
        }));

        $created = ProbePendingRecovery::create($client, self::payload());

        self::assertSame('01936fb2-0000-7000-8000-0000000000d1', $created->getWorkflowId());
        self::assertSame([
            ['POST /api/workflows', 0],
            ['POST /api/uploads/' . self::FID . '/probe', 0],
            ['POST /api/workflows', 1_000],
        ], $this->relativeRequests());
    }

    public function testANotApplicableUploadIsSkippedAndTheJobsOtherUploadIsStillAwaited(): void
    {
        // A watermark job: the logo image is never probed, the video is gated.
        $payload = new WorkflowCreatePayload(jobs: [
            new JobDefinitionPayload(operations: [], id: 'wm', inputs: [
                ['source' => Sources::upload('logo_png')],
                ['source' => Sources::upload('clip_mp4')],
            ]),
        ]);
        $captured = [];
        $client = self::makeClient(self::stubClient([
            self::refusal('wm'),
            self::probeNotApplicable(),
            self::probe('ok'),
            self::jsonResponse(201, self::createOk()),
        ], $captured));

        ProbePendingRecovery::create($client, $payload);

        self::assertCount(4, $captured);
        self::assertSame('/api/uploads/logo_png/probe', $captured[1]->getUri()->getPath());
        self::assertSame('/api/uploads/clip_mp4/probe', $captured[2]->getUri()->getPath());
        self::assertSame((string) $captured[0]->getBody(), (string) $captured[3]->getBody(), 'the SAME payload is re-created');
    }

    public function testANotApplicableUploadTheServerKeepsRefusingIsBoundedByTheBudgetNotACreateCount(): void
    {
        $client = self::makeClient($this->recordingHttp(static fn (RequestInterface $r): ResponseInterface => \str_ends_with($r->getUri()->getPath(), '/probe')
            ? self::probeNotApplicable()
            : self::refusal('op', null, 'refusal')));

        try {
            ProbePendingRecovery::create($client, self::payload());
            self::fail('expected the refusal');
        } catch (GislProbePendingError $e) {
            self::assertSame('refusal', $e->getMessage());
        }
        $creates = $this->relativeCreates();
        self::assertGreaterThan(3, \count($creates));
        self::assertLessThanOrEqual(50, \count($creates));
        self::assertLessThanOrEqual(ProbePendingRecovery::PROBE_WAIT_BOUND_MS, \max($creates));
        self::assertGreaterThan(ProbePendingRecovery::PROBE_WAIT_BOUND_MS - 30_000, \max($creates));
    }

    public function testALateNotApplicableAnswerPastTheBudgetRethrowsTheRefusalAndDoesNotReCreate(): void
    {
        // codex 5dd8528c4a6d: the probe request itself outlives the budget; its
        // not_applicable answer must not carry the recovery into a re-create.
        $queue = [self::refusal(), self::probeNotApplicable(), self::jsonResponse(201, self::createOk())];
        $client = self::makeClient($this->slowProbeHttp($queue, 10_000));
        try {
            ProbePendingRecovery::create($client, self::payload(), probeTimeoutMs: 5_000);
            self::fail('expected the original refusal');
        } catch (GislProbePendingError) {
            self::assertSame(['POST /api/workflows', 'POST /api/uploads/' . self::FID . '/probe'], \array_column($this->requests, 0), 'no re-create');
        }
    }

    public function testALateNotApplicableAnswerPastTheDeadlineIsATimeout(): void
    {
        $queue = [self::refusal(), self::probeNotApplicable(), self::jsonResponse(201, self::createOk())];
        $client = self::makeClient($this->slowProbeHttp($queue, 10_000));
        try {
            ProbePendingRecovery::create($client, self::payload(), probeTimeoutMs: 30_000, deadlineMs: $this->nowMs() + 8_000);
            self::fail('expected a timeout');
        } catch (GislTimeoutError $e) {
            self::assertCount(2, $this->requests, 'one create, one probe: no re-create');
            // Without the check in the not_applicable branch the post-loop deadline
            // check still times out, but claims "Probe landed", which is false.
            self::assertStringContainsString('while recovering from probe_pending', $e->getMessage());
        }
    }

    public function testRunWithProbeBeforeCreateFalseOptsOutOfRecovery(): void
    {
        $captured = [];
        $client = self::makeClient(self::stubClient([
            self::jsonResponse(200, self::uploadOk()),
            self::refusal(),
        ], $captured));

        $this->expectException(GislProbePendingError::class);
        try {
            $client->compress(self::writeTempFile('bytes'))
                ->run(new RunOptions(maxWait: '5m', useSSE: false, probeBeforeCreate: false));
        } finally {
            self::assertCount(2, $captured);
        }
    }

    public function testAProbe429MidRecoveryOnAMultiFileJobIsWaitedOutNotFatal(): void
    {
        // Two uploads in one job: the second's probe hits the shared per-user
        // probe bucket. Through the REAL waitForProbe, patient polling waits it out.
        $payload = new WorkflowCreatePayload(jobs: [
            new JobDefinitionPayload(operations: [], id: 'wm', inputs: [
                ['source' => Sources::upload('clip_a')],
                ['source' => Sources::upload('clip_b')],
            ]),
        ]);
        $queue = [
            self::refusal('wm'),
            self::probe('ok'),
            self::jsonResponse(429, ['success' => false, 'error' => 'TOO_MANY_REQUESTS', 'message' => 'Too many requests'])
                ->withHeader('Retry-After', '15'),
            self::probe('ok'),
            self::jsonResponse(201, self::createOk()),
        ];
        $client = self::makeClient($this->recordingHttp(static function () use (&$queue): ResponseInterface {
            return \array_shift($queue) ?? throw new \RuntimeException('queue exhausted');
        }));

        ProbePendingRecovery::create($client, $payload);

        self::assertSame([
            ['POST /api/workflows', 0],
            ['POST /api/uploads/clip_a/probe', 0],
            ['POST /api/uploads/clip_b/probe', 0],
            ['POST /api/uploads/clip_b/probe', 15_000],
            ['POST /api/workflows', 15_000],
        ], $this->relativeRequests());
    }

    // -----------------------------------------------------------------------
    // Hal5c41E / 4v7Vwvcw, signed in: the 900 s budget, paced creates, the
    // waited-out create 429 and one precise boundary create. Scripted client.
    // -----------------------------------------------------------------------

    public function testTheDefaultBudgetIsTheBoundAndThePollIsPatient(): void
    {
        $original = self::probePending();
        $client = $this->scripted(static fn () => throw $original, self::gaveUp('prober_error'));
        try {
            ProbePendingRecovery::create($client, self::payload());
            self::fail('expected the refusal');
        } catch (GislProbePendingError $e) {
            self::assertSame($original, $e);
        }
        self::assertSame(900_000, ProbePendingRecovery::PROBE_WAIT_BOUND_MS);
        self::assertSame(30_000, ProbePendingRecovery::GUEST_BACKOFF_MAX_MS);
        $options = $this->waitCalls[0][1];
        self::assertNotNull($options);
        self::assertSame(ProbePendingRecovery::PROBE_WAIT_BOUND_MS, $options->timeoutMs);
        self::assertTrue($options->patient);
    }

    public function testARecoveryLongerThan30sSucceedsByDefault(): void
    {
        $calls = 0;
        $client = $this->scripted(
            static function () use (&$calls): WorkflowCreateResponse {
                if (++$calls === 1) {
                    throw self::probePending();
                }
                return self::created();
            },
            function (): ProbeWaitResult {
                $this->advanceMs(120_000);
                return self::landed('ok');
            },
        );

        self::assertSame('wf', ProbePendingRecovery::create($client, self::payload())->getWorkflowId());
        self::assertSame([0, 120_000], $this->relativeCreates());
    }

    public function testAnExplicitShortTimeoutIsHonouredWithExactlyOneCreate(): void
    {
        $original = self::probePending();
        $client = $this->scripted(static fn () => throw $original, $this->neverLands());
        try {
            ProbePendingRecovery::create($client, self::payload(), probeTimeoutMs: 5_000);
            self::fail('expected the refusal');
        } catch (GislProbePendingError $e) {
            self::assertSame($original, $e);
        }
        self::assertCount(1, $this->createdAt);
        self::assertSame(5_000, $this->waitCalls[0][1]?->timeoutMs);
    }

    public function testAProbeThatDoesNotLandByTheDeadlineIsATimeoutNotTheRefusal(): void
    {
        $client = $this->scripted(static fn () => throw self::probePending(), $this->neverLands());
        try {
            ProbePendingRecovery::create($client, self::payload(), deadlineMs: $this->nowMs() + 20_000);
            self::fail('expected a timeout');
        } catch (GislTimeoutError) {
            self::assertCount(1, $this->createdAt);
            self::assertSame(20_000, $this->waitCalls[0][1]?->timeoutMs);
        }
    }

    public function testAProbeThatLandsOnlyAfterTheDeadlineIsATimeoutNotTheRefusal(): void
    {
        // The probe lands, but its answer arrives past the run deadline: the
        // re-create must not start, and the caller hears maxWait, not probe_pending.
        $client = $this->scripted(static fn () => throw self::probePending(), function (): ProbeWaitResult {
            $this->advanceMs(25_000);
            return self::landed('ok');
        });
        try {
            ProbePendingRecovery::create($client, self::payload(), deadlineMs: $this->nowMs() + 20_000);
            self::fail('expected a timeout');
        } catch (GislTimeoutError $e) {
            self::assertStringContainsString('Probe landed but maxWait elapsed', $e->getMessage());
            self::assertCount(1, $this->createdAt);
        }
    }

    public function testAPacedReCreateWhoseSleepOverranTheDeadlineIsATimeoutNotTheRefusal(): void
    {
        // The paced gap (1 s) fits before the deadline, but the sleep wakes 10 s
        // late, past it: the late-timer guard must time out without creating.
        $lateBy = 10_000;
        $this->onSlice = function () use (&$lateBy): void {
            $this->advanceMs($lateBy);
            $lateBy = 0;
        };
        $client = $this->scripted(static fn () => throw self::probePending());
        try {
            ProbePendingRecovery::create($client, self::payload(), deadlineMs: $this->nowMs() + 5_000);
            self::fail('expected a timeout');
        } catch (GislTimeoutError $e) {
            self::assertStringContainsString('while recovering from probe_pending', $e->getMessage());
            self::assertCount(1, $this->createdAt);
            self::assertSame(11_000, $this->nowMs() - 1_000_000, 'one 1 s slice, woken 10 s late');
        }
    }

    public function testTheBoundaryCreateIsMadeOnceTheMarginPastTheDefaultBudgetAndTheStrictServerAcceptsIt(): void
    {
        $client = $this->scripted($this->boundServer(), $this->neverLands());

        self::assertSame('wf', ProbePendingRecovery::create($client, self::payload())->getWorkflowId());
        // budgetEnd = first refusal + bound; the boundary create starts the margin past it.
        self::assertSame([0, ProbePendingRecovery::PROBE_WAIT_BOUND_MS + self::BOUNDARY_MARGIN_MS], $this->relativeCreates());
    }

    public function testTheServerModelIsStrictACreateAtTheBoundIsStillRefused(): void
    {
        // Positive control on boundServer(): at 900.999 s waitedSeconds is 900, not > 900.
        $server = $this->boundServer();
        $create = function () use ($server): WorkflowCreateResponse {
            $this->createdAt[] = $this->nowMs();
            return $server();
        };
        try {
            $create();
            self::fail('the first create must be refused');
        } catch (GislProbePendingError) {
        }
        $this->advanceMs(ProbePendingRecovery::PROBE_WAIT_BOUND_MS + 999);
        try {
            $create();
            self::fail('a create at the bound must still be refused');
        } catch (GislProbePendingError) {
        }
        $this->advanceMs(1);
        self::assertSame('wf', $create()->getWorkflowId());
    }

    public function testADeadlineBeforeTheBoundaryMarginIsATimeoutWithNoBoundaryCreate(): void
    {
        $client = $this->scripted($this->boundServer(), $this->neverLands());
        try {
            ProbePendingRecovery::create($client, self::payload(), deadlineMs: $this->nowMs() + ProbePendingRecovery::PROBE_WAIT_BOUND_MS + 1_000);
            self::fail('expected a timeout');
        } catch (GislTimeoutError) {
            self::assertCount(1, $this->createdAt);
        }
    }

    public function testARefusedBoundaryCreateIsTheLastAndTheFirstRefusalComesBack(): void
    {
        $original = self::probePending();
        $calls = 0;
        $client = $this->scripted(static function () use (&$calls, $original): never {
            throw ++$calls === 1 ? $original : self::probePending();
        }, $this->neverLands());
        try {
            ProbePendingRecovery::create($client, self::payload());
            self::fail('expected the refusal');
        } catch (GislProbePendingError $e) {
            self::assertSame($original, $e);
        }
        self::assertCount(2, $this->createdAt);
        self::assertCount(1, $this->waitCalls);
    }

    public function testNoBoundaryCreateAfterALateProbeAnswerPastTheTimerSlack(): void
    {
        // 2 s past the budget: beyond the 1 s timer slack but INSIDE paceCreate's
        // own margin + slack (3 s), so only the boundary's slack check refuses it.
        $original = self::probePending();
        $client = $this->scripted(static fn () => throw $original, $this->neverLands(overshootMs: 2_000));
        $this->expectExceptionObject($original);
        try {
            ProbePendingRecovery::create($client, self::payload());
        } finally {
            self::assertCount(1, $this->createdAt);
        }
    }

    public function testNoBoundaryCreateWhenTheBudgetIsShorterThanTheBound(): void
    {
        $original = self::probePending();
        $client = $this->scripted(static fn () => throw $original, $this->neverLands());
        $this->expectExceptionObject($original);
        try {
            ProbePendingRecovery::create($client, self::payload(), probeTimeoutMs: ProbePendingRecovery::PROBE_WAIT_BOUND_MS - 1);
        } finally {
            self::assertCount(1, $this->createdAt);
        }
    }

    /** @return iterable<string, array{int}> */
    public static function explicitBudgetsAtOrPastTheBound(): iterable
    {
        yield '900 000 ms' => [900_000];
        yield '1 200 000 ms' => [1_200_000];
    }

    /** @return iterable<string, array{int}> */
    public static function explicitBudgetsShortOfTheBoundaryCreate(): iterable
    {
        yield '900 000 ms' => [900_000];
        yield '901 000 ms (bound + 1 s, short of the 2 s margin)' => [901_000];
    }

    #[DataProvider('explicitBudgetsShortOfTheBoundaryCreate')]
    public function testStrictCapSignedInNoBoundaryCreateWhenItWouldLandPastAnExplicitBudget(int $budgetMs): void
    {
        // codex DISCUSS on #562: an explicit budget is a strict cap.
        $original = self::probePending();
        $client = $this->scripted(static fn () => throw $original, $this->neverLands());
        try {
            ProbePendingRecovery::create($client, self::payload(), probeTimeoutMs: $budgetMs);
            self::fail('expected the refusal');
        } catch (GislProbePendingError $e) {
            self::assertSame($original, $e);
        }
        self::assertSame([0], $this->relativeCreates());
        self::assertLessThanOrEqual($budgetMs, $this->nowMs() - 1_000_000);
    }

    public function testAnExplicitBudgetPastTheBoundaryMakesOneCreateWhenTheGateOpensWhichTheStrictServerAccepts(): void
    {
        // codex on #563: the gate opens 900 s after the upload, so a create at
        // ~902 s is accepted and is inside a 1 200 000 ms cap. The probe wait stops
        // there instead of running the whole 20 minutes.
        $client = $this->scripted($this->boundServer(), $this->neverLands());

        self::assertSame('wf', ProbePendingRecovery::create($client, self::payload(), probeTimeoutMs: 1_200_000)->getWorkflowId());
        self::assertSame([0, ProbePendingRecovery::PROBE_WAIT_BOUND_MS + self::BOUNDARY_MARGIN_MS], $this->relativeCreates());
        self::assertSame(ProbePendingRecovery::PROBE_WAIT_BOUND_MS, $this->waitCalls[0][1]?->timeoutMs);
        self::assertSame(ProbePendingRecovery::PROBE_WAIT_BOUND_MS + self::BOUNDARY_MARGIN_MS, $this->nowMs() - 1_000_000);
    }

    public function testAnExplicitBudgetsRefusedBoundaryCreateIsTheLastAndTheFirstRefusalComesBack(): void
    {
        $original = self::probePending();
        $calls = 0;
        $client = $this->scripted(static function () use (&$calls, $original): never {
            throw ++$calls === 1 ? $original : self::probePending();
        }, $this->neverLands());
        try {
            ProbePendingRecovery::create($client, self::payload(), probeTimeoutMs: 1_200_000);
            self::fail('expected the refusal');
        } catch (GislProbePendingError $e) {
            self::assertSame($original, $e);
        }
        self::assertSame([0, ProbePendingRecovery::PROBE_WAIT_BOUND_MS + self::BOUNDARY_MARGIN_MS], $this->relativeCreates());
    }

    #[DataProvider('explicitBudgetsAtOrPastTheBound')]
    public function testStrictCapSignedInEveryPacedCreateStartsByTheExplicitBudgetEnd(int $budgetMs): void
    {
        // The probe lands at once every time, so the creates run to the budget.
        $original = self::probePending();
        $client = $this->scripted(static fn () => throw $original);
        try {
            ProbePendingRecovery::create($client, self::payload(), probeTimeoutMs: $budgetMs);
            self::fail('expected the refusal');
        } catch (GislProbePendingError $e) {
            self::assertSame($original, $e);
        }
        $creates = $this->relativeCreates();
        self::assertGreaterThan(3, \count($creates));
        self::assertLessThanOrEqual($budgetMs, \max($creates));
    }

    #[DataProvider('explicitBudgetsAtOrPastTheBound')]
    public function testStrictCapAGuestsLastCreateLandsAtTheExplicitBudgetEndNotPastIt(int $budgetMs): void
    {
        $guest = self::makeGuest($this->recordingHttp(static fn (): ResponseInterface => self::refusal('op', null, 'refusal')));
        try {
            ProbePendingRecovery::create($guest, self::payload(), probeTimeoutMs: $budgetMs);
            self::fail('expected the refusal');
        } catch (GislProbePendingError) {
        }
        $creates = $this->relativeCreates();
        self::assertLessThanOrEqual($budgetMs, \max($creates));
        self::assertSame($budgetMs, $creates[\count($creates) - 1], 'the final create is AT the budget end, margin 0');
    }

    public function testNoBoundaryCreateWhenTheProbeWaitGaveUpForAnotherReason(): void
    {
        $original = self::probePending();
        $client = $this->scripted(static fn () => throw $original, $this->neverLands(reason: 'prober_error'));
        $this->expectExceptionObject($original);
        try {
            ProbePendingRecovery::create($client, self::payload());
        } finally {
            self::assertCount(1, $this->createdAt);
        }
    }

    public function testNoBoundaryCreateWhenTheProbeWaitTimedOutBeforeTheBudgetEnded(): void
    {
        $original = self::probePending();
        $client = $this->scripted(static fn () => throw $original, self::gaveUp('timeout'));
        $this->expectExceptionObject($original);
        try {
            ProbePendingRecovery::create($client, self::payload());
        } finally {
            self::assertCount(1, $this->createdAt);
        }
    }

    public function testNoBoundaryCreateWhenTheDeadlineNotTheBudgetEndedTheWait(): void
    {
        $client = $this->scripted(static fn () => throw self::probePending(), $this->neverLands());
        try {
            ProbePendingRecovery::create($client, self::payload(), deadlineMs: $this->nowMs() + ProbePendingRecovery::PROBE_WAIT_BOUND_MS);
            self::fail('expected a timeout');
        } catch (GislTimeoutError) {
            self::assertCount(1, $this->createdAt);
        }
    }

    public function testA429OnTheBoundaryCreateIsNotWaitedOutPastTheBudget(): void
    {
        $original = self::probePending();
        $calls = 0;
        $client = $this->scripted(static function () use (&$calls, $original): never {
            throw ++$calls === 1 ? $original : self::tooManyRequests('60');
        }, $this->neverLands());
        $this->expectExceptionObject($original);
        try {
            ProbePendingRecovery::create($client, self::payload());
        } finally {
            self::assertCount(2, $this->createdAt);
        }
    }

    public function testCreatesArePacedInsideTheFreeWorkflowCreateLimits(): void
    {
        // The probe lands at once every time, so the pacing is the only gap.
        $original = self::probePending();
        $client = $this->scripted(static fn () => throw $original);
        $this->expectExceptionObject($original);
        try {
            ProbePendingRecovery::create($client, self::payload());
        } finally {
            $t = $this->relativeCreates();
            foreach ($t as $from) {
                $inWindow = \array_filter($t, static fn (int $x): bool => $x >= $from && $x < $from + 60_000);
                self::assertLessThanOrEqual(10, \count($inWindow), "window from {$from} ms");
            }
            self::assertLessThanOrEqual(50, \count($t));
            // No count cap: it kept going, and the budget is what ended it.
            self::assertGreaterThan(3, \count($t));
            self::assertLessThanOrEqual(ProbePendingRecovery::PROBE_WAIT_BOUND_MS, $t[\count($t) - 1]);
            self::assertGreaterThan(ProbePendingRecovery::PROBE_WAIT_BOUND_MS - 30_000, $t[\count($t) - 1]);
            // Doubling from 1 s, capped at 30 s, from each create's start.
            self::assertSame([0, 1_000, 3_000, 7_000, 15_000, 31_000, 61_000, 91_000], \array_slice($t, 0, 8));
        }
    }

    public function testTheRefusalsRetryAfterPacesTheNextCreateWhenLongerThanTheBackoff(): void
    {
        $calls = 0;
        $client = $this->scripted(static function () use (&$calls): WorkflowCreateResponse {
            if (++$calls === 1) {
                throw self::probePending('op', '5');
            }
            return self::created();
        });
        ProbePendingRecovery::create($client, self::payload());
        self::assertSame([0, 5_000], $this->relativeCreates());
    }

    public function testACreate429TooManyRequestsMidRecoveryIsWaitedOutForItsRetryAfter(): void
    {
        $calls = 0;
        $client = $this->scripted(static function () use (&$calls): WorkflowCreateResponse {
            ++$calls;
            if ($calls === 1) {
                throw self::probePending();
            }
            if ($calls === 2) {
                throw self::tooManyRequests('20');
            }
            return self::created();
        });
        ProbePendingRecovery::create($client, self::payload());
        self::assertSame([0, 1_000, 21_000], $this->relativeCreates());
        self::assertCount(1, $this->waitCalls);
    }

    public function testACreate429TooManyRequestsWithNoRetryAfterIsWaitedOutWithTheCreateBackoff(): void
    {
        $calls = 0;
        $client = $this->scripted(static function () use (&$calls): WorkflowCreateResponse {
            ++$calls;
            if ($calls === 1) {
                throw self::probePending();
            }
            if ($calls === 2) {
                throw self::tooManyRequests();
            }
            return self::created();
        });
        ProbePendingRecovery::create($client, self::payload());
        self::assertSame([0, 1_000, 3_000], $this->relativeCreates());
    }

    public function testACreate429WhoseRetryAfterOutlastsTheBudgetRethrowsTheFirstRefusalAtOnce(): void
    {
        $original = self::probePending();
        $calls = 0;
        $client = $this->scripted(static function () use (&$calls, $original): never {
            throw ++$calls === 1 ? $original : self::tooManyRequests('600');
        });
        $this->expectExceptionObject($original);
        try {
            ProbePendingRecovery::create($client, self::payload(), probeTimeoutMs: 60_000);
        } finally {
            self::assertCount(2, $this->createdAt);
            self::assertSame(1_000, $this->nowMs() - 1_000_000, 'no wait after the 429');
        }
    }

    public function testACreate429WhoseRetryAfterOutlastsTheDeadlineIsATimeout(): void
    {
        $calls = 0;
        $client = $this->scripted(static function () use (&$calls): never {
            throw ++$calls === 1 ? self::probePending() : self::tooManyRequests('600');
        });
        try {
            ProbePendingRecovery::create($client, self::payload(), deadlineMs: $this->nowMs() + 60_000);
            self::fail('expected a timeout');
        } catch (GislTimeoutError) {
            self::assertCount(2, $this->createdAt);
        }
    }

    public function testDuplicateInProgressIsRethrownAtOnce(): void
    {
        $duplicate = self::tooManyRequests('1', 'DUPLICATE_IN_PROGRESS');
        $calls = 0;
        $client = $this->scripted(static function () use (&$calls, $duplicate): never {
            throw ++$calls === 1 ? self::probePending() : $duplicate;
        });
        $this->expectExceptionObject($duplicate);
        try {
            ProbePendingRecovery::create($client, self::payload());
        } finally {
            self::assertCount(2, $this->createdAt);
        }
    }

    public function testTheLongFormConcurrency429IsRethrownAtOnceEvenCarryingTooManyRequests(): void
    {
        // The code is TOO_MANY_REQUESTS so only the CLASS check can exclude it.
        $longForm = new GislLongFormConcurrencyError(
            'long-form limit',
            429,
            'TOO_MANY_REQUESTS',
            new LongFormConcurrencyLimitResponse(['error' => 'LONG_FORM_CONCURRENCY_LIMIT_EXCEEDED']),
            responseHeaders: ['retry-after' => '1'],
        );
        $calls = 0;
        $client = $this->scripted(static function () use (&$calls, $longForm): never {
            throw ++$calls === 1 ? self::probePending() : $longForm;
        });
        $this->expectExceptionObject($longForm);
        try {
            ProbePendingRecovery::create($client, self::payload());
        } finally {
            self::assertCount(2, $this->createdAt);
        }
    }

    public function testA429OnTheFirstCreateBeforeAnyProbePendingIsRethrown(): void
    {
        $limited = self::tooManyRequests('5');
        $client = $this->scripted(static fn () => throw $limited);
        $this->expectExceptionObject($limited);
        try {
            ProbePendingRecovery::create($client, self::payload());
        } finally {
            self::assertCount(1, $this->createdAt);
            self::assertSame(1_000_000, $this->nowMs(), 'no wait');
        }
    }

    public function testACancelDuringALongRetryAfterIsHonouredWithinOneSlice(): void
    {
        $token = new Cancellation();
        $slices = 0;
        $this->onSlice = static function () use (&$slices, $token): void {
            if (++$slices === 3) {
                $token->cancel();
            }
        };
        $client = $this->scripted(static fn () => throw self::probePending('op', '600'));
        try {
            ProbePendingRecovery::create($client, self::payload(), cancellation: $token);
            self::fail('expected GislAbortError');
        } catch (GislAbortError) {
            self::assertSame(3, $slices);
            self::assertSame(3_000, $this->nowMs() - 1_000_000, 'noticed before a 4th 1 s slice');
            self::assertCount(1, $this->createdAt);
            self::assertSame([], $this->waitCalls);
        }
    }

    public function testACancelDuringACreateBackoffIsHonouredWithinOneSlice(): void
    {
        // Refusal, probe lands, paced re-create refused 429 with a 600 s Retry-After,
        // then a cancel during that wait: no third create.
        $token = new Cancellation();
        $slices = 0;
        $this->onSlice = static function () use (&$slices, $token): void {
            if (++$slices === 5) {
                $token->cancel();
            }
        };
        $calls = 0;
        $client = $this->scripted(static function () use (&$calls): never {
            throw ++$calls === 1 ? self::probePending() : self::tooManyRequests('600');
        });
        try {
            ProbePendingRecovery::create($client, self::payload(), cancellation: $token);
            self::fail('expected GislAbortError');
        } catch (GislAbortError) {
            // 1 slice of create pacing, then 4 of the 429 wait.
            self::assertSame(5_000, $this->nowMs() - 1_000_000);
            self::assertCount(2, $this->createdAt);
        }
    }

    // -----------------------------------------------------------------------
    // 5dJrOdVC — anonymous-policy 2.1.0: the probe endpoint is sign-in only, so
    // a GUEST refused with probe_pending retries the CREATE after Retry-After
    // (or a doubling backoff) and never calls the probe. Mirrors the TS
    // "createWorkflowAwaitingProbe on a guest client" block. The real
    // (final) anonymous client over stubbed PSR-18, on the fake clock.
    // -----------------------------------------------------------------------

    public function testAGuestRunReCreatesWithoutPollingTheProbe(): void
    {
        $captured = [];
        $guest = self::makeGuest(self::stubClient([
            self::jsonResponse(200, self::uploadOk()),
            self::refusal('op', '1'),
            self::jsonResponse(201, self::guestCreateOk()),
            self::jsonResponse(200, self::statusCompleted()),
            self::jsonResponse(200, self::downloadsOk()),
        ], $captured));

        $result = $guest->compress(self::writeTempFile('bytes'))
            ->run(new RunOptions(maxWait: '5m', useSSE: false, pollIntervalMs: 100));

        self::assertSame('completed', $result->status);
        $paths = \array_map(static fn (RequestInterface $r): string => $r->getMethod() . ' ' . $r->getUri()->getPath(), $captured);
        self::assertSame('POST /api/workflows', $paths[1]);
        self::assertSame('POST /api/workflows', $paths[2], 'the guest re-creates; it does not poll the probe');
        self::assertSame([], \array_values(\array_filter($paths, static fn (string $p): bool => \str_ends_with($p, '/probe'))));
        self::assertSame((string) $captured[1]->getBody(), (string) $captured[2]->getBody(), 'the SAME payload is re-created');
    }

    public function testAGuestKeepsReCreatingPastThreeRefusalsUntilTheBudgetThenRethrowsTheFirst(): void
    {
        // fNSQUeDS: no count cap for a guest (refusals do not count against its
        // create limit); the budget ends it. Retry-After 1 s, budget 3.5 s.
        $calls = 0;
        $guest = self::makeGuest($this->recordingHttp(static function () use (&$calls): ResponseInterface {
            return self::refusal('op', '1', ++$calls === 1 ? 'first refusal' : 'later refusal');
        }));

        try {
            ProbePendingRecovery::create($guest, self::payload(), probeTimeoutMs: 3_500);
            self::fail('expected GislProbePendingError');
        } catch (GislProbePendingError $e) {
            // The FIRST refusal comes back, not the last (codex d63a045d884c).
            self::assertSame('first refusal', $e->getMessage());
        }
        // More than three creates: no count cap. The last lands AT the 3.5 s budget.
        self::assertSame([0, 1_000, 2_000, 3_000, 3_500], $this->relativeCreates());
        self::assertSame(['POST /api/workflows'], \array_values(\array_unique(\array_column($this->requests, 0))));
    }

    public function testAGuestMakesOneLastCreateAtTheBudgetBoundary(): void
    {
        // Budget 1.5 s, no Retry-After: create, wait 1 s, create, the next 2 s wait
        // would cross the budget, so wait the 0.5 s left and create once more.
        $queue = [self::refusal('op'), self::refusal('op'), self::jsonResponse(201, self::guestCreateOk())];
        $guest = self::makeGuest($this->recordingHttp(static function () use (&$queue): ResponseInterface {
            return \array_shift($queue) ?? throw new \RuntimeException('queue exhausted');
        }));

        ProbePendingRecovery::create($guest, self::payload(), probeTimeoutMs: 1_500);

        self::assertSame([0, 1_000, 1_500], $this->relativeCreates());
    }

    public function testAGuestMakesOneLastCreateJustPastTheDefaultBudgetWhichTheStrictServerAccepts(): void
    {
        // Headerless refusals until the server's `waitedSeconds > 900` (codex 781cae520a36, Hal5c41E).
        $guest = self::makeGuest($this->recordingHttp(function (): ResponseInterface {
            $waitedSeconds = \intdiv($this->nowMs() - $this->createdAt[0], 1_000);
            return $waitedSeconds > ProbePendingRecovery::PROBE_WAIT_BOUND_MS / 1_000
                ? self::jsonResponse(201, self::guestCreateOk())
                : self::refusal('op');
        }));

        ProbePendingRecovery::create($guest, self::payload());

        $creates = $this->relativeCreates();
        $last = $creates[\count($creates) - 1];
        self::assertGreaterThanOrEqual(ProbePendingRecovery::PROBE_WAIT_BOUND_MS + self::BOUNDARY_MARGIN_MS, $last);
        self::assertLessThanOrEqual(ProbePendingRecovery::PROBE_WAIT_BOUND_MS + self::BOUNDARY_MARGIN_MS + 1_000, $last);
    }

    public function testAGuestDeadlineBeforeTheBoundaryMarginIsATimeoutWithNoBoundaryCreate(): void
    {
        $guest = self::makeGuest($this->recordingHttp(static fn (): ResponseInterface => self::refusal('op')));
        try {
            ProbePendingRecovery::create($guest, self::payload(), deadlineMs: $this->nowMs() + ProbePendingRecovery::PROBE_WAIT_BOUND_MS + 1_000);
            self::fail('expected a timeout');
        } catch (GislTimeoutError) {
            self::assertLessThan(ProbePendingRecovery::PROBE_WAIT_BOUND_MS, \max($this->relativeCreates()));
        }
    }

    public function testAGuestCreate429TooManyRequestsMidRecoveryIsRethrownNotWaitedOut(): void
    {
        $queue = [
            self::refusal('op'),
            self::jsonResponse(429, ['success' => false, 'error' => 'TOO_MANY_REQUESTS', 'message' => 'Too many requests'])
                ->withHeader('Retry-After', '5'),
        ];
        $guest = self::makeGuest($this->recordingHttp(static function () use (&$queue): ResponseInterface {
            return \array_shift($queue) ?? throw new \RuntimeException('queue exhausted');
        }));
        try {
            ProbePendingRecovery::create($guest, self::payload());
            self::fail('expected the 429');
        } catch (GislApiError $e) {
            self::assertNotInstanceOf(GislProbePendingError::class, $e);
            self::assertSame(429, $e->statusCode);
            self::assertSame([0, 1_000], $this->relativeCreates());
        }
    }

    // -----------------------------------------------------------------------
    // f5ti3pik (contracts v2.235.0): a probe_pending refusal on a VIDEO merge
    // names the FEEDER job `src_N` (the passthrough wrapping that upload), not
    // the `merge` job. Through both public merge entry points, over stubbed
    // PSR-18 with the real waitForProbe, recovery polls exactly that upload's
    // probe and re-creates. No SDK change: this pins shipped behaviour. The
    // inputs are small single-shot uploads, so the pre-create probe (multipart
    // video only) sends nothing and the create meets the gate; probeBeforeCreate
    // false would disable the recovery under test (see the opt-out test).
    // -----------------------------------------------------------------------

    public function testFilesMergeRunWaitsOnExactlyTheFeederJobsUploadThenReCreates(): void
    {
        $dir = self::mergeInputsDir();
        $http = $this->mergeFeederHttp();
        $client = self::makeClient($http);

        $client->files([$dir . '/a.mp4', $dir . '/b.mp4', $dir . '/c.mp4'])
            ->merge(new MergeOptions(mediaKind: 'video'))
            ->run(maxWait: '5m', useSSE: false, pollIntervalMs: 100);

        $creates = $this->mergeCreateBodies();
        self::assertCount(2, $creates);
        self::assertSame($creates[0], $creates[1], 'the SAME payload is re-created');
        self::assertSame('upload', self::jobById($creates[0], 'src_1')['source']['type']);
        self::assertSame(self::mergeFid('b.mp4'), self::jobById($creates[0], 'src_1')['source']['file_id']);
        self::assertSame(['/api/uploads/' . self::mergeFid('b.mp4') . '/probe'], $this->probePaths());
    }

    public function testClientMergeWithARepeatedAssetWaitsOnTheSecondUniqueAssetsUpload(): void
    {
        $dir = self::mergeInputsDir();
        $http = $this->mergeFeederHttp();
        $client = self::makeClient($http);
        $a = Merge::asset($dir . '/a.mp4');
        $b = Merge::asset($dir . '/b.mp4');
        $c = Merge::asset($dir . '/c.mp4');

        // Play order a, a, b, c: MergedRecipe would lower one src job per
        // position (src_1 = a); MergeBuilder dedups onto one src per asset
        // (src_1 = b).
        $client->merge([$a, $b, $c], new MergeOptions(mediaKind: 'video'))
            ->sequence([$a, $a, $b, $c])
            ->run(new RunOptions(maxWait: '5m', useSSE: false, pollIntervalMs: 100));

        $creates = $this->mergeCreateBodies();
        self::assertCount(2, $creates);
        self::assertSame($creates[0], $creates[1], 'the SAME payload is re-created');
        $srcJobs = [];
        foreach (self::decodedJobs($creates[0]) as $job) {
            if (\str_starts_with((string) ($job['id'] ?? ''), 'src_')) {
                $srcJobs[] = [$job['id'], $job['source']['file_id']];
            }
        }
        self::assertSame([
            ['src_0', self::mergeFid('a.mp4')],
            ['src_1', self::mergeFid('b.mp4')],
            ['src_2', self::mergeFid('c.mp4')],
        ], $srcJobs);
        self::assertCount(4, self::jobById($creates[0], 'merge')['inputs']);
        self::assertSame(['/api/uploads/' . self::mergeFid('b.mp4') . '/probe'], $this->probePaths());
    }

    /** @var list<RequestInterface> every request the merge-feeder stub saw */
    private array $mergeRequests = [];

    /** A deterministic upload id per input file name. */
    private static function mergeFid(string $name): string
    {
        return match ($name) {
            'a.mp4' => '01936fb1-7bb3-7000-8000-0000000000a0',
            'b.mp4' => '01936fb1-7bb3-7000-8000-0000000000b0',
            'c.mp4' => '01936fb1-7bb3-7000-8000-0000000000c0',
        };
    }

    private static function mergeInputsDir(): string
    {
        $dir = \sys_get_temp_dir() . '/gisl-merge-feeder-' . \bin2hex(\random_bytes(6));
        \mkdir($dir, 0700, true);
        foreach (['a.mp4', 'b.mp4', 'c.mp4'] as $name) {
            \file_put_contents($dir . '/' . $name, 'bytes-' . $name);
        }
        return $dir;
    }

    /**
     * Routes by request: an upload answers with the id for the uploaded file's
     * NAME (read from the multipart body, so upload order cannot matter); the
     * first create is refused naming `src_1`; a probe lands `ok` echoing the
     * polled id; then status and downloads complete the merge.
     */
    private function mergeFeederHttp(): ClientInterface
    {
        $this->mergeRequests = [];
        $creates = 0;
        return $this->recordingHttp(function (RequestInterface $r) use (&$creates): ResponseInterface {
            $this->mergeRequests[] = $r;
            $method = $r->getMethod();
            $path = $r->getUri()->getPath();
            if ($method === 'POST' && $path === '/api/uploads') {
                if (\preg_match('/filename="([^"]+)"/', (string) $r->getBody(), $m) !== 1) {
                    throw new \RuntimeException('upload carried no filename');
                }
                return self::jsonResponse(200, ['success' => true, 'data' => [
                    'file_id' => self::mergeFid($m[1]),
                    'original_name' => $m[1],
                    'mime_type' => 'video/mp4',
                    'size_bytes' => 11,
                ]]);
            }
            if ($method === 'POST' && $path === '/api/workflows') {
                return ++$creates === 1 ? self::refusal('src_1') : self::jsonResponse(201, self::createOk());
            }
            if (\preg_match('#^/api/uploads/([^/]+)/probe$#', $path, $m) === 1) {
                return self::jsonResponse(200, ['success' => true, 'data' => [
                    'file_id' => $m[1],
                    'probe_status' => 'ok',
                    'media_metadata' => ['duration_seconds' => 10, 'codec' => 'h264', 'container' => 'mp4', 'probed_at' => '2026-10-14T10:00:00Z'],
                    'processing_class_pre_assignment' => 'short_form',
                ]]);
            }
            if ($method === 'GET' && \str_ends_with($path, '/downloads')) {
                return self::jsonResponse(200, self::mergeDownloads());
            }
            if ($method === 'GET' && \str_starts_with($path, '/api/workflows/')) {
                return self::jsonResponse(200, self::mergeStatusCompleted());
            }
            throw new \RuntimeException("unrouted request: {$method} {$path}");
        });
    }

    /** @return list<string> the create request bodies, in order */
    private function mergeCreateBodies(): array
    {
        $bodies = [];
        foreach ($this->mergeRequests as $r) {
            if ($r->getMethod() === 'POST' && $r->getUri()->getPath() === '/api/workflows') {
                $bodies[] = (string) $r->getBody();
            }
        }
        return $bodies;
    }

    /** @return list<string> every probe path polled, in order */
    private function probePaths(): array
    {
        $paths = [];
        foreach ($this->mergeRequests as $r) {
            if (\str_ends_with($r->getUri()->getPath(), '/probe')) {
                $paths[] = $r->getUri()->getPath();
            }
        }
        return $paths;
    }

    /** @return list<array<string, mixed>> */
    private static function decodedJobs(string $body): array
    {
        /** @var array{jobs: list<array<string, mixed>>} $decoded */
        $decoded = \json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        return $decoded['jobs'];
    }

    /** @return array<string, mixed> */
    private static function jobById(string $body, string $id): array
    {
        foreach (self::decodedJobs($body) as $job) {
            if (($job['id'] ?? null) === $id) {
                return $job;
            }
        }
        self::fail("no job '{$id}' in the create payload");
    }

    /** @return array<string, mixed> */
    private static function mergeStatusCompleted(): array
    {
        $status = self::statusCompleted();
        $status['data']['jobs'][0]['ref'] = 'merge';
        $status['data']['jobs'][0]['operations'][0]['type'] = 'merge';
        return $status;
    }

    /** @return array<string, mixed> */
    private static function mergeDownloads(): array
    {
        $downloads = self::downloadsOk();
        $downloads['data']['downloads'][0]['ref'] = 'merge';
        $downloads['data']['downloads'][0]['files'][0]['operation'] = 'merge';
        $downloads['data']['downloads'][0]['files'][0]['filename'] = 'merged.mp4';
        return $downloads;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * A GislClient whose createWorkflow and waitForProbe are scripted (the TS
     * `doubles()`), recording every create's clock and every wait's options.
     * Without `$wait` every probe lands `ok` at once.
     *
     * @param \Closure(): WorkflowCreateResponse                                $create
     * @param (\Closure(string, ?ProbeWaitOptions): ProbeWaitResult)|null      $wait
     */
    private function scripted(\Closure $create, ?\Closure $wait = null): GislClient
    {
        $this->createdAt = [];
        $this->waitCalls = [];
        $recordCreate = function () use ($create): WorkflowCreateResponse {
            $this->createdAt[] = $this->nowMs();
            return $create();
        };
        $recordWait = function (string $fileId, ?ProbeWaitOptions $options) use ($wait): ProbeWaitResult {
            $this->waitCalls[] = [$fileId, $options];
            return $wait === null ? self::landed('ok') : $wait($fileId, $options);
        };

        return new class ($recordCreate, $recordWait) extends GislClient {
            public function __construct(private readonly \Closure $create, private readonly \Closure $wait)
            {
                $factory = new HttpFactory();
                parent::__construct(
                    new GislClientConfig(baseUrl: 'https://api.test.example.com', apiKey: 'test-api-key'),
                    new class () implements ClientInterface {
                        public function sendRequest(RequestInterface $request): ResponseInterface
                        {
                            throw new \LogicException('the scripted client sends no HTTP');
                        }
                    },
                    $factory,
                    $factory,
                );
            }

            public function createWorkflow(WorkflowCreatePayload $payload): WorkflowCreateResponse
            {
                return ($this->create)();
            }

            public function waitForProbe(string $fileId, ?ProbeWaitOptions $options = null): ProbeWaitResult
            {
                return ($this->wait)($fileId, $options);
            }
        };
    }

    /**
     * A probe wait that takes its whole `timeoutMs` (plus `$overshootMs`) on the
     * fake clock and does not land.
     *
     * @return \Closure(string, ?ProbeWaitOptions): ProbeWaitResult
     */
    private function neverLands(string $reason = 'timeout', int $overshootMs = 0): \Closure
    {
        return function (string $fileId, ?ProbeWaitOptions $options) use ($reason, $overshootMs): ProbeWaitResult {
            $this->advanceMs(($options?->timeoutMs ?? 0) + $overshootMs);
            return new ProbeWaitResult(landed: false, reason: $reason);
        };
    }

    /** @return \Closure(): ProbeWaitResult a probe wait that gives up at once */
    private static function gaveUp(string $reason): \Closure
    {
        return static fn (): ProbeWaitResult => new ProbeWaitResult(landed: false, reason: $reason);
    }

    /**
     * The API's probe gate (compression_api CreateWorkflowCommandHandler.php:3095-3096):
     * it stops refusing only when `waitedSeconds > 900`, STRICT, in whole seconds
     * from the upload. The upload is taken to be the first create's instant, the
     * worst case (an upload and first refusal in the same second).
     *
     * @return \Closure(): WorkflowCreateResponse
     */
    private function boundServer(): \Closure
    {
        return function (): WorkflowCreateResponse {
            $waitedSeconds = \intdiv($this->nowMs() - $this->createdAt[0], 1_000);
            if ($waitedSeconds > ProbePendingRecovery::PROBE_WAIT_BOUND_MS / 1_000) {
                return self::created();
            }
            throw self::probePending();
        };
    }

    /**
     * A PSR-18 stub answering from `$respond`, recording every request with the
     * fake clock; a `POST /api/workflows` is also recorded as a create.
     *
     * @param \Closure(RequestInterface): ResponseInterface $respond
     */
    private function recordingHttp(\Closure $respond): ClientInterface
    {
        $this->requests = [];
        $this->createdAt = [];
        $record = function (RequestInterface $request) use ($respond): ResponseInterface {
            $line = $request->getMethod() . ' ' . $request->getUri()->getPath();
            $this->requests[] = [$line, $this->nowMs()];
            if ($line === 'POST /api/workflows') {
                $this->createdAt[] = $this->nowMs();
            }
            return $respond($request);
        };
        return new class ($record) implements ClientInterface {
            public function __construct(private readonly \Closure $record)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return ($this->record)($request);
            }
        };
    }

    /**
     * Like recordingHttp() over a queue, but every probe request advances the
     * fake clock by `$probeTakesMs`: a probe answer that arrives late.
     *
     * @param list<ResponseInterface> $queue
     */
    private function slowProbeHttp(array $queue, int $probeTakesMs): ClientInterface
    {
        return $this->recordingHttp(function (RequestInterface $request) use (&$queue, $probeTakesMs): ResponseInterface {
            if (\str_ends_with($request->getUri()->getPath(), '/probe')) {
                $this->advanceMs($probeTakesMs);
            }
            return \array_shift($queue) ?? throw new \RuntimeException('Stub PSR-18 client: response queue exhausted');
        });
    }

    /** @return list<array{0: string, 1: int}> the recorded requests, clock relative to the first */
    private function relativeRequests(): array
    {
        $first = $this->requests[0][1] ?? 0;
        return \array_map(static fn (array $r): array => [$r[0], $r[1] - $first], $this->requests);
    }

    private static function probePending(string $jobRef = 'op', ?string $retryAfter = null): GislProbePendingError
    {
        return new GislProbePendingError(
            'probe pending',
            422,
            'UNPROCESSABLE_ENTITY',
            new ProbePendingResponse(['job_ref' => $jobRef]),
            responseHeaders: $retryAfter === null ? [] : ['retry-after' => $retryAfter],
        );
    }

    /** A plain rate-limit 429 on create: a base GislApiError carrying TOO_MANY_REQUESTS. */
    private static function tooManyRequests(?string $retryAfter = null, string $errorCode = 'TOO_MANY_REQUESTS'): GislApiError
    {
        return new GislApiError(
            'Too many requests',
            429,
            $errorCode,
            responseHeaders: $retryAfter === null ? [] : ['retry-after' => $retryAfter],
        );
    }

    private static function created(): WorkflowCreateResponse
    {
        return new WorkflowCreateResponse(['workflow_id' => 'wf']);
    }

    private static function landed(string $probeStatus): ProbeWaitResult
    {
        return new ProbeWaitResult(landed: true, probe: new UploadProbeResponse(['probe_status' => $probeStatus]));
    }

    private static function payload(): WorkflowCreatePayload
    {
        return new WorkflowCreatePayload(jobs: [
            new JobDefinitionPayload(operations: [], id: 'op', source: Sources::upload(self::FID)),
        ]);
    }

    private static function refusal(string $jobRef = 'op', ?string $retryAfter = null, string $message = 'Upload probe has not completed.'): ResponseInterface
    {
        $response = self::jsonResponse(422, [
            'success' => false,
            'error' => 'UNPROCESSABLE_ENTITY',
            'error_type' => 'probe_pending',
            'message' => $message,
            'job_ref' => $jobRef,
        ]);
        return $retryAfter === null ? $response : $response->withHeader('Retry-After', $retryAfter);
    }

    private static function probeNotApplicable(): ResponseInterface
    {
        return self::jsonResponse(422, [
            'success' => false,
            'error' => 'UNPROCESSABLE_ENTITY',
            'error_type' => 'probe_not_applicable',
            'message' => 'This upload is never probed.',
        ]);
    }

    private static function probe(string $probeStatus): ResponseInterface
    {
        return self::jsonResponse(200, [
            'success' => true,
            'data' => [
                'file_id' => self::FID,
                'probe_status' => $probeStatus,
                'media_metadata' => ['duration_seconds' => 600, 'codec' => 'h264', 'container' => 'mp4', 'probed_at' => '2026-06-16T10:00:00Z'],
                'processing_class_pre_assignment' => 'long_form',
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function uploadOk(): array
    {
        return [
            'success' => true,
            'data' => [
                'file_id' => '01936fb1-7bb3-7000-8000-000000000010',
                'original_name' => 'fixture.bin',
                'mime_type' => 'application/octet-stream',
                'size_bytes' => 11,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function createOk(): array
    {
        return [
            'success' => true,
            'data' => [
                'workflow_id' => '01936fb2-0000-7000-8000-0000000000d1',
                'status' => 'pending',
                'created_at' => '2026-05-27T11:00:00Z',
                'jobs' => [],
                'delivery_plan' => [
                    'mode' => 'individual',
                    'selection_type' => 'terminal',
                    'outputs' => [],
                    'hidden_outputs' => [],
                ],
                'processing_plan' => ['jobs' => []],
                'warnings' => [],
            ],
        ];
    }

    private static function statusCompleted(): array
    {
        return [
            'success' => true,
            'data' => [
                'workflow_id' => '01936fb2-0000-7000-8000-0000000000d1',
                'status' => 'completed',
                'created_at' => '2026-05-27T11:00:00Z',
                'updated_at' => '2026-05-27T11:00:30Z',
                'jobs' => [
                    [
                        'job_id' => '01936fb3-0001-7000-8000-000000000001',
                        'ref' => 'op',
                        'status' => 'completed',
                        'operations' => [
                            [
                                'id' => '01936fb4-0001-7000-8000-000000000001',
                                'type' => 'compress',
                                'status' => 'completed',
                                'progress' => 1.0,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function downloadsOk(): array
    {
        return [
            'success' => true,
            'data' => [
                'downloads' => [
                    [
                        'job_id' => '01936fb3-0001-7000-8000-000000000001',
                        'ref' => 'op',
                        'files' => [
                            [
                                'operation' => 'compress',
                                'operation_id' => '01936fb4-0001-7000-8000-000000000001',
                                'filename' => 'output.webp',
                                'size_bytes' => 48_000,
                                'download_url' => 'https://cdn.example.com/output.webp',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private static function writeTempFile(string $bytes): string
    {
        $dir = \sys_get_temp_dir() . '/gisl-ergo-run-' . \bin2hex(\random_bytes(6));
        \mkdir($dir, 0700, true);
        $path = $dir . '/fixture.bin';
        \file_put_contents($path, $bytes);
        return $path;
    }

    private static function makeClient(ClientInterface $http): GislErgonomicClient
    {
        $factory = new HttpFactory();
        return new GislErgonomicClient(
            config: new GislClientConfig(
                baseUrl: 'https://api.test.example.com', streamBaseUrl: 'https://stream.example.com',
                apiKey: 'test-api-key',
                multipartConcurrency: 1,
            ),
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
        );
    }

    /**
     * @param list<ResponseInterface> $queue
     * @param-out list<RequestInterface> $captured
     */
    private static function stubClient(array $queue, array &$captured = []): ClientInterface
    {
        $captured = [];
        return new class ($queue, $captured) implements ClientInterface {
            /** @var list<ResponseInterface> */
            private array $queue;
            /** @var list<RequestInterface> */
            private array $captured;

            /**
             * @param list<ResponseInterface> $queue
             * @param list<RequestInterface>  $captured
             */
            public function __construct(array $queue, array &$captured)
            {
                $this->queue = $queue;
                $this->captured = &$captured;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;
                $next = \array_shift($this->queue);
                if ($next === null) {
                    throw new \RuntimeException(
                        'Stub PSR-18 client: response queue exhausted on request #'
                        . \count($this->captured) . ' for ' . $request->getMethod() . ' ' . $request->getUri(),
                    );
                }
                return $next;
            }
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function jsonResponse(int $status, array $body): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            \json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private static function makeGuest(ClientInterface $http): GislAnonymousClient
    {
        $factory = new HttpFactory();
        return Gisl::anonymous(
            baseUrl: 'https://api.test.example.com',
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
            streamBaseUrl: 'https://stream.example.com',
        );
    }

    /** @return array<string, mixed> */
    private static function guestCreateOk(): array
    {
        $body = self::createOk();
        $body['data']['anonymous'] = true;
        $body['data']['cap'] = 'cap_token_from_create';
        return $body;
    }
}
