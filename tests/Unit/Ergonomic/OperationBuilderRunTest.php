<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Ergonomic;

use Gisl\Sdk\Ergonomic\Artifact;
use Gisl\Sdk\Ergonomic\OperationBuilder;
use Gisl\Sdk\Ergonomic\Result;
use Gisl\Sdk\Ergonomic\RunOptions;
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
