<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Ergonomic;

use Gisl\Sdk\Cancellation;
use Gisl\Sdk\Ergonomic\BuilderInternals;
use Gisl\Sdk\Ergonomic\ProbePendingRecovery;
use Gisl\Sdk\Ergonomic\RunOptions;
use Gisl\Sdk\Errors\GislAbortError;
use Gisl\Sdk\Errors\GislProbePendingError;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use Gisl\Sdk\JobDefinitionPayload;
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
 * Mirrors TS tests/unit/probe-pending.test.ts. Driven through the PUBLIC
 * entry point, run(), so a caller writes no loop.
 */
#[CoversClass(ProbePendingRecovery::class)]
final class ProbePendingRecoveryTest extends TestCase
{
    private const FID = '01936fb1-7bb3-7000-8000-000000000010';

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

    public function testCreatesAreBoundedEvenIfTheServerKeepsRefusing(): void
    {
        $captured = [];
        $client = self::makeClient(self::stubClient([
            self::jsonResponse(200, self::uploadOk()),
            self::refusal(),
            self::probe('ok'),
            self::refusal(),
            self::probe('ok'),
            self::refusal(),
        ], $captured));

        $this->expectException(GislProbePendingError::class);
        try {
            $client->compress(self::writeTempFile('bytes'))->run(new RunOptions(maxWait: '5m', useSSE: false));
        } finally {
            self::assertCount(1 + 2 * ProbePendingRecovery::MAX_CREATE_ATTEMPTS - 1, $captured);
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
        $captured = [];
        $client = self::makeClient(self::stubClient([
            self::refusal('op', '1'),
            self::probe('ok'),
            self::jsonResponse(201, self::createOk()),
        ], $captured));

        $started = \hrtime(true);
        ProbePendingRecovery::create($client, self::payload());
        self::assertGreaterThanOrEqual(950, (\hrtime(true) - $started) / 1_000_000);
        self::assertCount(3, $captured);
    }

    public function testARetryAfterPastTheDeadlineIsATimeoutNow(): void
    {
        $captured = [];
        $client = self::makeClient(self::stubClient([self::refusal('op', '120')], $captured));

        $started = \hrtime(true);
        try {
            ProbePendingRecovery::create($client, self::payload(), deadlineMs: BuilderInternals::nowMs() + 5_000);
            self::fail('expected a timeout');
        } catch (GislTimeoutError) {
            self::assertLessThan(1_000, (\hrtime(true) - $started) / 1_000_000);
            self::assertCount(1, $captured);
        }
    }

    public function testARetryAfterThatDoesNotFitTheBudgetRethrowsWithNoDeadline(): void
    {
        $captured = [];
        $client = self::makeClient(self::stubClient([self::refusal('op', '120')], $captured));

        $started = \hrtime(true);
        try {
            ProbePendingRecovery::create($client, self::payload(), probeTimeoutMs: 1_000);
            self::fail('expected the refusal');
        } catch (GislProbePendingError) {
            self::assertLessThan(1_000, (\hrtime(true) - $started) / 1_000_000);
            self::assertCount(1, $captured);
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

    private static function payload(): WorkflowCreatePayload
    {
        return new WorkflowCreatePayload(jobs: [
            new JobDefinitionPayload(operations: [], id: 'op', source: Sources::upload(self::FID)),
        ]);
    }

    private static function refusal(string $jobRef = 'op', ?string $retryAfter = null): ResponseInterface
    {
        $response = self::jsonResponse(422, [
            'success' => false,
            'error' => 'UNPROCESSABLE_ENTITY',
            'error_type' => 'probe_pending',
            'message' => 'Upload probe has not completed.',
            'job_ref' => $jobRef,
        ]);
        return $retryAfter === null ? $response : $response->withHeader('Retry-After', $retryAfter);
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
}
