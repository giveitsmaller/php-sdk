<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\FileFirst;

use Gisl\Generated\OpenApi\Model\JobResponse;
use Gisl\Generated\OpenApi\Model\WorkflowStatusResponse;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\Recipe;
use Gisl\Sdk\FileFirst\RunResult;
use Gisl\Sdk\FileFirst\WatermarkedRecipe;
use Gisl\Sdk\FileFirst\WatermarkGate;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
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
 * FF4a (Z7zTr789) — fluent `file($base)->watermark($overlay, $opts)` multi-input
 * verb. Mirrors the TS `file-first-watermark.test.ts`: media routing, the local
 * planned-op gate (throws pre-upload), the multi-input DAG lowering, base/overlay/
 * post steps, overlay validation, immutability, and the `isWatermarkStatus`
 * projection.
 */
#[CoversClass(WatermarkedRecipe::class)]
#[CoversClass(WatermarkGate::class)]
final class WatermarkRecipeTest extends TestCase
{
    private function recipe(string $path): Recipe
    {
        return new Recipe(FileInput::path($path));
    }

    private function overlay(string $path = 'logo.png'): Recipe
    {
        return new Recipe(FileInput::path($path));
    }

    /**
     * @param array<string, mixed> $wire
     * @return array<string, mixed>
     */
    private function watermarkJob(array $wire): array
    {
        /** @var list<array<string, mixed>> $jobs */
        $jobs = $wire['jobs'];
        foreach ($jobs as $job) {
            if (($job['id'] ?? null) === 'watermark') {
                return $job;
            }
        }
        self::fail('no watermark job in payload');
    }

    // ── routing ───────────────────────────────────────────────────────────

    public function test_image_base_routes_to_image_watermark(): void
    {
        $wire = $this->recipe('photo.jpg')->watermark($this->overlay(), ['anchor' => 'bottom_right', 'opacity' => 0.65])
            ->toWorkflowPayload(['base', 'ovl'])->toWire();
        self::assertSame('image_watermark', $this->watermarkJob($wire)['operations'][0]['type']);
    }

    public function test_tiff_base_routes_to_image_watermark(): void
    {
        // image_tiff flipped planned->stable (v2.148.0).
        $wire = $this->recipe('scan.tiff')->watermark($this->overlay())
            ->toWorkflowPayload(['base', 'ovl'])->toWire();
        self::assertSame('image_watermark', $this->watermarkJob($wire)['operations'][0]['type']);
    }

    public function test_bmp_base_routes_to_image_watermark(): void
    {
        // image_bmp flipped planned->stable (v2.148.0).
        $wire = $this->recipe('pic.bmp')->watermark($this->overlay())
            ->toWorkflowPayload(['base', 'ovl'])->toWire();
        self::assertSame('image_watermark', $this->watermarkJob($wire)['operations'][0]['type']);
    }

    public function test_video_base_routes_to_video_watermark(): void
    {
        $wire = $this->recipe('clip.mp4')->watermark($this->overlay(), ['anchor' => 'top_right'])
            ->toWorkflowPayload(['base', 'ovl'])->toWire();
        self::assertSame('video_watermark', $this->watermarkJob($wire)['operations'][0]['type']);
    }

    public function test_transformed_base_routes_by_output_media_thumbnail_to_image(): void
    {
        $wire = $this->recipe('clip.mp4')->thumbnail(['width' => 640, 'height' => 360])->watermark($this->overlay())
            ->toWorkflowPayload(['base', 'ovl'])->toWire();
        self::assertSame('image_watermark', $this->watermarkJob($wire)['operations'][0]['type']);
    }

    public function test_base_converted_to_video_routes_to_video_watermark(): void
    {
        $wire = $this->recipe('photo.jpg')->convert('mp4')->watermark($this->overlay())
            ->toWorkflowPayload(['base', 'ovl'])->toWire();
        self::assertSame('video_watermark', $this->watermarkJob($wire)['operations'][0]['type']);
    }

    public function test_named_typeless_resource_base_routes_by_filename(): void
    {
        // A resource with a filename hint but no contentType must route like a path
        // (mime falls back to the filename extension) — parity with the TS blob branch.
        $res = \fopen('php://temp', 'r+b');
        \assert(\is_resource($res));
        $wire = (new Recipe(FileInput::resource($res, 'photo.png')))->watermark($this->overlay())
            ->toWorkflowPayload(['base', 'ovl'])->toWire();
        self::assertSame('image_watermark', $this->watermarkJob($wire)['operations'][0]['type']);
        \fclose($res);
    }

    public function test_generic_content_type_resource_base_falls_back_to_filename(): void
    {
        // A non-media-bearing contentType (application/octet-stream) must NOT be the
        // routing mime — fall back to the filename extension (parity with TS blob).
        $res = \fopen('php://temp', 'r+b');
        \assert(\is_resource($res));
        $wire = (new Recipe(FileInput::resource($res, 'photo.png', 'application/octet-stream')))
            ->watermark($this->overlay())
            ->toWorkflowPayload(['base', 'ovl'])->toWire();
        self::assertSame('image_watermark', $this->watermarkJob($wire)['operations'][0]['type']);
        \fclose($res);
    }

    // ── lowering shape ──────────────────────────────────────────────────────

    public function test_lowers_to_src_passthrough_jobs_plus_role_tagged_watermark_job(): void
    {
        $wire = $this->recipe('photo.jpg')->watermark($this->overlay(), ['anchor' => 'center', 'overlay_width' => '30%'])
            ->toWorkflowPayload(['base_id', 'ovl_id'])->toWire();

        /** @var list<array<string, mixed>> $jobs */
        $jobs = $wire['jobs'];
        self::assertCount(3, $jobs);
        self::assertSame(
            ['id' => 'src_0', 'source' => ['type' => 'upload', 'file_id' => 'base_id'], 'operations' => [['type' => 'passthrough']]],
            $jobs[0],
        );
        self::assertSame(
            ['id' => 'src_1', 'source' => ['type' => 'upload', 'file_id' => 'ovl_id'], 'operations' => [['type' => 'passthrough']]],
            $jobs[1],
        );
        $wm = $this->watermarkJob($wire);
        self::assertSame([
            ['source' => ['type' => 'job_output', 'from' => 'src_0'], 'role' => 'base'],
            ['source' => ['type' => 'job_output', 'from' => 'src_1'], 'role' => 'overlay'],
        ], $wm['inputs']);
        self::assertSame(
            ['type' => 'image_watermark', 'options' => ['anchor' => 'center', 'overlay_width' => '30%']],
            $wm['operations'][0],
        );
    }

    public function test_omits_options_key_when_no_watermark_options(): void
    {
        $wire = $this->recipe('photo.jpg')->watermark($this->overlay())
            ->toWorkflowPayload(['b', 'o'])->toWire();
        self::assertSame(['type' => 'image_watermark'], $this->watermarkJob($wire)['operations'][0]);
    }

    public function test_lowers_base_preceding_steps_into_src_0_and_overlay_steps_into_src_1(): void
    {
        $wire = $this->recipe('hero.jpg')->thumbnail(['width' => 1200, 'height' => 800])
            ->watermark($this->overlay('logo.png')->convert('png'))
            ->toWorkflowPayload(['b', 'o'])->toWire();
        /** @var list<array<string, mixed>> $jobs */
        $jobs = $wire['jobs'];
        self::assertSame([['type' => 'thumbnail', 'options' => ['width' => 1200, 'height' => 800]]], $jobs[0]['operations']);
        self::assertSame([['type' => 'convert', 'options' => ['output_format' => 'png']]], $jobs[1]['operations']);
    }

    public function test_appends_post_watermark_steps_after_the_watermark_op(): void
    {
        $wire = $this->recipe('photo.jpg')->watermark($this->overlay())->convert('webp')
            ->toWorkflowPayload(['b', 'o'])->toWire();
        $types = \array_map(static fn (array $op): string => $op['type'], $this->watermarkJob($wire)['operations']);
        self::assertSame(['image_watermark', 'convert'], $types);
    }

    public function test_post_watermark_compress_resolves_against_output_media(): void
    {
        $wire = $this->recipe('photo.jpg')->watermark($this->overlay())->compress(OptimizeFor::Size)
            ->toWorkflowPayload(['b', 'o'])->toWire();
        $ops = $this->watermarkJob($wire)['operations'];
        $compress = null;
        foreach ($ops as $op) {
            if ($op['type'] === 'compress') {
                $compress = $op;
            }
        }
        self::assertNotNull($compress);
        // image presets never carry the video-only crf key.
        self::assertArrayNotHasKey('crf', $compress['options'] ?? []);
    }

    public function test_post_watermark_compress_resolves_against_video_output_media(): void
    {
        // video base -> video_watermark -> synthetic post media is video (mp4 arm),
        // so compress(Size) resolves the VIDEO Size cell (carries crf).
        $wire = $this->recipe('clip.mp4')->watermark($this->overlay())->compress(OptimizeFor::Size)
            ->toWorkflowPayload(['b', 'o'])->toWire();
        $ops = $this->watermarkJob($wire)['operations'];
        self::assertSame('video_watermark', $ops[0]['type']);
        $compress = null;
        foreach ($ops as $op) {
            if ($op['type'] === 'compress') {
                $compress = $op;
            }
        }
        self::assertNotNull($compress);
        self::assertArrayHasKey('crf', $compress['options'] ?? []);
    }

    public function test_wires_callback_url(): void
    {
        $wire = $this->recipe('photo.jpg')->watermark($this->overlay())
            ->toWorkflowPayload(['b', 'o'], 'https://hook.example.com')->toWire();
        self::assertSame('https://hook.example.com', $wire['callback_url'] ?? null);
    }

    // ── planned-op gate (throws pre-upload) ─────────────────────────────────

    public function test_throws_for_audio_base(): void
    {
        $this->expectException(GislConfigError::class);
        $this->recipe('song.mp3')->watermark($this->overlay());
    }

    public function test_throws_for_document_base(): void
    {
        $this->expectException(GislConfigError::class);
        $this->recipe('report.pdf')->watermark($this->overlay());
    }

    public function test_throws_for_gif_base_planned(): void
    {
        $this->expectExceptionMessageMatches('/not yet available|planned/');
        $this->recipe('loop.gif')->watermark($this->overlay());
    }

    public function test_throws_for_unsupported_image_subtype_avif(): void
    {
        $this->expectExceptionMessageMatches('/does not support/');
        $this->recipe('pic.avif')->watermark($this->overlay());
    }

    public function test_throws_for_unsupported_video_subtype_mov(): void
    {
        $this->expectExceptionMessageMatches('/does not support/');
        $this->recipe('clip.mov')->watermark($this->overlay());
    }

    public function test_defers_undetectable_base_then_throws_at_lowering(): void
    {
        // A bare upload id carries no media — the eager gate is deferred (no throw
        // at the verb); the gate fires when lowering resolves the wire op.
        $wr = (new Recipe(FileInput::uploadId('u_base')))->watermark($this->overlay());
        self::assertInstanceOf(WatermarkedRecipe::class, $wr);
        $this->expectExceptionMessageMatches('/detectable base media/');
        $wr->toWorkflowPayload(['u_base', 'o']);
    }

    // ── overlay validation ──────────────────────────────────────────────────

    public function test_throws_for_video_overlay(): void
    {
        $this->expectExceptionMessageMatches('/overlay must be an image/');
        $this->recipe('photo.jpg')->watermark($this->overlay('clip.mp4'));
    }

    public function test_throws_for_audio_overlay(): void
    {
        $this->expectExceptionMessageMatches('/overlay must be an image/');
        $this->recipe('photo.jpg')->watermark($this->overlay('track.mp3'));
    }

    public function test_allows_transformed_overlay_whose_output_is_image(): void
    {
        $wr = $this->recipe('photo.jpg')->watermark($this->overlay('clip.mp4')->thumbnail(['width' => 64, 'height' => 64]));
        self::assertInstanceOf(WatermarkedRecipe::class, $wr);
    }

    public function test_allows_undetectable_overlay_upload_id(): void
    {
        $wire = $this->recipe('photo.jpg')->watermark(new Recipe(FileInput::uploadId('u_ovl')))
            ->toWorkflowPayload(['base', 'u_ovl'])->toWire();
        /** @var list<array<string, mixed>> $jobs */
        $jobs = $wire['jobs'];
        self::assertSame([['type' => 'passthrough']], $jobs[1]['operations']);
    }

    // ── immutability ────────────────────────────────────────────────────────

    public function test_post_verbs_return_a_new_instance(): void
    {
        $base = $this->recipe('photo.jpg')->watermark($this->overlay());
        $next = $base->compress(OptimizeFor::Size);
        self::assertNotSame($base, $next);
        self::assertSame(0, $base->stepCount());
        self::assertSame(1, $next->stepCount());
    }

    // ── isWatermarkStatus ───────────────────────────────────────────────────

    /**
     * @param list<string> $refs
     */
    private function statusOf(array $refs): WorkflowStatusResponse
    {
        return new WorkflowStatusResponse([
            'jobs' => \array_map(static fn (string $ref): JobResponse => new JobResponse(['ref' => $ref]), $refs),
        ]);
    }

    public function test_is_watermark_status_true_for_src_plus_watermark(): void
    {
        self::assertTrue(RunResult::isWatermarkStatus($this->statusOf(['src_0', 'src_1', 'watermark'])));
    }

    public function test_is_watermark_status_false_without_watermark_job(): void
    {
        self::assertFalse(RunResult::isWatermarkStatus($this->statusOf(['src_0', 'src_1'])));
    }

    public function test_is_watermark_status_false_for_plain_chain(): void
    {
        self::assertFalse(RunResult::isWatermarkStatus($this->statusOf(['op'])));
    }

    public function test_is_watermark_status_false_for_empty_job_list(): void
    {
        self::assertFalse(RunResult::isWatermarkStatus($this->statusOf([])));
    }

    // ── run() through the shared MultiInputUpload helper ────────────────────
    // xxy5Rlsy follow-up (Wi4OnaJE): run() reaches the shared helper at runtime
    // (the other tests are lowering-only). Mirrors the TS file-first-watermark.

    private const WORKFLOW_ID = '01936fb2-0000-7000-8000-0000000000b0';

    public function test_run_creates_the_lowered_watermark_dag_and_projects_only_the_watermark_output(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->uploadResponse('01936fb1-7bb3-7000-8000-0000000060b1'),
            $this->uploadResponse('01936fb1-7bb3-7000-8000-0000000060b2'),
            $this->createResponse(),
            $this->sseResponse("event: workflow.completed\ndata: {\"status\":\"completed\"}\n\n"),
            $this->statusResponse('completed'),
            $this->watermarkDownloadsResponse(),
        ], $captured);

        $client = $this->makeClient($http);
        $base = $this->tempFile('jpg');
        $overlay = $this->tempFile('png');
        try {
            $result = $client->file($base)
                ->watermark(new Recipe(FileInput::path($overlay)), ['anchor' => 'center'])
                ->run();
        } finally {
            @\unlink($base);
            @\unlink($overlay);
        }

        // base + overlay both uploaded → the workflow create is the THIRD request.
        self::assertStringContainsString('/api/workflows', (string) $captured[2]->getUri());
        $body = \json_decode((string) $captured[2]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        // The lowered watermark DAG: src_0 base + src_1 overlay passthrough jobs +
        // the role-tagged watermark job.
        self::assertSame('src_0', $body['jobs'][0]['id']);
        self::assertSame('src_1', $body['jobs'][1]['id']);
        self::assertSame('watermark', $body['jobs'][2]['id']);
        self::assertSame('image_watermark', $body['jobs'][2]['operations'][0]['type']);

        // The RunResult projects ONLY the watermark output — the src_* passthrough
        // downloads (raw base/overlay) are filtered out.
        self::assertSame('completed', $result->state);
        self::assertTrue($result->ok);
        self::assertSame(['photo_watermarked.jpg'], \array_map(static fn ($a) => $a->filename, $result->artifacts));
        self::assertSame('https://signed.example.com/photo_watermarked.jpg', $result->url);
    }

    public function test_run_use_sse_false_polls_directly_and_never_opens_the_sse_stream(): void
    {
        // wf133EDR — useSSE:false routes straight to the poll fallback: no
        // /events SSE request, terminal resolved via /status. The queue carries
        // NO sse response; the default SSE-first path is pinned by
        // test_run_creates_the_lowered_watermark_dag_... (which queues + consumes
        // an sse response). base + overlay both upload, so the create is the
        // THIRD request.
        $captured = [];
        $http = $this->stubClient([
            $this->uploadResponse('01936fb1-7bb3-7000-8000-0000000060b1'),
            $this->uploadResponse('01936fb1-7bb3-7000-8000-0000000060b2'),
            $this->createResponse(),
            $this->statusResponse('completed'),
            $this->watermarkDownloadsResponse(),
        ], $captured);

        $client = $this->makeClient($http);
        $base = $this->tempFile('jpg');
        $overlay = $this->tempFile('png');
        try {
            $result = $client->file($base)
                ->watermark(new Recipe(FileInput::path($overlay)), ['anchor' => 'center'])
                ->run(useSSE: false, pollIntervalMs: 0);
        } finally {
            @\unlink($base);
            @\unlink($overlay);
        }

        self::assertSame('completed', $result->state);
        self::assertTrue($result->ok);
        self::assertSame(['photo_watermarked.jpg'], \array_map(static fn ($a) => $a->filename, $result->artifacts));

        // upload + upload + create + status + downloads — no SSE request issued.
        self::assertCount(5, $captured);
        $hitStatus = false;
        foreach ($captured as $request) {
            $uri = (string) $request->getUri();
            self::assertStringNotContainsString('/events', $uri, 'useSSE:false must not open the SSE stream');
            if (\str_contains($uri, '/status')) {
                $hitStatus = true;
            }
        }
        self::assertTrue($hitStatus, 'useSSE:false must resolve terminal via the /status poll');
    }

    public function test_run_mid_batch_timeout_message_names_the_watermark_label(): void
    {
        // Pin the watermark label noun the shared helper threads into its timeout
        // message. A mid-batch deadline (maxWait 1ms + a slow first upload over
        // base + overlay) trips the `during {uploadsLabel} uploads` throw.
        $base = $this->tempFile('jpg');
        $overlay = $this->tempFile('png');
        $http = $this->slowFirstStubClient([$this->uploadResponse(), $this->uploadResponse()]);
        $client = $this->makeClient($http);
        try {
            $client->file($base)
                ->watermark(new Recipe(FileInput::path($overlay)))
                ->run(maxWait: 1);
            self::fail('expected GislTimeoutError');
        } catch (GislTimeoutError $e) {
            self::assertStringContainsString('watermark', $e->getMessage());
        } finally {
            @\unlink($base);
            @\unlink($overlay);
        }
    }

    // ── run() stub plumbing (mirrors RecipeRunTest / FilesRecipeTest) ───────

    private function makeClient(ClientInterface $http): GislErgonomicClient
    {
        $factory = new HttpFactory();
        return new GislErgonomicClient(
            config: new GislClientConfig(baseUrl: 'https://api.example.com', apiKey: 'sk_test', multipartConcurrency: 1),
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
        );
    }

    /**
     * @param list<ResponseInterface> $queue
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
                    throw new \RuntimeException('Stub queue exhausted on ' . $request->getUri());
                }
                return $next;
            }
        };
    }

    /**
     * A PSR-18 stub that usleeps ~5ms on its FIRST request so a 1ms maxWait is
     * reliably blown DURING the upload loop — the mid-batch throw.
     *
     * @param list<ResponseInterface> $queue
     */
    private function slowFirstStubClient(array $queue): ClientInterface
    {
        return new class ($queue) implements ClientInterface {
            /** @var list<ResponseInterface> */
            private array $queue;
            private bool $first = true;

            /** @param list<ResponseInterface> $queue */
            public function __construct(array $queue)
            {
                $this->queue = $queue;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                if ($this->first) {
                    $this->first = false;
                    \usleep(5000);
                }
                $next = \array_shift($this->queue);
                if ($next === null) {
                    throw new \RuntimeException('Stub queue exhausted on ' . $request->getUri());
                }
                return $next;
            }
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) \json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function uploadResponse(string $fileId = '01936fb1-7bb3-7000-8000-0000000060b9'): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['file_id' => $fileId, 'content_type' => 'image/jpeg', 'size_bytes' => 2048],
        ]);
    }

    private function createResponse(): ResponseInterface
    {
        return $this->jsonResponse(201, [
            'success' => true,
            'data' => ['workflow_id' => self::WORKFLOW_ID, 'status' => 'pending'],
        ]);
    }

    private function sseResponse(string $sse): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/event-stream'], $sse);
    }

    private function statusResponse(string $status): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['workflow_id' => self::WORKFLOW_ID, 'status' => $status, 'jobs' => []],
        ]);
    }

    /**
     * Downloads carrying the src_* passthrough re-exposures of the raw base +
     * overlay uploads ALONGSIDE the watermark output, so run()'s
     * `ref === 'watermark'` filter is genuinely exercised.
     */
    private function watermarkDownloadsResponse(): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => [
                'downloads' => [
                    [
                        'job_id' => '01936fb3-0001-7000-8000-0000000060b1',
                        'ref' => 'src_0',
                        'files' => [[
                            'operation' => 'passthrough',
                            'operation_id' => '01936fb4-0001-7000-8000-0000000060b1',
                            'filename' => 'photo.jpg',
                            'size_bytes' => 1,
                            'download_url' => 'https://signed.example.com/photo.jpg',
                        ]],
                    ],
                    [
                        'job_id' => '01936fb3-0002-7000-8000-0000000060b2',
                        'ref' => 'src_1',
                        'files' => [[
                            'operation' => 'passthrough',
                            'operation_id' => '01936fb4-0002-7000-8000-0000000060b2',
                            'filename' => 'logo.png',
                            'size_bytes' => 1,
                            'download_url' => 'https://signed.example.com/logo.png',
                        ]],
                    ],
                    [
                        'job_id' => '01936fb3-0003-7000-8000-0000000060b3',
                        'ref' => 'watermark',
                        'files' => [[
                            'operation' => 'image_watermark',
                            'operation_id' => '01936fb4-0003-7000-8000-0000000060b3',
                            'filename' => 'photo_watermarked.jpg',
                            'size_bytes' => 99,
                            'download_url' => 'https://signed.example.com/photo_watermarked.jpg',
                        ]],
                    ],
                ],
            ],
        ]);
    }

    private function tempFile(string $ext): string
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'gisl_wm_');
        self::assertIsString($tmp);
        $path = $tmp . '.' . $ext;
        \rename($tmp, $path);
        \file_put_contents($path, \str_repeat('x', 64));
        return $path;
    }
}
