<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\FileFirst;

use Gisl\Sdk\Cancellation;
use Gisl\Sdk\Ergonomic\ProgressEvent;
use Gisl\Sdk\Ergonomic\UploadProgressEvent;
use Gisl\Sdk\Errors\GislAbortError;
use Gisl\Sdk\Errors\GislProbePendingError;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\SplitRecipe;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * JpgY3328 — {@see SplitRecipe::run()} and {@see SplitRecipe::submit()} forward
 * EVERY argument to the wrapped Recipe. `probeBeforeCreate`/`useSSE` are both
 * `?bool` and `pollIntervalMs`/`probeTimeoutMs` both `?int`, so a reordered
 * signature or a dropped argument would type-check. Each case gives one argument
 * a non-default value and fails if it does not arrive: most through a stub queue
 * short enough that the default path runs it dry (`response queue exhausted`),
 * the on-progress and SSE cases through a direct assertion on the events and
 * the request host. The webhook is not repeated here: the shared parity fixture
 * `ff_submit_split_pdf_page_groups` drives `SplitRecipe::submit($webhook)` and
 * pins `callback_url`, so it already fails if the webhook is dropped.
 */
final class SplitRecipeForwardingTest extends TestCase
{
    private const UPLOAD_ID = '01936fb1-7bb3-7000-8000-00000000e5b1';
    private const WORKFLOW_ID = '01936fb2-0000-7000-8000-00000000e5b2';
    private const STREAM_HOST = 'stream.example.com';

    private HttpFactory $factory;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @\unlink($path);
        }
        $this->tempFiles = [];
    }

    // --- run() --------------------------------------------------------------

    #[Test]
    public function run_forwards_max_wait(): void
    {
        $captured = [];
        $split = $this->uploadedSplit($this->stubClient([], $captured));

        try {
            $split->run(maxWait: 'not-a-duration');
            self::fail('expected the maxWait parse error');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('maxWait', $e->getMessage());
        }
        self::assertCount(0, $captured);
    }

    #[Test]
    public function run_forwards_on_progress(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([$this->uploadResponse(), $this->refusal()], $captured));
        /** @var list<ProgressEvent> $events */
        $events = [];

        try {
            $client->file($this->tempFile('pdf'))
                ->split(['mode' => 'page_groups', 'page_groups' => 2])
                ->run(
                    maxWait: '30s',
                    onProgress: static function (ProgressEvent $event) use (&$events): void {
                        $events[] = $event;
                    },
                    probeBeforeCreate: false,
                );
            self::fail('expected the probe_pending refusal');
        } catch (GislProbePendingError) {
        }
        self::assertCount(2, $captured);
        self::assertNotSame([], $events);
        self::assertInstanceOf(UploadProgressEvent::class, $events[0]);
    }

    #[Test]
    public function run_forwards_poll_interval_ms(): void
    {
        // A 60 s interval cannot fit a 10 s maxWait, so the poll gives up after ONE
        // status read. The 2 s default would sleep and read status again.
        $captured = [];
        $split = $this->uploadedSplit($this->stubClient([
            $this->createResponse(),
            $this->statusResponse('processing'),
        ], $captured));

        $this->expectException(GislTimeoutError::class);
        try {
            $split->run(maxWait: '10s', pollIntervalMs: 60_000, useSSE: false);
        } finally {
            self::assertCount(2, $captured);
        }
    }

    #[Test]
    public function run_forwards_cancellation(): void
    {
        $captured = [];
        $split = $this->uploadedSplit($this->stubClient([], $captured));
        $cancellation = new Cancellation();
        $cancellation->cancel();

        $this->expectException(GislAbortError::class);
        try {
            $split->run(maxWait: '30s', cancellation: $cancellation);
        } finally {
            self::assertCount(0, $captured);
        }
    }

    #[Test]
    public function run_forwards_probe_before_create(): void
    {
        // Opted out, the refusal is rethrown after ONE create; the default would
        // recover by polling the probe endpoint.
        $captured = [];
        $split = $this->uploadedSplit($this->stubClient([$this->refusal()], $captured));

        $this->expectException(GislProbePendingError::class);
        try {
            $split->run(maxWait: '30s', probeBeforeCreate: false);
        } finally {
            self::assertCount(1, $captured);
        }
    }

    #[Test]
    public function run_forwards_probe_timeout_ms(): void
    {
        // A zero budget gives up without a probe request; the default budget polls.
        $captured = [];
        $split = $this->uploadedSplit($this->stubClient([$this->refusal()], $captured));

        $this->expectException(GislProbePendingError::class);
        try {
            $split->run(maxWait: '30s', probeTimeoutMs: 0);
        } finally {
            self::assertCount(1, $captured);
        }
    }

    #[Test]
    public function run_forwards_use_sse(): void
    {
        $captured = [];
        $split = $this->uploadedSplit($this->stubClient([
            $this->createResponse(),
            $this->statusResponse('completed'),
            $this->downloadsResponse(),
        ], $captured));

        $result = $split->run(maxWait: '30s', useSSE: false);

        self::assertTrue($result->ok);
        self::assertCount(3, $captured);
        self::assertSame(
            [],
            \array_values(\array_filter($captured, static fn (RequestInterface $r): bool => $r->getUri()->getHost() === self::STREAM_HOST)),
            'useSSE: false must never open the stream',
        );
        self::assertSame('/api/workflows/' . self::WORKFLOW_ID . '/status', $captured[1]->getUri()->getPath());
    }

    #[Test]
    public function run_with_sse_left_on_opens_the_stream(): void
    {
        // Control for run_forwards_use_sse: the stub really does see the stream host.
        $captured = [];
        $split = $this->uploadedSplit($this->stubClient([
            $this->createResponse(),
            new Response(200, ['Content-Type' => 'text/event-stream'], "event: workflow.completed\ndata: {\"status\":\"completed\"}\n\n"),
            $this->statusResponse('completed'),
            $this->downloadsResponse(),
        ], $captured));

        $split->run(maxWait: '30s');

        self::assertSame(self::STREAM_HOST, $captured[1]->getUri()->getHost());
    }

    // --- submit() -----------------------------------------------------------

    #[Test]
    public function submit_forwards_probe_before_create(): void
    {
        $captured = [];
        $split = $this->uploadedSplit($this->stubClient([$this->refusal()], $captured));

        $this->expectException(GislProbePendingError::class);
        try {
            $split->submit(probeBeforeCreate: false);
        } finally {
            self::assertCount(1, $captured);
        }
    }

    #[Test]
    public function submit_forwards_probe_timeout_ms(): void
    {
        $captured = [];
        $split = $this->uploadedSplit($this->stubClient([$this->refusal()], $captured));

        $this->expectException(GislProbePendingError::class);
        try {
            $split->submit(probeTimeoutMs: 0);
        } finally {
            self::assertCount(1, $captured);
        }
    }

    #[Test]
    public function the_probe_recovery_default_polls_the_probe(): void
    {
        // Control for the probe cases: with neither argument, the refusal is NOT
        // rethrown after one create; recovery reaches for the probe endpoint and
        // runs the stub dry.
        $captured = [];
        $split = $this->uploadedSplit($this->stubClient([$this->refusal()], $captured));

        try {
            $split->submit();
            self::fail('expected the stub to run dry');
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(GislProbePendingError::class, $e);
            self::assertStringContainsString('response queue exhausted', $e->getMessage());
        }
        self::assertCount(2, $captured);
        self::assertStringEndsWith('/probe', $captured[1]->getUri()->getPath());
    }

    // --- helpers ------------------------------------------------------------

    /** A lone split over a pre-uploaded id: no upload leg, ONE job (`job_0`). */
    private function uploadedSplit(ClientInterface $http): SplitRecipe
    {
        return $this->makeClient($http)
            ->file(FileInput::uploadId(self::UPLOAD_ID))
            ->split(['mode' => 'page_groups', 'page_groups' => 2]);
    }

    private function tempFile(string $extension): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'gisl-split-fwd-');
        self::assertIsString($path);
        $named = $path . '.' . $extension;
        \rename($path, $named);
        \file_put_contents($named, 'split forwarding test bytes');
        $this->tempFiles[] = $named;
        return $named;
    }

    /**
     * @param list<ResponseInterface> $queue
     * @param list<RequestInterface>  $captured
     * @param-out list<RequestInterface> $captured
     */
    private function stubClient(array $queue, array &$captured = []): ClientInterface
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
                    throw new \RuntimeException('Stub PSR-18 client: response queue exhausted');
                }
                return $next;
            }
        };
    }

    private function makeClient(ClientInterface $http): GislErgonomicClient
    {
        return new GislErgonomicClient(
            config: new GislClientConfig(
                baseUrl: 'https://api.example.com',
                streamBaseUrl: 'https://' . self::STREAM_HOST,
                apiKey: 'sk_test',
                multipartConcurrency: 1,
            ),
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

    private function uploadResponse(): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['file_id' => self::UPLOAD_ID, 'content_type' => 'application/pdf', 'size_bytes' => 27],
        ]);
    }

    private function createResponse(): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['workflow_id' => self::WORKFLOW_ID, 'status' => 'pending'],
        ]);
    }

    /** The create refusal, naming the lone split job by its server token. */
    private function refusal(): ResponseInterface
    {
        return $this->jsonResponse(422, [
            'success' => false,
            'error' => 'UNPROCESSABLE_ENTITY',
            'error_type' => 'probe_pending',
            'message' => 'Upload probe has not completed.',
            'job_ref' => 'job_0',
        ]);
    }

    private function statusResponse(string $status): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => [
                'workflow_id' => self::WORKFLOW_ID,
                'status' => $status,
                'jobs' => [['ref' => 'job_0', 'status' => $status, 'operations' => []]],
            ],
        ]);
    }

    private function downloadsResponse(): ResponseInterface
    {
        return $this->jsonResponse(200, ['success' => true, 'data' => ['downloads' => [[
            'ref' => 'job_0',
            'files' => [[
                'operation' => 'split',
                'operation_id' => '01936fb4-0001-7000-8000-00000000e5b3',
                'filename' => 'doc-1.pdf',
                'size_bytes' => 10,
                'download_url' => 'https://signed.example.com/doc-1.pdf',
                'page_index' => 1,
            ]],
        ]]]]);
    }
}
