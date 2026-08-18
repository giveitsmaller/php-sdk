<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\FileFirst;

use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\Recipe;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * YOA6FpFr PR2 — seam-wiring for the probe-before-create gate on the file-first
 * Recipe::run() path. A real GislClient over a stubbed PSR-18 client lets us
 * observe the WIRE: a >10MB VIDEO input must POST /api/uploads/{id}/probe
 * BETWEEN the upload and the workflow create; an IMAGE input must not. Mirrors
 * the TS probe-before-create-wiring.test.ts (which spies the gate directly).
 *
 * The gate is a no-op unless the upload exceeded the multipart threshold
 * (default 10_000_000 bytes), so the upload response advertises a >10MB size.
 */
final class RecipeRunProbeBeforeCreateTest extends TestCase
{
    private const WORKFLOW_ID = '01936fb2-0000-7000-8000-000000000001';
    private const FID = '01936fb1-7bb3-7000-8000-0000000060f1';

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

    /**
     * @param array<string, mixed> $body
     */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) \json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** An upload response advertising a >10MB size so the multipart gate engages. */
    private function uploadResponseLarge(string $contentType): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['file_id' => self::FID, 'content_type' => $contentType, 'size_bytes' => 20_000_000],
        ]);
    }

    private function probeOk(): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => [
                'file_id' => self::FID,
                'probe_status' => 'ok',
                'media_metadata' => ['duration_seconds' => 600, 'codec' => 'h264', 'container' => 'mp4', 'probed_at' => '2026-06-16T10:00:00Z'],
                'processing_class_pre_assignment' => 'long_form',
            ],
        ]);
    }

    private function createResponse(): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['workflow_id' => self::WORKFLOW_ID, 'status' => 'pending'],
        ]);
    }

    private function sseResponse(): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/event-stream'], "event: workflow.completed\ndata: {\"status\":\"completed\"}\n\n");
    }

    private function statusResponse(): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['workflow_id' => self::WORKFLOW_ID, 'status' => 'completed', 'jobs' => []],
        ]);
    }

    private function downloadsResponse(): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['downloads' => []],
        ]);
    }

    private function tempFile(string $extension): string
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'gisl_probe_');
        self::assertIsString($tmp);
        $path = $tmp . $extension;
        \rename($tmp, $path);
        // 20 MB+ so a real client would also route multipart; the stub ignores
        // the byte content but the upload response size drives the gate.
        \file_put_contents($path, \str_repeat('x', 2048));
        return $path;
    }

    private function recipe(GislClient $client, FileInput $input): Recipe
    {
        return new Recipe($input, null, [], null, null, $client);
    }

    private function probeRequestIndex(array $captured): ?int
    {
        foreach ($captured as $i => $request) {
            if (\str_contains((string) $request->getUri(), '/api/uploads/' . self::FID . '/probe')) {
                return $i;
            }
        }
        return null;
    }

    #[Test]
    public function video_run_hits_the_probe_endpoint_between_upload_and_create(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->uploadResponseLarge('video/mp4'),
            $this->probeOk(),
            $this->createResponse(),
            $this->sseResponse(),
            $this->statusResponse(),
            $this->downloadsResponse(),
        ], $captured);

        $client = $this->makeClient($http);
        $path = $this->tempFile('.mp4');
        try {
            $result = $this->recipe($client, FileInput::path($path))->convert('webm')->run();
        } finally {
            @\unlink($path);
        }

        self::assertSame('completed', $result->state);

        // Locate the probe + upload + create requests by URI and assert ordering.
        $uploadIdx = null;
        $createIdx = null;
        foreach ($captured as $i => $request) {
            $uri = (string) $request->getUri();
            if (\str_contains($uri, '/api/uploads') && !\str_contains($uri, '/probe')) {
                $uploadIdx ??= $i;
            }
            if (\str_contains($uri, '/api/workflows')) {
                $createIdx ??= $i;
            }
        }
        $probeIdx = $this->probeRequestIndex($captured);

        self::assertNotNull($probeIdx, 'the probe endpoint must be hit for a >10MB video run');
        self::assertNotNull($uploadIdx);
        self::assertNotNull($createIdx);
        // The probe POST sits strictly between the upload and the workflow create.
        self::assertLessThan($probeIdx, $uploadIdx);
        self::assertLessThan($createIdx, $probeIdx);
        // It is a POST to the probe endpoint.
        self::assertSame('POST', $captured[$probeIdx]->getMethod());
    }

    #[Test]
    public function image_run_does_not_hit_the_probe_endpoint(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->uploadResponseLarge('image/jpeg'),
            // NO probe response queued — an image input must NOT probe. If the
            // gate wrongly fired, the create response would be consumed by the
            // probe and the run would derail.
            $this->createResponse(),
            $this->sseResponse(),
            $this->statusResponse(),
            $this->downloadsResponse(),
        ], $captured);

        $client = $this->makeClient($http);
        $path = $this->tempFile('.jpg');
        try {
            $result = $this->recipe($client, FileInput::path($path))->compress()->run();
        } finally {
            @\unlink($path);
        }

        self::assertSame('completed', $result->state);
        self::assertNull($this->probeRequestIndex($captured), 'an image run must not probe');
    }

    #[Test]
    public function probe_before_create_false_skips_the_probe_for_a_video(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->uploadResponseLarge('video/mp4'),
            // NO probe response — probeBeforeCreate:false disables the gate.
            $this->createResponse(),
            $this->sseResponse(),
            $this->statusResponse(),
            $this->downloadsResponse(),
        ], $captured);

        $client = $this->makeClient($http);
        $path = $this->tempFile('.mp4');
        try {
            $result = $this->recipe($client, FileInput::path($path))
                ->convert('webm')
                ->run(probeBeforeCreate: false);
        } finally {
            @\unlink($path);
        }

        self::assertSame('completed', $result->state);
        self::assertNull($this->probeRequestIndex($captured), 'probeBeforeCreate:false must skip the probe');
    }
}
