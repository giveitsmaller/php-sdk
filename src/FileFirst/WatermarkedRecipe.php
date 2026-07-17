<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

use Gisl\Generated\OpenApi\Model\WorkflowCreateResponse;
use Gisl\Sdk\Cancellation;
use Gisl\Sdk\Ergonomic\BuilderInternals;
use Gisl\Sdk\Ergonomic\Handle;
use Gisl\Sdk\Ergonomic\MaxWait;
use Gisl\Sdk\Ergonomic\OptionValidation;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\Http\UploadSource;
use Gisl\Sdk\JobDefinitionPayload;
use Gisl\Sdk\OperationDef;
use Gisl\Sdk\PresetDefaults;
use Gisl\Sdk\Sources;
use Gisl\Sdk\UploadOptions;
use Gisl\Sdk\WorkflowCreatePayload;

/**
 * The single-output recipe you're in AFTER `file($base)->watermark($overlay, …)`
 * (FF4a). Composites an image OVERLAY onto the base (`image_watermark` for image
 * bases, `video_watermark` for video bases — routed at lowering by the base's
 * effective media). A multi-input op: base + overlay each enter via their own
 * `passthrough` source job (`src_0` base, `src_1` overlay; their own preceding
 * steps lower into those jobs), and the `watermark` job consumes them via
 * `job_output` inputs tagged `role: base` / `role: overlay`. Post-watermark
 * `compress`/`convert`/`thumbnail` chain onto the watermark output.
 *
 * Immutable / clone-on-write. Mirrors the TS `WatermarkedRecipe` in
 * `packages/typescript/src/file-first.ts`. `textWatermark` is intentionally NOT
 * a post-verb here (matches {@see MergedRecipe}).
 */
final class WatermarkedRecipe
{
    /**
     * @param list<RecipeStep>     $baseSteps        The base recipe's preceding steps (lower into src_0).
     * @param array<string, mixed> $watermarkOptions Wire watermark options (anchor/opacity/margin_x/...).
     * @param list<RecipeStep>     $postSteps        Ops applied to the watermark output, in order.
     */
    public function __construct(
        private readonly FileInput $baseInput,
        private readonly array $baseSteps,
        private readonly Recipe $overlay,
        private readonly array $watermarkOptions,
        private readonly array $postSteps = [],
        private readonly ?PresetDefaults $presetDefaults = null,
        private readonly ?PresetDefaults $scopedPresetDefaults = null,
        private readonly ?GislClient $client = null,
    ) {
    }

    /**
     * Reduce the watermarked output's size. See {@see Recipe::compress()}.
     *
     * @param array<string, mixed> $options
     */
    public function compress(OptimizeFor|string|null $optimize = null, array $options = []): self
    {
        $options['optimize'] = Recipe::coerceOptimize($optimize ?? ($options['optimize'] ?? null));
        return $this->withStep(new RecipeStep('compress', $options));
    }

    /**
     * Change the watermarked output's format. See {@see Recipe::convert()}.
     *
     * @param array<string, mixed> $options
     */
    public function convert(string $format, array $options = []): self
    {
        // Eager pre-upload key validation (see Recipe::convert). Validation
        // guarantees the bag carries neither `format` nor `output_format`.
        OptionValidation::validateVerbOptions('convert', $options);
        return $this->withStep(new RecipeStep('convert', [...$options, 'output_format' => $format]));
    }

    /**
     * Thumbnail the watermarked output. `width` AND `height` are required.
     *
     * @param array<string, mixed> $options
     */
    public function thumbnail(array $options = []): self
    {
        OptionValidation::validateVerbOptions('thumbnail', $options);
        OptionValidation::assertThumbnailDimensions($options);
        $wire = [];
        foreach ($options as $key => $value) {
            if ($value !== null) {
                $wire[$key] = $value;
            }
        }
        return $this->withStep(new RecipeStep('thumbnail', $wire));
    }

    /**
     * Geometric transform (rotate/flip) of the watermarked output. Passthrough —
     * see {@see Recipe::transform()}. No dimension gate.
     *
     * @param array<string, mixed> $options
     */
    public function transform(array $options = []): self
    {
        OptionValidation::validateVerbOptions('transform', $options);
        $wire = [];
        foreach ($options as $key => $value) {
            if ($value !== null) {
                $wire[$key] = $value;
            }
        }
        return $this->withStep(new RecipeStep('transform', $wire));
    }

    private function withStep(RecipeStep $step): self
    {
        return new self(
            $this->baseInput,
            $this->baseSteps,
            $this->overlay,
            $this->watermarkOptions,
            [...$this->postSteps, $step],
            $this->presetDefaults,
            $this->scopedPresetDefaults,
            $this->client,
        );
    }

    /** The number of post-watermark ops chained so far (introspection / tests). */
    public function stepCount(): int
    {
        return \count($this->postSteps);
    }

    /**
     * Lower to the watermark DAG: a `src_0` passthrough/base-steps job + a `src_1`
     * passthrough/overlay-steps job + one `watermark` job whose `inputs[]` consume
     * them via `job_output` (role base/overlay) and whose `operations[]` is
     * `[image_watermark|video_watermark, ...post-watermark ops]`. `$fileIds` is
     * `[baseId, overlayId]` (upload order). Throws pre-lowering if the base media
     * is undetectable/unsupported (the planned-op gate). Mirrors the TS lowering.
     *
     * @param list<string> $fileIds [baseId, overlayId]
     */
    public function toWorkflowPayload(array $fileIds, ?string $callbackUrl = null): WorkflowCreatePayload
    {
        [$media, $mime] = WatermarkGate::effectiveBase($this->baseInput, $this->baseSteps);
        $wireOp = WatermarkGate::resolveWireOp($media, $mime);

        $baseId = $fileIds[0];
        $overlayId = $fileIds[1];

        // src_0: the base (its preceding steps, else a lossless passthrough).
        $baseOps = $this->baseSteps !== []
            ? (new Recipe($this->baseInput, null, $this->baseSteps, $this->presetDefaults, $this->scopedPresetDefaults))
                ->toWorkflowPayload($baseId)->jobs[0]->operations
            : [new OperationDef(type: 'passthrough')];
        // src_1: the overlay recipe (its own steps, else a lossless passthrough).
        $overlayOps = $this->overlay->recipeSteps() !== []
            ? $this->overlay->toWorkflowPayload($overlayId)->jobs[0]->operations
            : [new OperationDef(type: 'passthrough')];

        $srcBase = new JobDefinitionPayload(operations: $baseOps, id: 'src_0', source: Sources::upload($baseId));
        $srcOverlay = new JobDefinitionPayload(operations: $overlayOps, id: 'src_1', source: Sources::upload($overlayId));

        $inputs = [
            ['source' => Sources::jobOutput('src_0'), 'role' => 'base'],
            ['source' => Sources::jobOutput('src_1'), 'role' => 'overlay'],
        ];
        $operations = [
            WatermarkGate::lowerWatermarkOp($wireOp, $this->watermarkOptions),
            ...$this->lowerPostSteps($wireOp),
        ];
        $watermarkJob = new JobDefinitionPayload(operations: $operations, id: 'watermark', inputs: $inputs);

        return new WorkflowCreatePayload(jobs: [$srcBase, $srcOverlay, $watermarkJob], callbackUrl: $callbackUrl);
    }

    /**
     * Execute end-to-end: upload base + overlay, create the watermark workflow,
     * await terminal (SSE with poll fallback), then resolve ONLY the watermark
     * output into a {@see RunResult}. Mirrors {@see MergedRecipe::run()}.
     *
     * @param (callable(\Gisl\Sdk\Ergonomic\ProgressEvent): void)|null $onProgress
     * @param bool|null $useSSE Force the poll fallback instead of attempting SSE. Default true (SSE-first, poll fallback).
     */
    public function run(
        string|int|null $maxWait = null,
        ?callable $onProgress = null,
        ?int $pollIntervalMs = null,
        ?Cancellation $cancellation = null,
        ?bool $probeBeforeCreate = null,
        ?int $probeTimeoutMs = null,
        ?bool $useSSE = null,
    ): RunResult {
        $client = $this->requireClient();
        $deadlineMs = BuilderInternals::nowMs() + MaxWait::parse($maxWait ?? 600_000);
        $onProgressClosure = BuilderInternals::callableOrNull($onProgress, 'WatermarkedRecipe::run() $onProgress');

        $created = $this->uploadAllAndCreate(
            null,
            $deadlineMs,
            $onProgressClosure,
            $cancellation,
            $probeBeforeCreate,
            $probeTimeoutMs,
        );
        $workflowId = $created->getWorkflowId() ?? '';

        $finalStatus = BuilderInternals::awaitTerminal(
            client: $client,
            workflowId: $workflowId,
            deadlineMs: $deadlineMs,
            onProgress: $onProgressClosure,
            useSSE: $useSSE ?? true,
            pollIntervalMs: $pollIntervalMs,
            cancellation: $cancellation,
        );

        BuilderInternals::throwIfCancelled($cancellation, 'downloads fetch');
        if (BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError(
                "Workflow {$workflowId} reached terminal status but maxWait elapsed before downloads could be fetched.",
            );
        }
        $downloads = $client->getWorkflowDownloads($workflowId);
        if (BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError(
                "Workflow {$workflowId} downloads fetch completed after maxWait elapsed.",
            );
        }

        // Project ONLY the watermark job's output — the `src_*` passthrough jobs
        // re-expose the raw base/overlay uploads, which are plumbing.
        $watermarkDownloads = \array_values(\array_filter(
            $downloads->getDownloads() ?? [],
            static fn ($d): bool => BuilderInternals::coerceString($d->getRef()) === 'watermark',
        ));

        return RunResult::fromTerminalDownloads(
            workflowId: $workflowId,
            finalStatus: $finalStatus,
            jobDownloads: $watermarkDownloads,
            key: null,
            downloader: new StreamingDownloader(),
        );
    }

    /**
     * Fire-and-forget: upload base + overlay + create the watermark workflow
     * (wiring `$webhook` into `callback_url` when given), return a client-bound
     * {@see Handle}. Mirrors {@see MergedRecipe::submit()}.
     */
    public function submit(
        ?string $webhook = null,
        ?bool $probeBeforeCreate = null,
        ?int $probeTimeoutMs = null,
    ): Handle {
        $this->requireClient();
        $created = $this->uploadAllAndCreate($webhook, null, null, null, $probeBeforeCreate, $probeTimeoutMs);

        return new Handle(
            workflowId: $created->getWorkflowId() ?? '',
            webhookSecret: $created->getWebhookSecret(),
            client: $this->client,
        );
    }

    // ---------------------------------------------------------------------

    private function requireClient(): GislClient
    {
        if ($this->client === null) {
            throw new GislConfigError(
                'WatermarkedRecipe requires a client; build via Gisl::create()->file(...)->watermark(...) rather than constructing it directly.',
                reason: 'no_client',
            );
        }
        return $this->client;
    }

    /** Base + overlay inputs, in upload/lowering order (`[base, overlay]`). */
    private function inputsInOrder(): array
    {
        return [$this->baseInput, $this->overlay->recipeInput()];
    }

    /**
     * Validate the watermark BEFORE any upload: the base must route to a shippable
     * wire op (throws for undetectable/unsupported/planned bases), and the overlay
     * must be an image. Mirrors {@see MergedRecipe::validatePreUpload()}.
     */
    private function validatePreUpload(): void
    {
        [$media, $mime] = WatermarkGate::effectiveBase($this->baseInput, $this->baseSteps);
        WatermarkGate::resolveWireOp($media, $mime);
        WatermarkGate::validateOverlay($this->overlay);
    }

    /**
     * Upload base + overlay once, then create ONE watermark workflow. Mirrors
     * {@see MergedRecipe::uploadAllAndCreate()}.
     *
     * @param (\Closure(\Gisl\Sdk\Ergonomic\UploadProgressEvent): void)|null $onProgressClosure
     */
    private function uploadAllAndCreate(
        ?string $webhook,
        ?int $deadlineMs,
        ?\Closure $onProgressClosure,
        ?Cancellation $cancellation,
        ?bool $probeBeforeCreate = null,
        ?int $probeTimeoutMs = null,
    ): WorkflowCreateResponse {
        $client = $this->requireClient();
        $this->validatePreUpload();

        // Preflight every input before uploading any (mirrors MergedRecipe).
        foreach ($this->inputsInOrder() as $input) {
            if ($input->kind === FileInput::KIND_RESOURCE) {
                UploadSource::assertUploadableStream($input->resource);
                UploadOptions::assertHintsValid($input->contentType, $input->filename);
            } elseif ($input->kind === FileInput::KIND_PATH) {
                UploadSource::fromPath(BuilderInternals::coerceString($input->path));
            }
        }

        return MultiInputUpload::uploadAllAndCreate(
            $client,
            $this->inputsInOrder(),
            fn (array $fileIds, ?string $callbackUrl): WorkflowCreatePayload => $this->toWorkflowPayload($fileIds, $callbackUrl),
            $webhook,
            $deadlineMs,
            $onProgressClosure,
            $cancellation,
            $probeBeforeCreate,
            $probeTimeoutMs,
            'watermark upload',
            'watermark workflow creation',
            'watermark',
            'the watermark workflow',
        );
    }

    /**
     * Lower the post-watermark chain over a synthetic input whose extension
     * matches the watermark OUTPUT media (image→png, video→mp4) so
     * `compress(optimize: ...)` resolves the correct preset. Mirrors
     * {@see MergedRecipe::lowerPostSteps()}.
     *
     * @return list<OperationDef>
     */
    private function lowerPostSteps(string $wireOp): array
    {
        if ($this->postSteps === []) {
            return [];
        }
        $ext = $wireOp === WatermarkGate::OP_VIDEO ? 'mp4' : 'png';
        $synthetic = FileInput::path("watermarked.{$ext}");
        $recipe = new Recipe($synthetic, null, $this->postSteps, $this->presetDefaults, $this->scopedPresetDefaults);
        return $recipe->toWorkflowPayload('watermarked')->jobs[0]->operations;
    }
}
