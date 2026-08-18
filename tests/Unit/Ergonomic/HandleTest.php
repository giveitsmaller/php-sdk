<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Ergonomic;

use Gisl\Sdk\Ergonomic\Handle;
use Gisl\Sdk\Ergonomic\StatusSnapshot;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislItemFailedError;
use Gisl\Sdk\Errors\GislNoSuchKeyError;
use Gisl\Sdk\Errors\GislResultNotReadyError;
use Gisl\Sdk\FileFirst\RunResult;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * FF5a — `Handle` + `StatusSnapshot` value objects + the `client->workflow(id)`
 * reattach surface, over a stubbed PSR-18 client serving responses in call
 * order (UUID-v7 ids because the generated models validate UUID-v7 on
 * deserialize). Mirrors the TS `handle.test.ts`.
 */
final class HandleTest extends TestCase
{
    private const WORKFLOW_ID = '01936fb2-0000-7000-8000-000000000001';

    private HttpFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    /**
     * @param list<ResponseInterface|\Throwable> $queue
     * @param-out list<RequestInterface>          $captured
     */
    private function stubClient(array $queue, array &$captured = []): ClientInterface
    {
        $captured = [];
        return new class ($queue, $captured) implements ClientInterface {
            /** @var list<ResponseInterface|\Throwable> */
            private array $queue;
            /** @var list<RequestInterface> */
            private array $captured;

            /**
             * @param list<ResponseInterface|\Throwable> $queue
             * @param list<RequestInterface>             $captured
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
                    throw new \RuntimeException('Stub PSR-18 client: response queue exhausted');
                }
                if ($next instanceof \Throwable) {
                    throw $next;
                }
                return $next;
            }
        };
    }

    private function makeClient(ClientInterface $http): GislClient
    {
        return new GislClient(
            config: new GislClientConfig(baseUrl: 'https://api.example.com', streamBaseUrl: 'https://stream.example.com', apiKey: 'sk_test'),
            httpClient: $http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    private function makeErgonomicClient(ClientInterface $http): GislErgonomicClient
    {
        return new GislErgonomicClient(
            config: new GislClientConfig(baseUrl: 'https://api.example.com', streamBaseUrl: 'https://stream.example.com', apiKey: 'sk_test'),
            httpClient: $http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) \json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<array<string, mixed>> $jobs
     */
    private function statusResponse(string $status, array $jobs = []): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['workflow_id' => self::WORKFLOW_ID, 'status' => $status, 'jobs' => $jobs],
        ]);
    }

    private function sseResponse(string $sse): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/event-stream'], $sse);
    }

    private function downloadsResponse(): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => [
                'downloads' => [
                    [
                        'job_id' => '01936fb3-0001-7000-8000-0000000060f3',
                        'ref' => 'op',
                        'files' => [
                            [
                                'operation' => 'compress',
                                'operation_id' => '01936fb4-0001-7000-8000-0000000060f4',
                                'filename' => 'photo_compressed.jpg',
                                'size_bytes' => 512,
                                'download_url' => 'https://signed.example.com/photo_compressed.jpg',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * A two-job fan-out downloads response (refs file-0 / file-1), one output
     * per job, for the uUnCtVAr data-driven per-job partition tests.
     */
    private function downloadsResponseFanout(): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => [
                'downloads' => [
                    [
                        'job_id' => '01936fb3-0001-7000-8000-0000000060f3',
                        'ref' => 'file-0',
                        'files' => [[
                            'operation' => 'compress',
                            'operation_id' => '01936fb4-0001-7000-8000-0000000060f4',
                            'filename' => 'a_compressed.jpg',
                            'size_bytes' => 100,
                            'download_url' => 'https://signed.example.com/a.jpg',
                        ]],
                    ],
                    [
                        'job_id' => '01936fb3-0002-7000-8000-0000000060f5',
                        'ref' => 'file-1',
                        'files' => [[
                            'operation' => 'compress',
                            'operation_id' => '01936fb4-0002-7000-8000-0000000060f6',
                            'filename' => 'b_compressed.jpg',
                            'size_bytes' => 200,
                            'download_url' => 'https://signed.example.com/b.jpg',
                        ]],
                    ],
                ],
            ],
        ]);
    }

    private const TERMINAL_SSE = "event: workflow.completed\ndata: {\"status\":\"completed\"}\n\n";

    // -----------------------------------------------------------------------
    // StatusSnapshot — pure value object
    // -----------------------------------------------------------------------

    #[Test]
    public function status_snapshot_exposes_the_raw_state_verbatim(): void
    {
        $snap = new StatusSnapshot('wf_1', 'in_progress');
        self::assertSame('wf_1', $snap->workflowId);
        self::assertSame('in_progress', $snap->state);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function terminalStateProvider(): iterable
    {
        yield 'completed' => ['completed', true];
        yield 'failed' => ['failed', true];
        yield 'partially_failed' => ['partially_failed', true];
        yield 'cancelled' => ['cancelled', true];
        yield 'expired' => ['expired', true];
        yield 'paused_insufficient_credits' => ['paused_insufficient_credits', true];
        yield 'pending' => ['pending', false];
        yield 'in_progress' => ['in_progress', false];
    }

    #[Test]
    #[DataProvider('terminalStateProvider')]
    public function status_snapshot_is_terminal_table(string $state, bool $expected): void
    {
        self::assertSame($expected, (new StatusSnapshot('wf_1', $state))->isTerminal());
    }

    #[Test]
    public function status_snapshot_to_array_carries_only_workflow_id_and_state_no_phase(): void
    {
        $arr = (new StatusSnapshot('wf_1', 'completed'))->toArray();
        self::assertSame(['workflowId' => 'wf_1', 'state' => 'completed'], $arr);
        self::assertArrayNotHasKey('phase', $arr);
    }

    // -----------------------------------------------------------------------
    // status()
    // -----------------------------------------------------------------------

    #[Test]
    public function status_fetches_once_and_projects_the_raw_state(): void
    {
        $captured = [];
        $http = $this->stubClient([$this->statusResponse('in_progress')], $captured);
        $client = $this->makeClient($http);

        $snap = (new Handle(self::WORKFLOW_ID, null, $client))->status();

        self::assertInstanceOf(StatusSnapshot::class, $snap);
        self::assertSame('in_progress', $snap->state);
        self::assertFalse($snap->isTerminal());
        // status() makes exactly one call — no downloads, no SSE.
        self::assertCount(1, $captured);
        foreach ($captured as $request) {
            self::assertStringNotContainsString('/downloads', (string) $request->getUri());
            self::assertStringNotContainsString('/events', (string) $request->getUri());
        }
    }

    // -----------------------------------------------------------------------
    // wait()
    // -----------------------------------------------------------------------

    #[Test]
    public function wait_awaits_sse_terminal_then_downloads_into_a_keyless_run_result(): void
    {
        $http = $this->stubClient([
            $this->sseResponse(self::TERMINAL_SSE),
            $this->statusResponse('completed'),
            $this->downloadsResponse(),
        ]);
        $client = $this->makeClient($http);

        $result = (new Handle(self::WORKFLOW_ID, null, $client))->wait();

        self::assertInstanceOf(RunResult::class, $result);
        self::assertSame('completed', $result->state);
        self::assertTrue($result->ok);
        self::assertCount(1, $result->artifacts);
        self::assertSame('https://signed.example.com/photo_compressed.jpg', $result->url);
        // Reattached handle carries NO recipe key → keyless RunResult.
        self::assertNull($result->succeeded[0]->key);
        $this->expectException(GislNoSuchKeyError::class);
        $result->byKey('anything');
    }

    #[Test]
    public function wait_falls_back_to_polling_when_sse_ends_without_terminal(): void
    {
        $http = $this->stubClient([
            $this->sseResponse("event: operation.progress\ndata: {\"progress\":50}\n\n"),
            $this->statusResponse('completed'),
            $this->downloadsResponse(),
        ]);
        $client = $this->makeClient($http);

        // wait() has no pollIntervalMs knob; the poll loop fetches status
        // BEFORE any sleep and the queued 'completed' returns on the first poll.
        $result = (new Handle(self::WORKFLOW_ID, null, $client))->wait();

        self::assertSame('completed', $result->state);
        self::assertTrue($result->ok);
        self::assertCount(1, $result->artifacts);
    }

    // -----------------------------------------------------------------------
    // result() — NON-blocking
    // -----------------------------------------------------------------------

    #[Test]
    public function result_fetches_downloads_for_a_terminal_workflow_without_sse(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->statusResponse('completed'),
            $this->downloadsResponse(),
        ], $captured);
        $client = $this->makeClient($http);

        $result = (new Handle(self::WORKFLOW_ID, null, $client))->result();

        self::assertInstanceOf(RunResult::class, $result);
        self::assertSame('completed', $result->state);
        self::assertTrue($result->ok);
        // result() is NON-blocking: status + downloads only, no SSE/events request.
        self::assertCount(2, $captured);
        foreach ($captured as $request) {
            self::assertStringNotContainsString('/events', (string) $request->getUri());
        }
        // Keyless.
        self::assertNull($result->succeeded[0]->key);
    }

    // -----------------------------------------------------------------------
    // uUnCtVAr (FF3a-submit) — data-driven per-job partitioning for a fan-out.
    // The producer is chosen off the wire (file-{i} job refs), NOT a marker, so
    // a REATTACHED keyless handle still partitions per job (karen Gap A).
    // -----------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private static function fanoutJobs(string $job1Status = 'completed'): array
    {
        return [
            ['job_id' => '01936fb3-0001-7000-8000-0000000060f3', 'ref' => 'file-0', 'status' => 'completed'],
            ['job_id' => '01936fb3-0002-7000-8000-0000000060f5', 'ref' => 'file-1', 'status' => $job1Status],
        ];
    }

    #[Test]
    public function result_partitions_a_fanout_per_job_with_index_keys_even_keyless(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->statusResponse('completed', self::fanoutJobs()),
            $this->downloadsResponseFanout(),
        ], $captured);
        $client = $this->makeClient($http);

        // No key (reattach) — keys MUST still come from the file-{i} refs.
        $result = (new Handle(self::WORKFLOW_ID, null, $client))->result();

        self::assertTrue($result->ok);
        self::assertCount(2, $result->succeeded);
        self::assertSame([], $result->failed);
        self::assertSame(['0', '1'], \array_map(static fn ($s) => $s->key, $result->succeeded));
        self::assertSame('https://signed.example.com/a.jpg', $result->byKey('0')->outputs[0]->url);
        self::assertSame('https://signed.example.com/b.jpg', $result->byKey('1')->outputs[0]->url);
    }

    #[Test]
    public function wait_partitions_a_fanout_per_job_even_keyless(): void
    {
        // The SSE wait() path feeds project() a $finalStatus fetched AFTER the
        // terminal SSE frame (getWorkflowStatus, which carries jobs) — verify
        // the fan-out branch is reached via wait(), not only result().
        $captured = [];
        $http = $this->stubClient([
            $this->sseResponse(self::TERMINAL_SSE),
            $this->statusResponse('completed', self::fanoutJobs()),
            $this->downloadsResponseFanout(),
        ], $captured);
        $client = $this->makeClient($http);

        $result = (new Handle(self::WORKFLOW_ID, null, $client))->wait();

        self::assertTrue($result->ok);
        self::assertSame(['0', '1'], \array_map(static fn ($s) => $s->key, $result->succeeded));
    }

    #[Test]
    public function single_input_fanout_still_partitions_with_index_key(): void
    {
        // Boundary: ONE job whose ref is `file-0` IS a fan-out (files([x])) —
        // it must partition with key "0", NOT collapse to the single-output
        // path. Guards against a `count === 1 -> single-output` regression.
        $captured = [];
        $http = $this->stubClient([
            $this->statusResponse('completed', [
                ['job_id' => '01936fb3-0001-7000-8000-0000000060f3', 'ref' => 'file-0', 'status' => 'completed'],
            ]),
            $this->jsonResponse(200, [
                'success' => true,
                'data' => ['downloads' => [[
                    'job_id' => '01936fb3-0001-7000-8000-0000000060f3',
                    'ref' => 'file-0',
                    'files' => [[
                        'operation' => 'compress',
                        'operation_id' => '01936fb4-0001-7000-8000-0000000060f4',
                        'filename' => 'a_compressed.jpg',
                        'size_bytes' => 100,
                        'download_url' => 'https://signed.example.com/a.jpg',
                    ]],
                ]]],
            ]),
        ], $captured);
        $client = $this->makeClient($http);

        $result = (new Handle(self::WORKFLOW_ID, null, $client))->result();

        self::assertCount(1, $result->succeeded);
        self::assertSame('0', $result->succeeded[0]->key);
        self::assertSame('https://signed.example.com/a.jpg', $result->byKey('0')->outputs[0]->url);
    }

    #[Test]
    public function result_partitions_a_partially_failed_fanout_per_job(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->statusResponse('partially_failed', self::fanoutJobs('failed')),
            $this->downloadsResponseFanout(),
        ], $captured);
        $client = $this->makeClient($http);

        $result = (new Handle(self::WORKFLOW_ID, null, $client))->result();

        self::assertFalse($result->ok);
        self::assertSame(['0'], \array_map(static fn ($s) => $s->key, $result->succeeded));
        self::assertSame(['1'], \array_map(static fn ($f) => $f->key, $result->failed));
    }

    #[Test]
    public function single_file_handle_stays_single_output_and_keeps_its_key(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->statusResponse('completed', [['job_id' => '01936fb3-0001-7000-8000-0000000060f3', 'ref' => 'op', 'status' => 'completed']]),
            $this->downloadsResponse(),
        ], $captured);
        $client = $this->makeClient($http);

        // ref 'op' is NOT a fan-out → single-output producer, keyed by #key.
        $result = (new Handle(self::WORKFLOW_ID, null, $client, 'hero'))->result();

        self::assertCount(1, $result->succeeded);
        self::assertSame('hero', $result->succeeded[0]->key);
        self::assertSame('https://signed.example.com/photo_compressed.jpg', $result->byKey('hero')->outputs[0]->url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonTerminalStateProvider(): iterable
    {
        yield 'pending' => ['pending'];
        yield 'in_progress' => ['in_progress'];
    }

    #[Test]
    #[DataProvider('nonTerminalStateProvider')]
    public function result_throws_not_ready_for_a_non_terminal_workflow_without_downloading(string $state): void
    {
        $captured = [];
        $http = $this->stubClient([$this->statusResponse($state)], $captured);
        $client = $this->makeClient($http);

        try {
            (new Handle(self::WORKFLOW_ID, null, $client))->result();
            self::fail('expected GislResultNotReadyError');
        } catch (GislResultNotReadyError $e) {
            self::assertSame(self::WORKFLOW_ID, $e->getWorkflowId());
            self::assertSame($state, $e->getState());
        }

        // Exactly one request (the status fetch) — no downloads, no events.
        self::assertCount(1, $captured);
        foreach ($captured as $request) {
            self::assertStringNotContainsString('/downloads', (string) $request->getUri());
            self::assertStringNotContainsString('/events', (string) $request->getUri());
        }
    }

    // -----------------------------------------------------------------------
    // no_client guard
    // -----------------------------------------------------------------------

    #[Test]
    public function status_throws_config_error_no_client_on_a_clientless_handle(): void
    {
        $this->assertNoClientGuard(static fn (Handle $h): mixed => $h->status());
    }

    #[Test]
    public function wait_throws_config_error_no_client_on_a_clientless_handle(): void
    {
        $this->assertNoClientGuard(static fn (Handle $h): mixed => $h->wait());
    }

    #[Test]
    public function result_throws_config_error_no_client_on_a_clientless_handle(): void
    {
        $this->assertNoClientGuard(static fn (Handle $h): mixed => $h->result());
    }

    /**
     * @param callable(Handle): mixed $call
     */
    private function assertNoClientGuard(callable $call): void
    {
        $handle = new Handle('wf_1', 'wh_secret');
        try {
            $call($handle);
            self::fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertSame('no_client', $e->getReason());
        }
    }

    // -----------------------------------------------------------------------
    // toArray — back-compat shape
    // -----------------------------------------------------------------------

    #[Test]
    public function to_array_omits_webhook_secret_when_absent_and_never_leaks_the_client(): void
    {
        $http = $this->stubClient([]);
        $handle = new Handle('wf_1', null, $this->makeClient($http));
        self::assertSame(['workflowId' => 'wf_1'], $handle->toArray());
        self::assertArrayNotHasKey('client', $handle->toArray());
    }

    #[Test]
    public function to_array_includes_webhook_secret_when_present_in_workflow_id_then_secret_order(): void
    {
        $handle = new Handle('wf_1', 'wh_secret');
        self::assertSame(['workflowId' => 'wf_1', 'webhookSecret' => 'wh_secret'], $handle->toArray());
        self::assertSame(['workflowId', 'webhookSecret'], \array_keys($handle->toArray()));
    }

    #[Test]
    public function to_array_is_byte_identical_regardless_of_the_bound_client(): void
    {
        $http = $this->stubClient([]);
        $bare = new Handle('wf_1', 'wh_secret');
        $bound = new Handle('wf_1', 'wh_secret', $this->makeClient($http));
        self::assertSame($bare->toArray(), $bound->toArray());
    }

    // -----------------------------------------------------------------------
    // client->workflow(id) reattach
    // -----------------------------------------------------------------------

    #[Test]
    public function workflow_reattach_returns_a_client_bound_handle_with_no_webhook_secret(): void
    {
        $http = $this->stubClient([]);
        $client = $this->makeErgonomicClient($http);

        $handle = $client->workflow('01936fb2-0000-7000-8000-0000000000aa');

        self::assertInstanceOf(Handle::class, $handle);
        self::assertSame('01936fb2-0000-7000-8000-0000000000aa', $handle->workflowId);
        self::assertNull($handle->webhookSecret);
        self::assertSame(['workflowId' => '01936fb2-0000-7000-8000-0000000000aa'], $handle->toArray());
    }

    #[Test]
    public function workflow_reattach_result_drives_a_terminal_status_into_a_keyless_run_result(): void
    {
        $http = $this->stubClient([
            $this->statusResponse('completed'),
            $this->downloadsResponse(),
        ]);
        $client = $this->makeErgonomicClient($http);

        $result = $client->workflow(self::WORKFLOW_ID)->result();

        self::assertSame('completed', $result->state);
        self::assertTrue($result->ok);
        self::assertNull($result->succeeded[0]->key);
        $this->expectException(GislNoSuchKeyError::class);
        $result->byKey('x');
    }

    #[Test]
    public function workflow_reattach_wait_drives_sse_terminal_into_a_keyless_run_result(): void
    {
        $http = $this->stubClient([
            $this->sseResponse(self::TERMINAL_SSE),
            $this->statusResponse('completed'),
            $this->downloadsResponse(),
        ]);
        $client = $this->makeErgonomicClient($http);

        $result = $client->workflow(self::WORKFLOW_ID)->wait();

        self::assertSame('completed', $result->state);
        self::assertNull($result->succeeded[0]->key);
    }

    // -----------------------------------------------------------------------
    // RunResult::fromTerminalDownloads — partition invariant
    // -----------------------------------------------------------------------

    #[Test]
    public function from_terminal_downloads_partitions_completed_into_succeeded_only(): void
    {
        $http = $this->stubClient([
            $this->statusResponse('completed'),
            $this->downloadsResponse(),
        ]);
        $client = $this->makeClient($http);

        $result = (new Handle(self::WORKFLOW_ID, null, $client))->result();

        self::assertTrue($result->ok);
        self::assertSame([], $result->failed);
        self::assertCount(1, $result->succeeded);
        self::assertNull($result->succeeded[0]->key);
        self::assertCount(1, $result->artifacts);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonCompletedTerminalProvider(): iterable
    {
        yield 'failed' => ['failed'];
        yield 'partially_failed' => ['partially_failed'];
        yield 'cancelled' => ['cancelled'];
        yield 'expired' => ['expired'];
        yield 'paused_insufficient_credits' => ['paused_insufficient_credits'];
    }

    #[Test]
    #[DataProvider('nonCompletedTerminalProvider')]
    public function from_terminal_downloads_partitions_non_completed_terminal_into_failed_only(string $state): void
    {
        // A non-completed terminal status partitions into failed with ok=false,
        // even though the downloads response carries files — success is ONLY
        // state==='completed'.
        $http = $this->stubClient([
            $this->statusResponse($state, [
                ['operations' => [['error_message' => 'boom']]],
            ]),
            $this->downloadsResponse(),
        ]);
        $client = $this->makeClient($http);

        $result = (new Handle(self::WORKFLOW_ID, null, $client))->result();

        self::assertSame($state, $result->state);
        self::assertFalse($result->ok);
        self::assertSame([], $result->succeeded);
        self::assertCount(1, $result->failed);
        self::assertNull($result->failed[0]->key);
        self::assertInstanceOf(GislItemFailedError::class, $result->failed[0]->error);
        // The server-provided reason must survive + carry the state prefix — a
        // regression dropping or mis-formatting it would otherwise pass.
        self::assertSame($state . ': boom', $result->failed[0]->error->getMessage());
        // The reattached Handle funnels through fromTerminalDownloads, so the
        // typed error carries the terminal state + the op's message.
        self::assertSame($state, $result->failed[0]->error->state);
        self::assertSame('boom', $result->failed[0]->error->errorMessage);
        // Downloads still flatten into artifacts even though the run failed;
        // the partition is independent of artifact presence.
        self::assertNotEmpty($result->artifacts);
    }

    #[Test]
    public function reattached_handle_failure_carries_error_code_from_the_op(): void
    {
        // The reattach surface produces the SAME typed error as a direct run():
        // a failing op with BOTH error_code + error_message surfaces both, and
        // toArray() emits the full key set in fixed order.
        $http = $this->stubClient([
            $this->statusResponse('failed', [
                ['operations' => [['error_message' => 'too large', 'error_code' => 'output_too_large']]],
            ]),
            $this->downloadsResponse(),
        ]);
        $client = $this->makeClient($http);

        $result = (new Handle(self::WORKFLOW_ID, null, $client))->result();

        self::assertInstanceOf(GislItemFailedError::class, $result->failed[0]->error);
        self::assertSame('failed', $result->failed[0]->error->state);
        self::assertSame('too large', $result->failed[0]->error->errorMessage);
        self::assertSame('output_too_large', $result->failed[0]->error->errorCode);
        self::assertSame(
            ['key' => null, 'error' => 'failed: too large', 'state' => 'failed', 'errorMessage' => 'too large', 'errorCode' => 'output_too_large'],
            $result->toArray()['failed'][0],
        );
    }

    #[Test]
    public function reattached_handle_cancelled_terminal_carries_only_bare_state_and_omits_optional_keys(): void
    {
        // cancelled is a terminal with NO failing op — the typed error carries
        // only the bare state; errorMessage/errorCode are null and toArray()
        // OMITS both keys (never `=> null`). `error` === the bare state string.
        $http = $this->stubClient([
            $this->statusResponse('cancelled', [['operations' => []]]),
            $this->downloadsResponse(),
        ]);
        $client = $this->makeClient($http);

        $result = (new Handle(self::WORKFLOW_ID, null, $client))->result();

        self::assertInstanceOf(GislItemFailedError::class, $result->failed[0]->error);
        self::assertSame('cancelled', $result->failed[0]->error->state);
        self::assertNull($result->failed[0]->error->errorMessage);
        self::assertNull($result->failed[0]->error->errorCode);
        self::assertSame('cancelled', $result->failed[0]->error->getMessage());

        $failedEntry = $result->toArray()['failed'][0];
        self::assertSame(['key' => null, 'error' => 'cancelled', 'state' => 'cancelled'], $failedEntry);
        self::assertArrayNotHasKey('errorMessage', $failedEntry);
        self::assertArrayNotHasKey('errorCode', $failedEntry);
    }

    #[Test]
    public function from_terminal_downloads_failure_falls_back_to_bare_state_without_error_message(): void
    {
        // No op carries an error_message → the failure message is the bare state.
        $http = $this->stubClient([
            $this->statusResponse('failed', [['operations' => [[]]]]),
            $this->downloadsResponse(),
        ]);
        $client = $this->makeClient($http);

        $result = (new Handle(self::WORKFLOW_ID, null, $client))->result();

        self::assertSame('failed', $result->failed[0]->error->getMessage());
    }
}
