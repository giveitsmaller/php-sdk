<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Ergonomic;

use Gisl\Sdk\Cancellation;
use Gisl\Sdk\Ergonomic\RunOptions;
use Gisl\Sdk\Ergonomic\SubmitOptions;
use Gisl\Sdk\Errors\GislAbortError;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Cooperative cancellation (VOxtu0RZ-B3). A {@see Cancellation} token cancelled
 * mid-call aborts the blocking ergonomic paths with a {@see GislAbortError},
 * checked at the same between-steps boundaries as the `maxWait` deadline.
 */
#[CoversClass(Cancellation::class)]
#[CoversClass(GislAbortError::class)]
final class CancellationTest extends TestCase
{
    public function test_token_is_not_cancelled_until_cancel_called(): void
    {
        $token = new Cancellation();
        $this->assertFalse($token->isCancelled());
        $token->cancel();
        $this->assertTrue($token->isCancelled());
        // Idempotent + one-way.
        $token->cancel();
        $this->assertTrue($token->isCancelled());
    }

    public function test_run_with_already_cancelled_token_aborts_before_any_upload(): void
    {
        $token = new Cancellation();
        $token->cancel();

        // Empty queue: if ANY request fires, the stub throws "queue exhausted".
        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));

        $this->expectException(GislAbortError::class);
        try {
            $client->compress(self::writeTempFile('x'), ['quality' => 75])
                ->run(new RunOptions(maxWait: '5m', useSSE: false, cancellation: $token));
        } finally {
            $this->assertSame([], $captured, 'no wire traffic may fire when cancelled before upload');
        }
    }

    public function test_run_cancelled_during_poll_aborts_before_downloads(): void
    {
        $token = new Cancellation();

        // upload -> create -> status(processing, and cancel the token as it is
        // served) -> the next poll iteration's top-of-loop check must abort
        // BEFORE a second /status call or any /downloads call.
        $http = new class ($token) implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $captured = [];
            private Cancellation $token;
            private int $statusCalls = 0;

            public function __construct(Cancellation $token)
            {
                $this->token = $token;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;
                $uri = (string) $request->getUri();
                if (\str_contains($uri, '/api/uploads')) {
                    return CancellationTest::json(200, CancellationTest::uploadOk());
                }
                if (\str_contains($uri, '/status')) {
                    $this->statusCalls++;
                    if ($this->statusCalls > 1) {
                        throw new \RuntimeException('second /status must not fire — cancellation should abort first.');
                    }
                    $this->token->cancel();
                    return CancellationTest::json(200, CancellationTest::statusProcessing());
                }
                if (\str_contains($uri, '/downloads')) {
                    throw new \RuntimeException('/downloads must not fire after a cancelled wait.');
                }
                if (\str_contains($uri, '/api/workflows')) {
                    return CancellationTest::json(201, CancellationTest::createOk());
                }
                throw new \RuntimeException("unexpected request: {$uri}");
            }
        };

        $client = self::makeClient($http);
        $this->expectException(GislAbortError::class);
        $client->compress(self::writeTempFile('x'), ['quality' => 75])
            ->run(new RunOptions(maxWait: '30s', useSSE: false, pollIntervalMs: 50, cancellation: $token));
    }

    public function test_submit_with_already_cancelled_token_aborts_before_upload(): void
    {
        $token = new Cancellation();
        $token->cancel();

        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));

        $this->expectException(GislAbortError::class);
        try {
            $client->compress(self::writeTempFile('x'))
                ->submit(new SubmitOptions(webhook: 'https://example.com/cb', cancellation: $token));
        } finally {
            $this->assertSame([], $captured, 'submit must not upload when cancelled');
        }
    }

    public function test_submit_cancelled_during_upload_aborts_before_workflow_creation(): void
    {
        $token = new Cancellation();

        // Cancel the token while serving the upload; submit() must NOT proceed
        // to createWorkflow after a cancel that arrived during the upload.
        $http = new class ($token) implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $captured = [];
            private Cancellation $token;

            public function __construct(Cancellation $token)
            {
                $this->token = $token;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;
                $uri = (string) $request->getUri();
                if (\str_contains($uri, '/api/uploads')) {
                    $this->token->cancel();
                    return CancellationTest::json(200, CancellationTest::uploadOk());
                }
                if (\str_contains($uri, '/api/workflows')) {
                    throw new \RuntimeException('createWorkflow must not fire after a cancel during upload.');
                }
                throw new \RuntimeException("unexpected request: {$uri}");
            }
        };

        $client = self::makeClient($http);
        $this->expectException(GislAbortError::class);
        $client->compress(self::writeTempFile('x'))
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb', cancellation: $token));
    }

    public function test_merge_run_cancelled_between_uploads_aborts_before_second_upload(): void
    {
        $token = new Cancellation();

        // Cancel the token while serving the FIRST upload; the merge upload loop
        // checks cancellation at the top of each asset iteration, so the SECOND
        // asset must never upload and no workflow may be created.
        $http = new class ($token) implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $captured = [];
            private Cancellation $token;
            private int $uploadCalls = 0;

            public function __construct(Cancellation $token)
            {
                $this->token = $token;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;
                $uri = (string) $request->getUri();
                if (\str_contains($uri, '/api/uploads')) {
                    $this->uploadCalls++;
                    if ($this->uploadCalls > 1) {
                        throw new \RuntimeException('second upload must not fire after a between-uploads cancel.');
                    }
                    $this->token->cancel();
                    return CancellationTest::json(200, CancellationTest::uploadOk());
                }
                throw new \RuntimeException("merge cancel test must not reach {$uri}");
            }
        };

        $client = self::makeClient($http);
        $this->expectException(GislAbortError::class);
        $client->merge([self::writeTempFile('a.mp4'), self::writeTempFile('b.mp4')])
            ->run(new RunOptions(maxWait: '30s', useSSE: false, cancellation: $token));
    }

    public function test_files_run_cancelled_between_uploads_aborts_before_second_upload(): void
    {
        $token = new Cancellation();

        $http = new class ($token) implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $captured = [];
            private Cancellation $token;
            private int $uploadCalls = 0;

            public function __construct(Cancellation $token)
            {
                $this->token = $token;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;
                $uri = (string) $request->getUri();
                if (\str_contains($uri, '/api/uploads')) {
                    $this->uploadCalls++;
                    if ($this->uploadCalls > 1) {
                        throw new \RuntimeException('second fan-out upload must not fire after a cancel.');
                    }
                    $this->token->cancel();
                    return CancellationTest::json(200, CancellationTest::uploadOk());
                }
                throw new \RuntimeException("files cancel test must not reach {$uri}");
            }
        };

        $client = self::makeClient($http);
        $this->expectException(GislAbortError::class);
        $client->files([self::writeTempFile('1.jpg'), self::writeTempFile('2.jpg')])
            ->run(maxWait: '30s', cancellation: $token);
    }

    public function test_file_recipe_run_with_already_cancelled_token_aborts_before_upload(): void
    {
        $token = new Cancellation();
        $token->cancel();

        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));

        $this->expectException(GislAbortError::class);
        try {
            $client->file(self::writeTempFile('x.jpg'))
                ->run(maxWait: '5m', cancellation: $token);
        } finally {
            $this->assertSame([], $captured, 'single-file run must not upload when cancelled');
        }
    }

    public function test_run_cancelled_after_terminal_aborts_before_downloads(): void
    {
        $token = new Cancellation();

        // upload -> create -> status(completed, cancel the token as it is served)
        // -> the pre-downloads cancellation check must abort BEFORE /downloads.
        $http = new class ($token) implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $captured = [];
            private Cancellation $token;

            public function __construct(Cancellation $token)
            {
                $this->token = $token;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;
                $uri = (string) $request->getUri();
                if (\str_contains($uri, '/api/uploads')) {
                    return CancellationTest::json(200, CancellationTest::uploadOk());
                }
                if (\str_contains($uri, '/status')) {
                    $this->token->cancel();
                    return CancellationTest::json(200, CancellationTest::statusCompleted());
                }
                if (\str_contains($uri, '/downloads')) {
                    throw new \RuntimeException('/downloads must not fire — cancellation should abort after terminal wait.');
                }
                if (\str_contains($uri, '/api/workflows')) {
                    return CancellationTest::json(201, CancellationTest::createOk());
                }
                throw new \RuntimeException("unexpected request: {$uri}");
            }
        };

        $client = self::makeClient($http);
        $this->expectException(GislAbortError::class);
        $client->compress(self::writeTempFile('x'), ['quality' => 75])
            ->run(new RunOptions(maxWait: '30s', useSSE: false, pollIntervalMs: 50, cancellation: $token));
    }

    public function test_mapeach_run_with_already_cancelled_token_aborts_before_parent_upload(): void
    {
        $token = new Cancellation();
        $token->cancel();

        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));

        $this->expectException(GislAbortError::class);
        try {
            $client->compress(self::writeTempFile('x'), ['quality' => 75])
                ->mapEach(static fn ($art) => throw new \RuntimeException('fn must not run when cancelled'))
                ->run(new RunOptions(maxWait: '30s', useSSE: false, cancellation: $token));
        } finally {
            $this->assertSame([], $captured, 'mapEach must not upload the parent when cancelled');
        }
    }

    public function test_handle_wait_cancelled_during_poll_aborts(): void
    {
        $token = new Cancellation();

        $http = new class ($token) implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $captured = [];
            private Cancellation $token;
            private int $statusCalls = 0;

            public function __construct(Cancellation $token)
            {
                $this->token = $token;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;
                $uri = (string) $request->getUri();
                if (\str_contains($uri, '/events')) {
                    // Force the poll fallback: a clean SSE stream with no body.
                    return new Response(200, ['Content-Type' => 'text/event-stream'], '');
                }
                if (\str_contains($uri, '/status')) {
                    $this->statusCalls++;
                    if ($this->statusCalls > 1) {
                        throw new \RuntimeException('second /status must not fire after a cancelled wait.');
                    }
                    $this->token->cancel();
                    return CancellationTest::json(200, CancellationTest::statusProcessing());
                }
                if (\str_contains($uri, '/downloads')) {
                    throw new \RuntimeException('/downloads must not fire after a cancelled wait.');
                }
                throw new \RuntimeException("unexpected request: {$uri}");
            }
        };

        $client = self::makeClient($http);
        $handle = $client->workflow('01936fb2-0000-7000-8000-0000000000d1');

        $this->expectException(GislAbortError::class);
        $handle->wait('30s', null, $token);
    }

    // -----------------------------------------------------------------
    // Fixtures (public-static so the anonymous-class stubs can reach them).
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public static function uploadOk(): array
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
    public static function createOk(): array
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
    public static function statusProcessing(): array
    {
        return [
            'success' => true,
            'data' => [
                'workflow_id' => '01936fb2-0000-7000-8000-0000000000d1',
                'status' => 'in_progress',
                'created_at' => '2026-05-27T11:00:00Z',
                'updated_at' => '2026-05-27T11:00:05Z',
                'jobs' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function statusCompleted(): array
    {
        return [
            'success' => true,
            'data' => [
                'workflow_id' => '01936fb2-0000-7000-8000-0000000000d1',
                'status' => 'completed',
                'created_at' => '2026-05-27T11:00:00Z',
                'updated_at' => '2026-05-27T11:00:30Z',
                'jobs' => [],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function json(int $status, array $body): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            \json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private static function writeTempFile(string $name): string
    {
        $dir = \sys_get_temp_dir() . '/gisl-cancel-' . \bin2hex(\random_bytes(6));
        \mkdir($dir, 0700, true);
        $path = $dir . '/' . $name;
        \file_put_contents($path, 'bytes');
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
                        'Stub PSR-18 client: queue exhausted on ' . $request->getMethod() . ' ' . $request->getUri(),
                    );
                }
                return $next;
            }
        };
    }
}
