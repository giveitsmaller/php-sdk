<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Ergonomic;

use Gisl\Sdk\Ergonomic\Artifact;
use Gisl\Sdk\Ergonomic\BuilderInternals;
use Gisl\Sdk\Ergonomic\OperationBuilder;
use Gisl\Sdk\Ergonomic\Result;
use Gisl\Sdk\Ergonomic\RunOptions;
use Gisl\Sdk\Errors\GislApiError;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(OperationBuilder::class)]
final class OperationBuilderRunTest extends TestCase
{
    /**
     * 36AZ98FV — `RunOptions::$maxWait` was mandatory on the stated grounds that the
     * poll path's 600_000 ms default "would otherwise leak silently". The same tree
     * applied exactly that default at FOURTEEN sites, and PHP had already NAMED the
     * number in WorkflowConstants and hard-coded the literal beside it seven times.
     * The prohibition was refuted by the code it protected.
     */
    public function test_run_requires_no_options_at_all(): void
    {
        $tempPath = self::writeTempFile('payload');

        $captured = [];
        // ⚠️ FIVE responses, not four. Every other run test here passes
        // `useSSE: false`; this one passes NO OPTIONS AT ALL, which is the point —
        // so `useSSE` takes its default of true and the SSE attempt consumes a
        // queue slot before the poll fallback. A four-response queue exhausts on
        // the status poll, which is a test-harness artefact and not a defect.
        $http = self::stubClient([
            self::jsonResponse(200, self::uploadOk()),
            self::jsonResponse(201, self::createOk()),
            // An empty event-stream: the SSE attempt connects, yields nothing and
            // ends, so the run falls through to the poll path. A 404 here does NOT
            // fall back — it raises GislApiError.
            new Response(200, ['Content-Type' => 'text/event-stream'], ''),
            self::jsonResponse(200, self::statusCompleted()),
            self::jsonResponse(200, self::downloadsOk()),
        ], $captured);

        $client = self::makeClient($http);
        // No RunOptions — this was a TypeError before 36AZ98FV, while the
        // file-first spelling of the same task already accepted none.
        $result = $client->compress($tempPath, ['quality' => 75])->run();

        $this->assertNotSame('', $result->workflowId);
    }

    /**
     * 3OVNoRxh — A REFUSED SSE CONNECT IS NOT A FAILED RUN.
     *
     * The contract declares the `events_stream` 429 retryable, and it clears as
     * soon as another caller closes a stream. Before this, the refusal
     * propagated: a `run()` caller got a hard failure for a transport they
     * never asked about, while polling — a working transport, and what they
     * actually asked for — sat unused.
     *
     * 🔴 THE THING THAT MAKES THIS TEST WORTH MORE THAN ITS TWIN IN TS:
     * `streamEvents()` is a GENERATOR here, so its body — the HTTP call
     * included — does not run when it is CALLED. A guard wrapped around the
     * call site would never fire in production and would still pass a test
     * whose double throws eagerly. This test drives a real PSR-18 stub through
     * the real generator, so it fails if the priming `rewind()` is removed.
     */
    public function test_a_429_on_the_sse_connect_falls_back_to_polling(): void
    {
        $tempPath = self::writeTempFile('input bytes');

        $captured = [];
        $http = self::stubClient([
            self::jsonResponse(200, self::uploadOk()),
            self::jsonResponse(201, self::createOk()),
            // The SSE connect is REFUSED. Retryable, so the run polls instead.
            self::jsonResponse(429, [
                'success' => false,
                'error' => 'RATE_LIMITED',
                'message' => 'Too many concurrent event streams',
            ]),
            self::jsonResponse(200, self::statusCompleted()),
            self::jsonResponse(200, self::downloadsOk()),
        ], $captured);

        $client = self::makeClient($http);
        $result = $client
            ->compress($tempPath, ['quality' => 75])
            ->run(new RunOptions(maxWait: '30s', pollIntervalMs: 1_000));

        $this->assertSame('completed', $result->status);
        // The poll actually happened — the refusal did not just get swallowed
        // into a result built from nothing.
        $this->assertCount(5, $captured);
        $this->assertStringContainsString('/events', (string) $captured[2]->getUri());
        $this->assertStringContainsString('/status', (string) $captured[3]->getUri());
        $this->assertStringContainsString('/downloads', (string) $captured[4]->getUri());
    }

    /**
     * 3OVNoRxh — THE TWIN, AND THE NARROWING IS THE PROPERTY.
     *
     * Without this, the suite would pass on a change that polled after ANY API
     * error on the connect — which would report a successful run on a workflow
     * the caller has no right to read. A 401 is not retryable and must reach
     * the caller untouched.
     */
    public function test_a_401_on_the_sse_connect_propagates_and_does_not_poll(): void
    {
        $tempPath = self::writeTempFile('input bytes');

        $captured = [];
        $http = self::stubClient([
            self::jsonResponse(200, self::uploadOk()),
            self::jsonResponse(201, self::createOk()),
            self::jsonResponse(401, [
                'success' => false,
                'error' => 'UNAUTHORIZED',
                'message' => 'Not signed in',
            ]),
            // Deliberately queued: if the SDK polls anyway, the run SUCCEEDS
            // and this test fails loudly instead of erroring on an empty queue.
            self::jsonResponse(200, self::statusCompleted()),
            self::jsonResponse(200, self::downloadsOk()),
        ], $captured);

        $client = self::makeClient($http);

        $this->expectException(GislApiError::class);
        try {
            $client
                ->compress($tempPath, ['quality' => 75])
                ->run(new RunOptions(maxWait: '30s', pollIntervalMs: 1_000));
        } finally {
            // Three requests, not five: upload, create, the refused connect.
            $this->assertCount(3, $captured);
        }
    }

    public function test_run_happy_path_with_poll_fallback(): void
    {
        $tempPath = self::writeTempFile('input bytes');

        // Sequence: upload -> createWorkflow -> getWorkflowStatus
        // (terminal completed on first poll) -> getWorkflowDownloads.
        $captured = [];
        $http = self::stubClient([
            self::jsonResponse(200, self::uploadOk()),
            self::jsonResponse(201, self::createOk()),
            self::jsonResponse(200, self::statusCompleted()),
            self::jsonResponse(200, self::downloadsOk()),
        ], $captured);

        $client = self::makeClient($http);
        $result = $client
            ->compress($tempPath, ['quality' => 75])
            ->run(new RunOptions(maxWait: '5m', useSSE: false, pollIntervalMs: 100));

        $this->assertInstanceOf(Result::class, $result);
        $this->assertSame('01936fb2-0000-7000-8000-0000000000d1', $result->workflowId);
        $this->assertSame('completed', $result->status);
        $this->assertCount(1, $result->artifacts);
        $art = $result->artifacts[0];
        $this->assertInstanceOf(Artifact::class, $art);
        $this->assertSame('https://cdn.example.com/output.webp', $art->url);
        $this->assertSame('output.webp', $art->filename);
        $this->assertSame(48_000, $art->sizeBytes);
        $this->assertSame('compress', $art->operation);
        // url sugar fires when artifacts.length === 1.
        $this->assertSame('https://cdn.example.com/output.webp', $result->url);

        // resolvedOptions echoes the call-time options under `applied`.
        $this->assertSame(['quality' => 75], $result->resolvedOptions->applied);
        $this->assertNull($result->resolvedOptions->preset);

        // 4 outbound requests: upload + create + status + downloads.
        $this->assertCount(4, $captured);
        $this->assertStringContainsString('/api/uploads', (string) $captured[0]->getUri());
        $this->assertStringContainsString('/api/workflows', (string) $captured[1]->getUri());
        $this->assertStringContainsString('/status', (string) $captured[2]->getUri());
        $this->assertStringContainsString('/downloads', (string) $captured[3]->getUri());
    }

    /**
     * r7bpd7MY — the VALUE, exactly. Companion to the request-count test below;
     * neither is redundant. A count over a real deadline cannot tell 1000 ms
     * from 750 (codex d218bd6a0c62); this pins the number, that one proves the
     * clamp sits on the path `run()` travels.
     */
    public function test_poll_interval_clamps_to_exactly_one_second(): void
    {
        $this->assertSame(1_000, BuilderInternals::clampPollIntervalMs(1));
        $this->assertSame(1_000, BuilderInternals::clampPollIntervalMs(999));
        $this->assertSame(1_000, BuilderInternals::clampPollIntervalMs(0));
        $this->assertSame(1_000, BuilderInternals::clampPollIntervalMs(-5));

        // At and above the floor, untouched — a clamp that rewrote legal values
        // would be a different bug.
        $this->assertSame(1_000, BuilderInternals::clampPollIntervalMs(1_000));
        $this->assertSame(5_000, BuilderInternals::clampPollIntervalMs(5_000));

        // The default is not the floor and this card does not move it.
        $this->assertSame(2_000, BuilderInternals::clampPollIntervalMs(null));
    }

    /**
     * r7bpd7MY — THE POLL FLOOR IS 1000 ms AND THE NUMBER IS WHAT IS ASSERTED.
     *
     * api's `status_poll` family is a SLIDING 60 requests/minute at Free and Basic
     * (`TieredRateLimiterService.php:37-39`, scaled per tier by
     * `UserTier::rateLimitMultiplier()`). The previous 100 ms floor is 600/minute —
     * ten times that ceiling, and twice Pro's.
     *
     * Counting outbound requests over a real 2.5 s deadline is the cheapest
     * observation that can tell 1000 from 100: at the correct floor this makes
     * about three status calls, at the old floor about twenty-five. The assertion
     * is a BOUND chosen to sit far below the old behaviour and above scheduler
     * jitter — an exact count would be a flaky test pretending to be a precise one.
     *
     * Deliberate mirror of the TypeScript test of the same name; the two languages
     * move together on this value.
     */
    public function test_poll_floor_is_one_second_measured_by_request_count(): void
    {
        $tempPath = self::writeTempFile('input bytes');

        // upload + create, then a long run of NEVER-terminal statuses. Forty is
        // more than even the OLD floor would consume in 2.5 s, so the test fails
        // on the ASSERTION rather than on an exhausted queue — a queue that runs
        // dry would fail for a reason that has nothing to do with the floor.
        $queue = [
            self::jsonResponse(200, self::uploadOk()),
            self::jsonResponse(201, self::createOk()),
        ];
        for ($i = 0; $i < 40; $i++) {
            $queue[] = self::jsonResponse(200, self::statusRunning());
        }

        $captured = [];
        $http = self::stubClient($queue, $captured);
        $client = self::makeClient($http);

        $this->expectException(GislTimeoutError::class);

        try {
            $client
                ->compress($tempPath, ['quality' => 75])
                ->run(new RunOptions(maxWait: 2500, useSSE: false, pollIntervalMs: 1));
        } finally {
            // upload + create + the status polls.
            // ⚠️ SIX, NOT EIGHT (codex f127c6af7335): at eight this passed with a
            // 500 ms floor, so it could not tell a materially unsafe regression
            // from the correct value. upload + create + ~3 polls = 5 at 1000ms;
            // 500ms produces 7 and fails.
            $this->assertLessThanOrEqual(
                6,
                \count($captured),
                'poll floor regressed: ' . \count($captured) . ' requests in 2.5s. '
                . 'At a 1000ms floor this is ~5; at 500ms ~7; at the old 100ms floor '
                . '~27, which is 600 req/min against a 60 req/min per-tier limit.',
            );
        }
    }

    public function test_run_deadline_after_upload_throws_timeout(): void
    {
        $tempPath = self::writeTempFile('input bytes');

        // Slow-upload stub: sleeps 60ms inside the upload response so the
        // wall-clock deadline (50ms) has demonstrably lapsed by the time
        // the post-upload check fires. If the SDK reaches createWorkflow
        // anyway, the stub queue is exhausted -> RuntimeException (the
        // negative-control branch the test asserts against).
        $slowHttp = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                if (!\str_contains((string) $request->getUri(), '/api/uploads')) {
                    throw new \RuntimeException(
                        'deadline-after-upload test should never reach ' . $request->getUri()
                        . ' — the deadline check at builder.php must fire first.',
                    );
                }
                \usleep(60 * 1_000);
                return OperationBuilderRunTest::jsonResponseStatic(200, OperationBuilderRunTest::uploadOkStatic());
            }
        };

        $client = self::makeClient($slowHttp);
        $builder = $client->compress($tempPath, ['quality' => 75]);

        $this->expectException(GislTimeoutError::class);
        $this->expectExceptionMessage('Upload completed but maxWait elapsed before workflow could be created.');
        $builder->run(new RunOptions(maxWait: 50, useSSE: false));
    }

    public function test_run_deadline_after_terminal_before_downloads_throws_timeout(): void
    {
        // The deadline-after-terminal check fires when the poll returns
        // terminal but the wall clock has already passed the deadline.
        // We sleep briefly between upload and the terminal poll so the
        // 50ms deadline lapses BEFORE the downloads request would fire.
        $slowHttp = new class implements ClientInterface {
            /** @var list<ResponseInterface> */
            public array $queue;
            /** @var list<RequestInterface> */
            public array $captured = [];

            public function __construct()
            {
                $this->queue = [
                    OperationBuilderRunTest::jsonResponseStatic(200, OperationBuilderRunTest::uploadOkStatic()),
                    OperationBuilderRunTest::jsonResponseStatic(201, OperationBuilderRunTest::createOkStatic()),
                    OperationBuilderRunTest::jsonResponseStatic(200, OperationBuilderRunTest::statusCompletedStatic()),
                ];
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;
                $next = \array_shift($this->queue);
                if ($next === null) {
                    throw new \RuntimeException('Stub queue exhausted (downloads must NOT fire after deadline).');
                }
                // Sleep just before returning the terminal status so the
                // deadline-after-terminal check trips at >= deadline.
                if (\str_contains((string) $request->getUri(), '/status')) {
                    \usleep(80 * 1_000); // 80ms > 50ms deadline
                }
                return $next;
            }
        };

        $client = self::makeClient($slowHttp);
        $builder = $client->compress(self::writeTempFile('x'), ['quality' => 80]);

        $this->expectException(GislTimeoutError::class);
        $this->expectExceptionMessage('reached terminal status but maxWait elapsed before downloads could be fetched');
        $builder->run(new RunOptions(maxWait: 50, useSSE: false, pollIntervalMs: 10));
    }

    public function test_run_slow_downloads_after_deadline_throws_timeout(): void
    {
        // TDqmkWpX: the deadline-AFTER-downloads re-check fires when the
        // downloads request itself runs long. Terminal arrives within the 50ms
        // deadline; the downloads response sleeps PAST it, so the post-fetch
        // re-check must time out rather than returning a late success.
        $slowHttp = new class implements ClientInterface {
            /** @var list<ResponseInterface> */
            public array $queue;
            /** @var list<RequestInterface> */
            public array $captured = [];

            public function __construct()
            {
                $this->queue = [
                    OperationBuilderRunTest::jsonResponseStatic(200, OperationBuilderRunTest::uploadOkStatic()),
                    OperationBuilderRunTest::jsonResponseStatic(201, OperationBuilderRunTest::createOkStatic()),
                    OperationBuilderRunTest::jsonResponseStatic(200, OperationBuilderRunTest::statusCompletedStatic()),
                    OperationBuilderRunTest::jsonResponseStatic(200, OperationBuilderRunTest::downloadsEmptyStatic()),
                ];
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;
                $next = \array_shift($this->queue);
                if ($next === null) {
                    throw new \RuntimeException('Stub queue exhausted.');
                }
                // Sleep on the downloads request itself so the deadline lapses
                // DURING the fetch — the re-check AFTER the call must trip.
                if (\str_contains((string) $request->getUri(), '/downloads')) {
                    \usleep(80 * 1_000); // 80ms > 50ms deadline
                }
                return $next;
            }
        };

        $client = self::makeClient($slowHttp);
        $builder = $client->compress(self::writeTempFile('x'), ['quality' => 80]);

        $this->expectException(GislTimeoutError::class);
        $this->expectExceptionMessage('downloads fetch completed after maxWait elapsed');
        $builder->run(new RunOptions(maxWait: 50, useSSE: false, pollIntervalMs: 10));
    }

    public function test_run_invalid_maxwait_string_throws_invalid_argument(): void
    {
        $tempPath = self::writeTempFile('x');
        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));
        $builder = $client->compress($tempPath);

        $this->expectException(\InvalidArgumentException::class);
        $builder->run(new RunOptions(maxWait: 'five minutes', useSSE: false));
    }

    public function test_run_returns_null_url_when_artifacts_not_single(): void
    {
        $tempPath = self::writeTempFile('x');
        $captured = [];
        $http = self::stubClient([
            self::jsonResponse(200, self::uploadOk()),
            self::jsonResponse(201, self::createOk()),
            self::jsonResponse(200, self::statusCompleted()),
            self::jsonResponse(200, self::downloadsEmpty()),
        ], $captured);

        $client = self::makeClient($http);
        $result = $client->compress($tempPath)->run(new RunOptions(maxWait: '5m', useSSE: false));

        $this->assertSame([], $result->artifacts);
        $this->assertNull($result->url, 'url sugar must be null when artifact count != 1');
    }

    // -----------------------------------------------------------------
    // Helpers (public-static where used from anonymous-class fixtures).
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public static function uploadOkStatic(): array
    {
        return self::uploadOk();
    }

    /**
     * @return array<string, mixed>
     */
    public static function createOkStatic(): array
    {
        return self::createOk();
    }

    /**
     * @return array<string, mixed>
     */
    public static function statusCompletedStatic(): array
    {
        return self::statusCompleted();
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function jsonResponseStatic(int $status, array $body): ResponseInterface
    {
        return self::jsonResponse($status, $body);
    }

    /**
     * @return array<string, mixed>
     */
    public static function downloadsEmptyStatic(): array
    {
        return self::downloadsEmpty();
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

    /**
     * @return array<string, mixed>
     */
    /**
     * A non-terminal status, so the poll loop keeps going until the deadline.
     *
     * ⚠️ `in_progress`, NOT `running`. The generated WorkflowStatus enum admits
     * pending / in_progress / completed / failed / partially_failed /
     * paused_insufficient_credits / cancelled / expired — a `running` body makes
     * the deserializer throw InvalidArgumentException, which surfaces as a test
     * failure that looks nothing like the thing under test. (TypeScript's mock
     * is untyped and accepts `running` happily; the two languages disagree about
     * how much a stub is allowed to lie.)
     */
    private static function statusRunning(): array
    {
        $body = self::statusCompleted();
        $body['data']['status'] = 'in_progress';
        $body['data']['jobs'][0]['status'] = 'in_progress';
        $body['data']['jobs'][0]['operations'][0]['status'] = 'in_progress';
        $body['data']['jobs'][0]['operations'][0]['progress'] = 0.1;

        return $body;
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

    /**
     * @return array<string, mixed>
     */
    private static function downloadsEmpty(): array
    {
        return [
            'success' => true,
            'data' => ['downloads' => []],
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
