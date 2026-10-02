<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\FileFirst;

use Gisl\Sdk\Environment;
use Gisl\Sdk\Ergonomic\BuilderInternals;
use Gisl\Sdk\Ergonomic\ProcessingProgressEvent;
use Gisl\Sdk\Ergonomic\ProgressEvent;
use Gisl\Sdk\Ergonomic\RunOptions;
use Gisl\Sdk\Ergonomic\RunTransport;
use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\Gisl;
use Gisl\Sdk\GislErgonomicClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * v0JhuD8V — the transport a run actually used is visible on its result, and a
 * client that polls because no stream host is declared says so ONCE.
 *
 * Measured on staging with published sdk 0.38.0: a `baseUrl`-only client
 * resolves no stream host (by design — the stream host is declared, never
 * derived from `baseUrl`), so every run polled GET /status and never opened
 * /events, with no signal anywhere. Correct, and invisible.
 *
 * Every client here comes from {@see Gisl::create()}, the public entry point,
 * so the stream-host resolution under test is the real one. Warnings are
 * captured with a scoped error handler that records only the SDK's prefix, so
 * PHPUnit's own `failOnWarning` never sees them and an unrelated warning is not
 * swallowed.
 *
 * Mirrors the TS `run-transport.test.ts`.
 */
final class RunTransportTest extends TestCase
{
    private const WORKFLOW_ID = '01936fb2-0001-7000-8000-0000000071a2';
    private const TERMINAL_SSE = "event: workflow.completed\ndata: {\"status\":\"completed\"}\n\n";
    private const ENV_VARS = ['GISL_STREAM_BASE_URL', 'GISL_ENVIRONMENT', 'GISL_BASE_URL'];

    private HttpFactory $factory;

    /** @var array<string, string|false> */
    private array $envSnapshot = [];

    /** @var list<string> */
    private array $warnings = [];

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
        // The env arm of the resolver must not read the developer's shell, and
        // must not leak into the next test either: snapshot, clear, restore.
        foreach (self::ENV_VARS as $name) {
            $this->envSnapshot[$name] = \getenv($name);
            \putenv($name);
        }
        $this->warnings = [];
        \set_error_handler(function (int $errno, string $errstr): bool {
            if ($errno === \E_USER_WARNING
                && \str_starts_with($errstr, '[' . BuilderInternals::STREAM_HOST_NOT_DECLARED_WARNING . ']')) {
                $this->warnings[] = $errstr;
                return true;
            }
            return false;
        });
    }

    protected function tearDown(): void
    {
        \restore_error_handler();
        foreach ($this->envSnapshot as $name => $value) {
            \putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    /**
     * @param list<ResponseInterface>      $queue
     * @param list<RequestInterface>|null $captured
     * @param-out list<RequestInterface>  $captured
     */
    private function stubClient(array $queue, ?array &$captured = []): ClientInterface
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
                    throw new \RuntimeException('Stub PSR-18 client: response queue exhausted at '
                        . $request->getMethod() . ' ' . $request->getUri());
                }
                return $next;
            }
        };
    }

    private function create(
        ClientInterface $http,
        ?string $baseUrl = null,
        ?Environment $environment = null,
        ?string $streamBaseUrl = null,
    ): GislErgonomicClient {
        return Gisl::create(
            apiKey: 'sk_test',
            environment: $environment,
            baseUrl: $baseUrl,
            httpClient: $http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
            streamBaseUrl: $streamBaseUrl,
        );
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) \json_encode(
            ['success' => true, 'data' => $data],
            \JSON_THROW_ON_ERROR,
        ));
    }

    private function created(): ResponseInterface
    {
        return $this->json(['workflow_id' => self::WORKFLOW_ID, 'status' => 'pending']);
    }

    private function completed(): ResponseInterface
    {
        return $this->json(['workflow_id' => self::WORKFLOW_ID, 'status' => 'completed', 'jobs' => []]);
    }

    private function sse(string $body): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/event-stream'], $body);
    }

    private function downloads(): ResponseInterface
    {
        return $this->json(['downloads' => [[
            'job_id' => '01936fb3-0001-7000-8000-0000000071a3',
            'ref' => 'op',
            'files' => [[
                'operation' => 'compress',
                'operation_id' => '01936fb4-0001-7000-8000-0000000071a4',
                'filename' => 'photo_compressed.jpg',
                'size_bytes' => 512,
                'download_url' => 'https://signed.example.com/photo_compressed.jpg',
            ]],
        ]]]);
    }

    /** @param list<RequestInterface> $captured */
    private static function eventsRequests(array $captured): int
    {
        return \count(\array_filter(
            $captured,
            static fn (RequestInterface $r): bool => \str_ends_with($r->getUri()->getPath(), '/events'),
        ));
    }

    #[Test]
    public function a_base_url_only_client_polls_says_so_on_the_result_and_warns_exactly_once_across_two_runs(): void
    {
        $http = $this->stubClient([
            $this->created(), $this->completed(), $this->downloads(),
            $this->created(), $this->completed(), $this->downloads(),
        ], $captured);
        $client = $this->create($http, baseUrl: 'https://api.staging.giveitsmaller.com');

        $first = $client->file(FileInput::uploadId('file_1'))->compress()->run(pollIntervalMs: 0);
        $second = $client->file(FileInput::uploadId('file_1'))->compress()->run(pollIntervalMs: 0);

        self::assertSame(RunTransport::Polling, $first->transport);
        self::assertSame(RunTransport::Polling, $second->transport);
        self::assertSame(0, self::eventsRequests($captured), 'baseUrl must not move the stream: no /events request');
        self::assertCount(1, $this->warnings, 'one warning per CLIENT, not per run');
        // The warning names the fix, not just the symptom.
        self::assertStringContainsString('baseUrl does not move the stream host', $this->warnings[0]);
        self::assertStringContainsString('streamBaseUrl', $this->warnings[0]);
        self::assertStringContainsString('GISL_STREAM_BASE_URL', $this->warnings[0]);
        self::assertStringContainsString('useSSE: false', $this->warnings[0]);
    }

    #[Test]
    public function each_client_gets_its_own_warning(): void
    {
        foreach ([1, 2] as $_) {
            $http = $this->stubClient([$this->created(), $this->completed(), $this->downloads()]);
            $this->create($http, baseUrl: 'https://api.staging.giveitsmaller.com')
                ->file(FileInput::uploadId('file_1'))->compress()->run(pollIntervalMs: 0);
        }

        self::assertCount(2, $this->warnings);
    }

    #[Test]
    public function an_environment_client_streams_and_does_not_warn(): void
    {
        $http = $this->stubClient([$this->created(), $this->sse(self::TERMINAL_SSE), $this->completed(), $this->downloads()], $captured);
        $client = $this->create($http, environment: Environment::Staging);

        $result = $client->file(FileInput::uploadId('file_1'))->compress()->run();

        self::assertSame(RunTransport::Sse, $result->transport);
        self::assertSame(1, self::eventsRequests($captured));
        self::assertSame([], $this->warnings);
    }

    #[Test]
    public function a_stream_base_url_client_streams_and_does_not_warn(): void
    {
        $http = $this->stubClient([$this->created(), $this->sse(self::TERMINAL_SSE), $this->completed(), $this->downloads()]);
        $client = $this->create(
            $http,
            baseUrl: 'https://api.staging.giveitsmaller.com',
            streamBaseUrl: 'https://stream.staging.giveitsmaller.com',
        );

        $result = $client->file(FileInput::uploadId('file_1'))->compress()->run();

        self::assertSame(RunTransport::Sse, $result->transport);
        self::assertSame([], $this->warnings);
    }

    #[Test]
    public function the_stream_base_url_env_var_also_silences_the_warning(): void
    {
        \putenv('GISL_STREAM_BASE_URL=https://stream.staging.giveitsmaller.com');
        $http = $this->stubClient([$this->created(), $this->sse(self::TERMINAL_SSE), $this->completed(), $this->downloads()]);
        $client = $this->create($http, baseUrl: 'https://api.staging.giveitsmaller.com');

        $result = $client->file(FileInput::uploadId('file_1'))->compress()->run();

        self::assertSame(RunTransport::Sse, $result->transport);
        self::assertSame([], $this->warnings);
    }

    #[Test]
    public function use_sse_false_polls_without_a_warning(): void
    {
        $http = $this->stubClient([$this->created(), $this->completed(), $this->downloads()]);
        $client = $this->create($http, baseUrl: 'https://api.staging.giveitsmaller.com');

        $result = $client->file(FileInput::uploadId('file_1'))->compress()->run(pollIntervalMs: 0, useSSE: false);

        self::assertSame(RunTransport::Polling, $result->transport);
        self::assertSame([], $this->warnings, 'the caller chose polling; there is nothing to tell them');
    }

    #[Test]
    public function a_stream_that_opens_then_falls_back_reports_the_final_transport(): void
    {
        // The stream delivers a progress event, then ends cleanly with NO
        // terminal frame: run() falls back to polling. The result reports the
        // FINAL transport; the streamed progress is what onProgress saw.
        $http = $this->stubClient([
            $this->created(),
            $this->sse("event: operation.progress\ndata: {\"progress\":40,\"job_ref\":\"op\",\"operation_id\":\"o1\"}\n\n"),
            $this->completed(),
            $this->downloads(),
        ]);
        $client = $this->create($http, environment: Environment::Staging);
        /** @var list<ProgressEvent> $events */
        $events = [];

        $result = $client->file(FileInput::uploadId('file_1'))->compress()->run(
            onProgress: static function (ProgressEvent $e) use (&$events): void {
                $events[] = $e;
            },
            pollIntervalMs: 0,
        );

        self::assertSame(RunTransport::Polling, $result->transport);
        self::assertCount(1, $events);
        self::assertInstanceOf(ProcessingProgressEvent::class, $events[0]);
        self::assertSame([], $this->warnings, 'a stream host WAS declared; the fallback is not a configuration problem');
    }

    #[Test]
    public function the_transport_is_serialised_last(): void
    {
        $http = $this->stubClient([$this->created(), $this->completed(), $this->downloads()]);
        $client = $this->create($http, baseUrl: 'https://api.staging.giveitsmaller.com');

        $array = $client->file(FileInput::uploadId('file_1'))->compress()->run(pollIntervalMs: 0, useSSE: false)->toArray();

        self::assertSame('polling', $array['transport'] ?? null);
        self::assertSame('transport', \array_key_last($array));
    }

    #[Test]
    public function handle_wait_reports_its_transport_and_result_reports_none(): void
    {
        $http = $this->stubClient([
            $this->completed(), $this->downloads(),   // wait(): poll (no stream host) + downloads
            $this->completed(), $this->downloads(),   // result(): one status + downloads, no wait
        ]);
        $client = $this->create($http, baseUrl: 'https://api.staging.giveitsmaller.com');

        $waited = $client->workflow(self::WORKFLOW_ID)->wait();
        $fetched = $client->workflow(self::WORKFLOW_ID)->result();

        self::assertSame(RunTransport::Polling, $waited->transport);
        self::assertNull($fetched->transport, 'no wait happened, so there is no transport to report');
        self::assertArrayNotHasKey('transport', $fetched->toArray());
        self::assertCount(1, $this->warnings);
    }

    #[Test]
    public function the_operation_first_result_carries_the_transport_too(): void
    {
        $path = \tempnam(\sys_get_temp_dir(), 'gisl_tr_') . '.jpg';
        \file_put_contents($path, \str_repeat('x', 2048));
        try {
            $http = $this->stubClient([
                $this->json(['file_id' => '01936fb1-7bb3-7000-8000-0000000071a1', 'content_type' => 'image/jpeg', 'size_bytes' => 2048]),
                $this->created(),
                $this->completed(),
                $this->downloads(),
            ]);
            $client = $this->create($http, baseUrl: 'https://api.staging.giveitsmaller.com');

            $result = $client->compress($path)->run(new RunOptions(pollIntervalMs: 0));
        } finally {
            @\unlink($path);
        }

        self::assertSame(RunTransport::Polling, $result->transport);
        self::assertSame('polling', $result->toArray()['transport'] ?? null);
        self::assertCount(1, $this->warnings);
    }

    #[Test]
    public function an_error_handler_that_throws_cannot_fail_the_run(): void
    {
        // Laravel and Symfony-debug convert warnings to exceptions. By the time
        // the SDK knows it is polling, the workflow ALREADY EXISTS server-side,
        // so letting that exception out would lose a result the caller is
        // paying for. The SDK catches it and writes the message to error_log().
        \set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        });
        $logFile = \tempnam(\sys_get_temp_dir(), 'gisl_log_');
        $previousLog = \ini_set('error_log', (string) $logFile);
        try {
            $http = $this->stubClient([$this->created(), $this->completed(), $this->downloads()]);
            $client = $this->create($http, baseUrl: 'https://api.staging.giveitsmaller.com');

            $result = $client->file(FileInput::uploadId('file_1'))->compress()->run(pollIntervalMs: 0);

            self::assertSame(RunTransport::Polling, $result->transport);
            self::assertTrue($result->ok);
            self::assertStringContainsString(
                '[' . BuilderInternals::STREAM_HOST_NOT_DECLARED_WARNING . ']',
                (string) \file_get_contents((string) $logFile),
            );
        } finally {
            \restore_error_handler();
            \ini_set('error_log', $previousLog === false ? '' : $previousLog);
            @\unlink((string) $logFile);
        }
    }
}
