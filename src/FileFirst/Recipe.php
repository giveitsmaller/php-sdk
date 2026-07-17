<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

use Gisl\Generated\OpenApi\Model\WorkflowCreateResponse;
use Gisl\Sdk\Ergonomic\BuilderInternals;
use Gisl\Sdk\Ergonomic\Handle;
use Gisl\Sdk\Ergonomic\ImageOutputRoutes;
use Gisl\Sdk\Ergonomic\MaxWait;
use Gisl\Sdk\Ergonomic\OperationBuilder;
use Gisl\Sdk\Ergonomic\OptionValidation;
use Gisl\Sdk\Ergonomic\PresetResolver;
use Gisl\Sdk\Ergonomic\UploadProgressEvent;
use Gisl\Sdk\Cancellation;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\JobDefinitionPayload;
use Gisl\Sdk\OperationDef;
use Gisl\Sdk\PresetDefaults;
use Gisl\Sdk\Sources;
use Gisl\Sdk\UploadOptions;
use Gisl\Sdk\WorkflowCreatePayload;

/**
 * The file-first builder value. `$client->file($path)` returns a `Recipe`;
 * single-input operations called on it (`compress`, `convert`, `thumbnail`,
 * `textWatermark`) chain SEQUENTIALLY — each op feeds the next, and the chain
 * lowers to ONE workflow job with an ordered `operations[]` (per ADR-0004:
 * "Ordered list of operations. Executed sequentially — each operation consumes
 * the previous operation's output"). A chain yields the TERMINAL output only;
 * intermediates are consumed (surfaced by FF2b's `run()`/`RunResult`).
 *
 * **Immutable / clone-on-write.** Every op returns a NEW `Recipe` carrying the
 * appended step — `$this` is never mutated. A Recipe is therefore a reusable
 * value: branching the same base recipe two different ways cannot let one
 * branch observe the other's steps (the aliasing trap that mutable fluent
 * builders fall into). All properties are readonly and there is no setter;
 * `withStep()` rebuilds via the constructor rather than `clone`-and-mutate
 * (PHP forbids writing a readonly property on a clone).
 *
 * FF2a is network-free: there is NO `run()` here (that is FF2b). The lowering
 * seam {@see toWorkflowPayload()} takes the resolved upload id as a parameter
 * so it stays pure — FF2b's `run()` will call the SAME method after uploading,
 * and the parity harness calls it with a fixed id to assert the lowered shape.
 *
 * Mirrors the TS `Recipe` in `packages/typescript/src/file-first.ts`.
 */
final class Recipe
{
    /**
     * @param list<RecipeStep> $steps Ordered operations applied so far.
     */
    public function __construct(
        private readonly FileInput $input,
        private readonly ?string $key = null,
        private readonly array $steps = [],
        private readonly ?PresetDefaults $presetDefaults = null,
        private readonly ?PresetDefaults $scopedPresetDefaults = null,
        private readonly ?GislClient $client = null,
    ) {
    }

    /**
     * Reduce file size. `optimize` selects a per-media preset (resolved to
     * concrete wire fields at lower-time, exactly as `client->compress()` does)
     * — pass an {@see OptimizeFor} case or its string value. `$options` carries
     * the full per-op options bag (mirrors `client->compress($input, $options)`);
     * the explicit `$optimize` param wins over any `optimize` key in the bag.
     *
     * @param array<string, mixed> $options
     */
    public function compress(OptimizeFor|string|null $optimize = null, array $options = []): self
    {
        // The shorthand $optimize wins; otherwise preserve any `optimize` the
        // caller put in the bag (mirrors op-first, where optimize lives in the
        // bag) — omitting the shorthand must NOT null out a bag-supplied preset.
        $options['optimize'] = self::coerceOptimize($optimize ?? ($options['optimize'] ?? null));
        return $this->withStep(new RecipeStep('compress', $options));
    }

    /**
     * Change format. `$format` is the target container/codec family
     * (e.g. `'mp4'`, `'webp'`) — lowered to the `output_format` wire option
     * (the contract's required convert key, see convert.yaml), NOT `format`.
     * `$options` carries any additional per-op convert options.
     *
     * @param array{
     *   quality?: int,
     *   background?: string,
     *   crf?: int,
     *   trim_start?: float,
     *   trim_end?: float,
     *   fps?: int|float,
     *   width?: int,
     *   height?: int,
     *   fit?: 'max'|'crop'|'scale',
     *   metadata?: 'strip'|'keep',
     *   color_profile?: 'keep'|'srgb'|'strip',
     *   auto_orient?: bool,
     *   max_colors?: int,
     *   loop?: int,
     *   dither?: 'none'|'bayer'|'floyd_steinberg'|'sierra2'|'sierra2_4a',
     *   bitrate?: 64|96|128|192|256|320,
     *   pages?: string,
     *   dpi?: int,
     * } $options Keys are all optional so the `= []` default type-checks; the
     *   honored set narrows by media at lower time. Mirrors the TS `ConvertOptions`.
     */
    public function convert(string $format, array $options = []): self
    {
        // Eager pre-upload key validation (rejects unknown keys + a user-supplied
        // output_format/format, which this verb owns via the `$format` argument).
        OptionValidation::validateVerbOptions('convert', $options);
        // The convert op's wire key is `output_format` (contract: convert.yaml,
        // required, all media), NOT `format`. Validation above guarantees the bag
        // carries neither `format` nor `output_format`, so no drop is needed.
        return $this->withStep(new RecipeStep('convert', [...$options, 'output_format' => $format]));
    }

    /**
     * Generate a preview / resize. `width` AND `height` (in pixels) are BOTH
     * required (the contract marks both required for image/video/document); an
     * unknown key or a missing/null dimension throws `GislConfigError` before any
     * upload. Any additional per-op thumbnail option (fit/format/quality/…) passes
     * through; a null OPTIONAL value is dropped from the wire options. Mirrors the
     * TS `thumbnail({ width, height })`.
     *
     * @param array{
     *   width?: int,
     *   height?: int,
     *   fit?: 'max'|'crop'|'scale',
     *   format?: 'jpg'|'png'|'webp',
     *   quality?: int,
     *   background?: string,
     *   timestamp?: string,
     *   source?: 'page'|'cover',
     *   page?: int,
     * } $options `width` + `height` are REQUIRED at runtime (see above /
     *   {@see OptionValidation::assertThumbnailDimensions()}) but are marked
     *   optional in the shape so the `= []` default type-checks. Mirrors the TS
     *   `ThumbnailOptions`.
     */
    public function thumbnail(array $options = []): self
    {
        // Eager pre-upload validation: unknown keys rejected; width AND height
        // required (contract marks both required for image/video/document).
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
     * Apply a geometric transform: rotate (0/90/180/270°) and/or flip. A
     * PASSTHROUGH verb (like {@see thumbnail()}) — the `$options` bag lowers
     * verbatim to the `transform` wire op (unknown keys rejected pre-upload; a
     * null OPTIONAL value is dropped). Chainable — the canonical single-job order
     * the server applies is `transform → convert → compress → thumbnail`, so
     * downstream size options refer to the final (post-transform) frame.
     *
     * NO client-side media / dimension / availability gate: `rotate`/`flip` are
     * forwarded as-is (the SDK does NOT narrow per media — a `flip` on a PDF
     * input passes SDK validation but the server rejects it, matching thumbnail's
     * coarse per-op-union validation). The transform op is `availability: planned`
     * today, so a lowered transform returns `feature_not_available` (422) until
     * the per-media Lambdas ship. Mirrors the TS `Recipe::transform`.
     *
     * @param array{
     *   rotate?: 0|90|180|270,
     *   flip?: 'none'|'horizontal'|'vertical'|'both',
     * } $options Keys are all optional so the `= []` default type-checks.
     *   Mirrors the TS `TransformOptions`.
     */
    public function transform(array $options = []): self
    {
        // Eager pre-upload key validation: unknown keys rejected. No required
        // dimension / media gate — transform is a passthrough (server-validated).
        OptionValidation::validateVerbOptions('transform', $options);
        $wire = [];
        foreach ($options as $key => $value) {
            if ($value !== null) {
                $wire[$key] = $value;
            }
        }
        return $this->withStep(new RecipeStep('transform', $wire));
    }

    /**
     * Produce ONE transformed image: keep or change format, plus quality, resize
     * and route-honored controls. The single user-facing image transform — the SDK
     * resolves the route from `(input format, output_format)` against the contract's
     * image-output-routes projection and lowers to that route's wire op:
     * same-format → `compress` (optimiser, `output_format: 'original'`),
     * format-change → `convert` (transcoder, `output_format: <fmt>`). Only options
     * the resolved route honors are sent; a planned or not-honored option throws
     * BEFORE upload. Resize (`width`/`height`/`fit`, via `$options` or
     * {@see resize()}) stays on the SAME op — one output, never a separate
     * thumbnail. Mirrors the TS `Recipe::output`.
     *
     * `$format` null → keep the input format (same-format optimiser route).
     *
     * `$options` is an OutputOptions array shape (keys honored vary by route; the
     * lowering narrows per route and throws on a not-honored/planned option):
     *
     * @param array{
     *   quality?: int,
     *   quality_preset?: 'best'|'good'|'fair'|'low',
     *   encoding_mode?: 'quality'|'target_size',
     *   target_size_bytes?: int,
     *   chroma_subsampling?: '420'|'422'|'444',
     *   width?: int,
     *   height?: int,
     *   fit?: 'max'|'crop'|'scale',
     *   background?: string,
     *   progressive?: bool,
     *   optimization_level?: int,
     *   avif_speed?: int,
     *   metadata?: 'strip'|'keep',
     *   color_profile?: 'keep'|'srgb'|'strip',
     *   auto_orient?: bool,
     *   lossless?: bool,
     * } $options
     */
    public function output(?string $format = null, array $options = []): self
    {
        // Eager pre-upload key validation (coarse: rejects keys no image route
        // honors, + a bag-supplied output_format/format which the positional
        // `$format` owns).
        OptionValidation::validateVerbOptions('output', $options);
        $wire = [];
        foreach ($options as $key => $value) {
            if ($value !== null) {
                $wire[$key] = $value;
            }
        }
        // Store the REQUESTED format token under `output_format`; lowerOutputStep
        // resolves the route and rewrites it to the wire value ('original' for
        // same-format). Omitted format → no output_format key → same-format route.
        if ($format !== null) {
            $wire['output_format'] = $format;
        }
        return $this->withStep(new RecipeStep('output', $wire));
    }

    /**
     * Resize as part of the Output transform. Merges `width`/`height`/`fit` into
     * the PRECEDING `output()` step (one artifact); if no Output step precedes,
     * appends a same-format Output step carrying the resize. Never emits a
     * `thumbnail` op. `$height` is optional — width-only resize preserves aspect
     * ratio. Resize is raster-only (e.g. an SVG input has no resize on its route →
     * throws at lower). Mirrors the TS `Recipe::resize`.
     */
    public function resize(int $width, ?int $height = null, ?string $fit = null): self
    {
        $resizeOptions = ['width' => $width];
        if ($height !== null) {
            $resizeOptions['height'] = $height;
        }
        if ($fit !== null) {
            $resizeOptions['fit'] = $fit;
        }
        $steps = $this->steps;
        $last = $steps !== [] ? $steps[\count($steps) - 1] : null;
        if ($last !== null && $last->opType === 'output') {
            $steps[\count($steps) - 1] = new RecipeStep('output', [...$last->options, ...$resizeOptions]);
            return new self(
                $this->input,
                $this->key,
                $steps,
                $this->presetDefaults,
                $this->scopedPresetDefaults,
                $this->client,
            );
        }
        return $this->withStep(new RecipeStep('output', $resizeOptions));
    }

    /**
     * Apply a text watermark. Single-input (the text is an option, not a
     * secondary file) — lowers to the `text_watermark` op with a `text` option.
     * `$options` carries any additional per-op watermark options.
     *
     * @param array{
     *   font_size?: int,
     *   color?: string,
     *   font_family?: 'liberation_sans',
     *   rotation?: int,
     *   watermark_mode?: 'single'|'tiled',
     *   tile_spacing?: int,
     *   anchor?: 'top_left'|'top_center'|'top_right'|'center_left'|'center'|'center_right'|'bottom_left'|'bottom_center'|'bottom_right',
     *   margin_x?: string,
     *   margin_y?: string,
     *   opacity?: float,
     * } $options Keys are all optional so the `= []` default type-checks.
     *   Mirrors the TS `TextWatermarkOptions`.
     */
    public function textWatermark(string $text, array $options = []): self
    {
        // Eager pre-upload validation (rejects unknown keys + a user-supplied
        // `text`, which this verb owns via the first argument).
        OptionValidation::validateVerbOptions('textWatermark', $options);
        return $this->withStep(new RecipeStep('text_watermark', [...$options, 'text' => $text]));
    }

    /**
     * Composite an image OVERLAY onto this file (a multi-input op). `$overlay` is
     * a secondary file-NODE (a {@see Recipe} — e.g. `$client->file('logo.png')`),
     * itself optionally processed first. Routes by THIS file's effective media:
     * image base → `image_watermark` (stable), video base → `video_watermark`
     * (beta). Audio/document/animated-GIF/unsupported-subtype/undetectable bases
     * throw locally BEFORE any upload (the planned-op gate). `$options` carries
     * the wire watermark options (`anchor`, `opacity`, `margin_x`, `margin_y`,
     * `overlay_width`, or `overlays[]` for the multi-overlay stack). Returns a
     * {@see WatermarkedRecipe} (chain post-watermark
     * `compress`/`convert`/`thumbnail`, then `run`/`submit`). Distinct from
     * {@see textWatermark()} (single-input text overlay). Mirrors the TS
     * `Recipe::watermark`.
     *
     * @param array{
     *   anchor?: 'top_left'|'top_center'|'top_right'|'center_left'|'center'|'center_right'|'bottom_left'|'bottom_center'|'bottom_right',
     *   margin_x?: string,
     *   margin_y?: string,
     *   opacity?: float,
     *   overlay_width?: string,
     *   overlays?: list<array{anchor?: 'top_left'|'top_center'|'top_right'|'center_left'|'center'|'center_right'|'bottom_left'|'bottom_center'|'bottom_right', margin_x?: string, margin_y?: string, opacity?: float, overlay_width?: string}>,
     * } $options The flat single-overlay keys and `overlays[]` are mutually
     *   exclusive (server rejects mixing as `invalid_options`). Keys are all
     *   optional so the `= []` default type-checks. Mirrors the TS
     *   `WatermarkOptions`.
     */
    public function watermark(Recipe $overlay, array $options = []): WatermarkedRecipe
    {
        // Eager pre-upload key validation (against image_watermark ∪ video_watermark,
        // since the base media may be undetectable here; routing is gated separately).
        OptionValidation::validateVerbOptions('watermark', $options);
        // Eager gate when the base media is KNOWN (unit-testable pre-upload); an
        // undetectable base is DEFERRED — re-checked pre-upload in run()/submit().
        [$media, $mime] = WatermarkGate::effectiveBase($this->input, $this->steps);
        if ($media !== null) {
            WatermarkGate::resolveWireOp($media, $mime);
        }
        WatermarkGate::validateOverlay($overlay);

        return new WatermarkedRecipe(
            $this->input,
            $this->steps,
            $overlay,
            $options,
            [],
            $this->presetDefaults,
            $this->scopedPresetDefaults,
            $this->client,
        );
    }

    /**
     * Lower this recipe to a workflow-create payload against a resolved upload
     * id. Single-input chain → ONE job, `source: upload($fileId)`, ordered
     * `operations[]`; the job `id` is omitted (a single job referenced by
     * nothing — the server auto-assigns `job_N`).
     *
     * When `$callbackUrl` is given (the file-first `submit()` path), it is built
     * INTO the payload at construction (`callback_url`) rather than spread onto
     * an already-built readonly payload. `run()` passes no `$callbackUrl`.
     *
     * @internal Consumed by FF2b's `run()` (after a real upload), FF5b's
     *           `submit()` (with a webhook), and the cross-language parity
     *           harness (with a fixed id). Not part of the caller-facing fluent
     *           surface.
     */
    public function toWorkflowPayload(string $fileId, ?string $callbackUrl = null): WorkflowCreatePayload
    {
        $operations = [];
        foreach ($this->steps as $i => $step) {
            $operations[] = $this->lowerStep($step, $i);
        }

        $job = new JobDefinitionPayload(
            operations: $operations,
            source: Sources::upload($fileId),
        );

        return new WorkflowCreatePayload(jobs: [$job], callbackUrl: $callbackUrl);
    }

    /**
     * Trigger the per-step lowering purely for its validation side effects
     * (e.g. {@see lowerCompressOptions()}'s `media_unknown` guard), discarding
     * the result. Used to fail fast BEFORE uploading bytes. Lowering does not
     * depend on the upload id, so this is a faithful preflight.
     */
    private function assertOperationsLowerable(): void
    {
        foreach ($this->steps as $i => $step) {
            $this->lowerStep($step, $i);
        }
    }

    /** The result-addressing key passed to `file()`, or null. */
    public function key(): ?string
    {
        return $this->key;
    }

    /** The number of operations chained so far (introspection / tests). */
    public function stepCount(): int
    {
        return \count($this->steps);
    }

    /**
     * The captured op chain. Read by {@see FilesRecipe} to compose a shared
     * chain across many inputs without duplicating the chain-method validation.
     *
     * @internal
     *
     * @return list<RecipeStep>
     */
    public function recipeSteps(): array
    {
        return $this->steps;
    }

    /**
     * The primary input this recipe operates on. Read by {@see WatermarkedRecipe}
     * to lift an overlay Recipe's input (for upload + media inference + src-job
     * lowering) without exposing the constructor field publicly.
     *
     * @internal
     */
    public function recipeInput(): FileInput
    {
        return $this->input;
    }

    /**
     * Execute the recipe end-to-end: upload the input (when required), create
     * the workflow, await a terminal state (SSE with poll fallback), then
     * resolve the produced downloads into a flat {@see RunResult}. Throws
     * {@see GislTimeoutError} if `$maxWait` elapses before terminal status.
     *
     * Mirrors {@see \Gisl\Sdk\Ergonomic\OperationBuilder::run()}. Requires a
     * client bound at construction time — `Gisl::create()->file(...)` wires it;
     * a directly-constructed Recipe (e.g. a lowering-only test) has no client
     * and throws {@see GislConfigError}.
     *
     * @param string|int|null $maxWait Wall-clock deadline for the whole run
     *                                 (upload + create + wait + downloads).
     *                                 String suffix (`'2h'`/`'30m'`/`'120s'`)
     *                                 or milliseconds; defaults to 600s.
     * @param (callable(\Gisl\Sdk\Ergonomic\ProgressEvent): void)|null $onProgress
     * @param int|null $pollIntervalMs Override the poll-fallback interval (ms).
     * @param Cancellation|null $cancellation Cooperative cancellation token —
     *        cancel it to abort the run early (between steps) with a
     *        {@see \Gisl\Sdk\Errors\GislAbortError}.
     * @param bool|null $probeBeforeCreate Best-effort probe-before-create for a
     *        VIDEO upload that went multipart (default true). Pass false to skip.
     * @param int|null $probeTimeoutMs Overall timeout (ms) for the probe wait.
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
        if ($this->client === null) {
            throw new GislConfigError(
                'Recipe::run() requires a client; build the recipe via Gisl::create()->file(...) rather than constructing Recipe directly.',
                reason: 'no_client',
            );
        }

        $deadlineMs = BuilderInternals::nowMs() + MaxWait::parse($maxWait ?? 600_000);
        $onProgressClosure = BuilderInternals::callableOrNull($onProgress, 'Recipe::run() $onProgress');

        // 1+2. Upload (when required) + create the workflow. Shared with
        // submit() (which passes a webhook → callback_url). run() passes none.
        $created = $this->uploadAndCreate(
            null,
            $deadlineMs,
            $onProgressClosure,
            $cancellation,
            $probeBeforeCreate,
            $probeTimeoutMs,
        );
        $workflowId = $created->getWorkflowId() ?? '';

        // 3. Wait to terminal status — SSE first, poll on a genuine SSE error.
        $finalStatus = BuilderInternals::awaitTerminal(
            client: $this->client,
            workflowId: $workflowId,
            deadlineMs: $deadlineMs,
            onProgress: $onProgressClosure,
            useSSE: $useSSE ?? true,
            pollIntervalMs: $pollIntervalMs,
            cancellation: $cancellation,
        );

        // 4. Fetch downloads. Codex TS r1 medium 42a6ea3b6102 — the maxWait
        // deadline covers upload + create + wait + downloads, so check before
        // issuing the request rather than letting a slow call exceed it.
        BuilderInternals::throwIfCancelled($cancellation, 'downloads fetch');
        if (BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError(
                "Workflow {$workflowId} reached terminal status but maxWait elapsed before downloads could be fetched.",
                $workflowId,
            );
        }
        $downloads = $this->client->getWorkflowDownloads($workflowId);
        // TDqmkWpX: the maxWait deadline also covers the downloads fetch itself —
        // re-check AFTER the call so a slow getWorkflowDownloads cannot return a
        // success past the advertised whole-run deadline.
        if (BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError(
                "Workflow {$workflowId} downloads fetch completed after maxWait elapsed.",
                $workflowId,
            );
        }

        // Download URLs from getWorkflowDownloads are pre-signed and require no
        // SDK auth, so the downloader issues a plain unauthenticated stream.
        // The flatten + succeeded/failed partition lives in the shared
        // RunResult::fromTerminalDownloads() helper (also used by Handle).
        return RunResult::fromTerminalDownloads(
            workflowId: $workflowId,
            finalStatus: $finalStatus,
            jobDownloads: \array_values($downloads->getDownloads() ?? []),
            key: $this->key,
            downloader: new StreamingDownloader(),
        );
    }

    /**
     * Fire-and-forget the recipe: upload the input (when required), create the
     * workflow (wiring `$webhook` into `callback_url` when given), and return a
     * client-bound {@see Handle} carrying the workflow id + webhook secret + the
     * recipe key. Does NOT wait for terminal status — call `$handle->wait()` /
     * `$handle->result()` later to collect the {@see RunResult}.
     *
     * Requires a client bound at construction time (same `no_client` guard as
     * {@see run()}). `$webhook` is OPTIONAL: when null, no `callback_url` is
     * sent. Mirrors the TS `Recipe.submit()`.
     *
     * @param string|null $webhook Absolute callback URL the server POSTs
     *                             lifecycle events to.
     * @param bool|null $probeBeforeCreate Best-effort probe-before-create for a
     *        VIDEO upload that went multipart (default true). Pass false to skip.
     * @param int|null $probeTimeoutMs Overall timeout (ms) for the probe wait.
     */
    public function submit(
        ?string $webhook = null,
        ?bool $probeBeforeCreate = null,
        ?int $probeTimeoutMs = null,
    ): Handle {
        if ($this->client === null) {
            throw new GislConfigError(
                'Recipe::submit() requires a client; build the recipe via Gisl::create()->file(...) rather than constructing Recipe directly.',
                reason: 'no_client',
            );
        }

        // submit() is fire-and-forget — NO whole-run deadline. The upload may be
        // large (a multi-GB master, example 12) and is bounded by the HTTP
        // client's own request timeout, not an arbitrary submit-side cap. Pass
        // null so the post-upload deadline check is skipped: a 600s cap here
        // would throw on a slow-but-successful big upload before createWorkflow
        // (codex).
        $created = $this->uploadAndCreate($webhook, null, null, null, $probeBeforeCreate, $probeTimeoutMs);

        return new Handle(
            workflowId: $created->getWorkflowId() ?? '',
            webhookSecret: $created->getWebhookSecret(),
            client: $this->client,
            key: $this->key,
        );
    }

    /**
     * Resolve the upload id (verbatim for a pre-uploaded id; uploading a path
     * otherwise via the byte-counter progress closure; resource-stream inputs
     * throw `resource_input_unsupported`), check the post-upload deadline, lower
     * to the workflow-create payload (wiring `$webhook` into `callback_url`),
     * and create the workflow. Shared first half of {@see run()} + {@see submit()}.
     *
     * The post-upload deadline check carries a prior codex fix (9a117f04eb59):
     * a slow upload must not proceed to createWorkflow past the deadline.
     *
     * @param \Closure|null $onProgressClosure Already-coerced progress closure.
     */
    private function uploadAndCreate(
        ?string $webhook,
        ?int $deadlineMs,
        ?\Closure $onProgressClosure,
        ?Cancellation $cancellation = null,
        ?bool $probeBeforeCreate = null,
        ?int $probeTimeoutMs = null,
    ): WorkflowCreateResponse {
        // 0. Honour an already-cancelled token before spending the upload.
        BuilderInternals::throwIfCancelled($cancellation, 'upload');

        // Preflight the operation lowering BEFORE any upload so a local lowering
        // failure (e.g. compress(optimize: ...) on a stream / upload-id input
        // with no inferable media) fails fast instead of after the upload bytes
        // are spent (codex VOxtu0RZ-B4).
        $this->assertOperationsLowerable();

        // 1. Resolve the upload id. A pre-uploaded id skips the upload entirely;
        // a path or a seekable stream resource (VOxtu0RZ-B4) is uploaded now,
        // emitting UploadProgressEvent from the byte counter. A non-seekable
        // stream is rejected by uploadFile().
        //
        // A pre-uploaded id carries no local mime/size, so the video probe-gate
        // is skipped for it (no $uploadResp to read getSizeBytes() from).
        $uploadSizeBytes = null;
        if ($this->input->kind === FileInput::KIND_UPLOAD_ID) {
            $fileId = BuilderInternals::coerceString($this->input->fileId);
        } else {
            $onProgressUpload = $onProgressClosure !== null
                ? static function (int $u, int $t) use ($onProgressClosure): void {
                    $onProgressClosure(new UploadProgressEvent($u, $t));
                }
                : null;
            $uploadTarget = BuilderInternals::coerceString($this->input->path);
            $uploadOpts = $onProgressUpload !== null ? new UploadOptions(onProgress: $onProgressUpload) : null;
            if ($this->input->kind === FileInput::KIND_RESOURCE) {
                \assert(\is_resource($this->input->resource));
                $uploadTarget = $this->input->resource;
                // fFwaKsN5: carry the resource's filename/contentType hints to the
                // upload so a nameless stream uploads with a real name + MIME
                // (the server's media inference reads them), matching a browser
                // Blob's .name/.type.
                $uploadOpts = new UploadOptions(
                    onProgress: $onProgressUpload,
                    contentType: $this->input->contentType,
                    filename: $this->input->filename,
                );
            }
            $uploadResp = $this->client?->uploadFile($uploadTarget, $uploadOpts);
            $fileId = $uploadResp?->getFileId() ?? '';
            $uploadSizeBytes = $uploadResp?->getSizeBytes();
        }

        // Codex TS r2 medium 9a117f04eb59 — run() passes a whole-run deadline so
        // a slow upload doesn't proceed to createWorkflow past maxWait. submit()
        // passes null (fire-and-forget, no upload cap), so the check is skipped.
        BuilderInternals::throwIfCancelled($cancellation, 'workflow creation');
        if ($deadlineMs !== null && BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError('Upload completed but maxWait elapsed before workflow could be created.');
        }

        // Best-effort probe-before-create: for a VIDEO upload that went
        // multipart, let the server see the codec + duration before
        // createWorkflow so it admits the parallel split. Never-bounce — a
        // give-up just proceeds. The wait is CAPPED to the remaining maxWait
        // budget so a slow probe cannot push createWorkflow past the deadline.
        \assert($this->client !== null);
        $this->client->maybeWaitForVideoProbe(
            $fileId,
            $probeBeforeCreate ?? true,
            $this->input->compressMediaHint() === 'video',
            $uploadSizeBytes,
            BuilderInternals::cappedProbeTimeoutMs($probeTimeoutMs, $deadlineMs),
            $cancellation,
        );
        // A cancel arriving during the FINAL successful probe request must not
        // still create the workflow (maybeWaitForVideoProbe returns landed
        // without a final cancel re-check), so check here BEFORE createWorkflow.
        BuilderInternals::throwIfCancelled($cancellation, 'workflow creation');
        // RE-CHECK the deadline AFTER the probe wait (it consumes time).
        if ($deadlineMs !== null && BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError('Probe wait completed but maxWait elapsed before workflow could be created.');
        }

        // 2. Create the workflow from the lowered payload (callback_url built
        // into the payload at construction when a webhook is given).
        $payload = $this->toWorkflowPayload($fileId, $webhook);

        // Guaranteed non-null by the no_client guard in run()/submit() before
        // this private helper is reached.
        \assert($this->client !== null);

        return $this->client->createWorkflow($payload);
    }

    private function withStep(RecipeStep $step): self
    {
        return new self(
            $this->input,
            $this->key,
            [...$this->steps, $step],
            $this->presetDefaults,
            $this->scopedPresetDefaults,
            $this->client,
        );
    }

    private function lowerStep(RecipeStep $step, int $stepIndex): OperationDef
    {
        // The internal `output` step lowers to a `compress`/`convert` wire op per
        // the route projection (it owns its own type + options resolution + gating).
        if ($step->opType === 'output') {
            return $this->lowerOutputStep($step, $stepIndex);
        }

        $options = $step->opType === 'compress'
            ? $this->lowerCompressOptions($step->options, $stepIndex)
            : $step->options;

        // Empty options omit the `options` wire key entirely, so PHP (null →
        // absent) and TS (undefined → absent) serialise byte-identically — an
        // empty PHP array would otherwise emit `[]` where TS emits `{}`.
        return new OperationDef($step->opType, $options === [] ? null : $options);
    }

    /**
     * Lower an `output` step to its route's wire op. Resolves the route from the
     * (chain-folded) input format token + the requested `output_format`, then
     * emits `compress` (same_format) or `convert` (format_change) carrying only the
     * route-honored options. A planned option (e.g. `lossless`), an option not
     * honored on the resolved route (e.g. `progressive` on a format-change), a
     * planned per-value (e.g. `metadata: 'keep'`), or an unrepresentable route all
     * throw a typed {@see GislConfigError} BEFORE upload. Resize (`width`/`height`/
     * `fit`) is input-keyed (raster only) and rides whichever op the route selects.
     * Mirrors the TS `Recipe::lowerOutputStep`.
     */
    private function lowerOutputStep(RecipeStep $step, int $stepIndex): OperationDef
    {
        $requested = \is_string($step->options['output_format'] ?? null)
            ? $step->options['output_format']
            : null;
        $inputToken = $this->outputInputToken($stepIndex);

        if ($inputToken === null) {
            // Undetectable input (bare upload id / hint-less resource) → the route
            // can't be resolved. Only the legacy compress facade for a
            // facade-managed output (webp) + quality is expressible without knowing
            // the input; anything else (resize, a same-format optimise, a
            // non-facade target) needs a detectable input. Mirrors
            // lowerCompressOptions' media_unknown fail-fast.
            if ($requested !== null && \in_array($requested, ImageOutputRoutes::FACADE_MANAGED_OUTPUTS, true)) {
                $facade = ['output_format' => $requested];
                foreach ($step->options as $key => $value) {
                    if ($key === 'output_format' || $value === null) {
                        continue;
                    }
                    if ($key !== 'quality') {
                        throw new GislConfigError(
                            "output(): '{$key}' needs a detectable input format to route; reference the file by "
                            . 'a path with an extension (or a resource with a filename/contentType hint) rather than a bare upload id.',
                            reason: 'media_unknown',
                            conflictingFields: [$key],
                        );
                    }
                    $facade[$key] = $value;
                }
                return new OperationDef('compress', $facade);
            }
            throw new GislConfigError(
                'output() needs a detectable input format to resolve the route (same-format optimise vs '
                . 'format-change transcode); reference the file by a path with an extension, or a resource with '
                . 'a filename/contentType hint, rather than a bare upload id.',
                reason: 'media_unknown',
                conflictingFields: ['output_format'],
            );
        }

        $resolved = ImageOutputRoutes::resolveOutputRoute($inputToken, $requested);
        if ($resolved === null) {
            $what = $requested === null ? 'this output' : "'{$requested}'";
            throw new GislConfigError(
                "output(): cannot produce {$what} from a '{$inputToken}' input — no such image Output route.",
                reason: 'unsupported_route',
                conflictingFields: ['output_format'],
            );
        }

        $wireOptions = ['output_format' => $resolved['outputFormatWire']];
        foreach ($step->options as $key => $value) {
            if ($key === 'output_format' || $value === null) {
                continue;
            }
            if (isset($resolved['planned'][$key])) {
                throw new GislConfigError(
                    "output(): '{$key}' is advertised but not available yet on the {$resolved['route']} route "
                    . "for '{$resolved['inputToken']}' images (planned). It will work once stable-flipped.",
                    reason: 'feature_not_available',
                    conflictingFields: [$key],
                );
            }
            if (!isset($resolved['honored'][$key])) {
                $target = $requested ?? $resolved['inputToken'];
                throw new GislConfigError(
                    "output(): '{$key}' is not honored on the {$resolved['route']} route "
                    . "({$resolved['inputToken']} → {$target}). "
                    . 'Check it applies to this format/route combination.',
                    reason: 'option_not_on_route',
                    conflictingFields: [$key],
                );
            }
            if (ImageOutputRoutes::isPlannedValue($resolved['inputToken'], $key, $value)) {
                $shown = ImageOutputRoutes::stringifyForMessage($value);
                throw new GislConfigError(
                    "output(): '{$key}: {$shown}' is advertised but not available yet (planned).",
                    reason: 'feature_not_available',
                    conflictingFields: [$key],
                );
            }
            $wireOptions[$key] = $value;
        }
        return new OperationDef($resolved['sourceOp'], $wireOptions);
    }

    /**
     * The input format token an `output` step at `$uptoIndex` operates on — the
     * original input's token, FOLDED through preceding `convert`/`output` steps
     * that change the format (mirrors {@see effectiveCompressMedia()}). Null when
     * the input media is not inferable (a bare upload id / hint-less resource).
     * Mirrors the TS `Recipe::outputInputToken`.
     */
    private function outputInputToken(?int $uptoIndex = null): ?string
    {
        $token = $this->inputFormatToken();
        if ($uptoIndex === null) {
            return $token;
        }
        for ($i = 0; $i < $uptoIndex; $i++) {
            $prior = $this->steps[$i];
            if ($prior->opType === 'convert' || $prior->opType === 'output') {
                $fmt = $prior->options['output_format'] ?? null;
                // A same-format `output` step carries no output_format (or
                // 'original') → token unchanged; a format target (e.g. 'webp')
                // advances it.
                if (\is_string($fmt)) {
                    $token = ImageOutputRoutes::tokenForPath("f.{$fmt}") ?? $token;
                }
            }
        }
        return $token;
    }

    /**
     * The original input's image format token (path ext / resource
     * contentType / filename). Mirrors the TS `Recipe::inputFormatToken` (PHP's
     * resource hints stand in for the TS Blob `.type`/`.name`). A bare upload id
     * is undetectable → null.
     */
    private function inputFormatToken(): ?string
    {
        if ($this->input->kind === FileInput::KIND_PATH && $this->input->path !== null) {
            return ImageOutputRoutes::tokenForPath($this->input->path);
        }
        if ($this->input->kind === FileInput::KIND_RESOURCE) {
            if ($this->input->contentType !== null) {
                $fromType = ImageOutputRoutes::tokenForMime($this->input->contentType);
                if ($fromType !== null) {
                    return $fromType;
                }
            }
            if ($this->input->filename !== null) {
                return ImageOutputRoutes::tokenForPath($this->input->filename);
            }
        }
        return null; // uploadId / hint-less resource — undetectable
    }

    /**
     * Resolve a compress step's `optimize` selector to concrete wire options
     * via the shared {@see PresetResolver}, keyed off the input's media class
     * (extension-derived, offline-deterministic). When the media cannot be
     * inferred locally (a resource handle or bare upload id), preset resolution
     * is impossible here — emit empty options and let the server apply its
     * media defaults. This path is not reached by path-based inputs.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function lowerCompressOptions(array $options, int $uptoIndex): array
    {
        // Mirror the op-first resolver precedence
        // ({@see \Gisl\Sdk\Ergonomic\OperationBuilder::resolve()}): optimize =
        // preset layer, presetOverrides = callPresetOverride layer, the rest =
        // explicit layer.
        $explicit = $options;
        $optimize = $explicit['optimize'] ?? null;
        unset($explicit['optimize']);
        \assert($optimize === null || $optimize instanceof OptimizeFor);
        $presetOverrides = $explicit['presetOverrides'] ?? null;
        unset($explicit['presetOverrides']);

        $media = $this->effectiveCompressMedia($uptoIndex);
        if ($media === null) {
            // Cannot infer a media class (a resource handle or bare upload id
            // carries no extension) → preset resolution is impossible. Fail
            // FAST rather than silently dropping an explicit `optimize` choice;
            // a bare `compress()` is still fine (server applies defaults). When
            // no optimize is set, pass any explicit options through verbatim
            // (mirrors the op-first media-undefined passthrough).
            if ($optimize !== null) {
                throw new GislConfigError(
                    "compress(optimize: {$optimize->value}) needs a media type to resolve the preset, but the "
                    . 'input has no inferable media (a pre-uploaded file id or stream carries no extension). '
                    . 'Use a path with a file extension, or call compress() without optimize.',
                    reason: 'media_unknown',
                    conflictingFields: ['optimize'],
                );
            }
            // presetOverrides override a resolved preset; with no media there is
            // no preset to override, so fail fast rather than silently dropping.
            if ($presetOverrides !== null) {
                throw new GislConfigError(
                    'compress(presetOverrides) needs a media type to resolve the preset to override, but the '
                    . 'input has no inferable media (a pre-uploaded file id or stream carries no extension). '
                    . 'Use a path with a file extension.',
                    reason: 'media_unknown',
                    conflictingFields: ['presetOverrides'],
                );
            }
            return $explicit;
        }

        $resolved = PresetResolver::resolveCompress(
            media: $media,
            presetDefaults: $this->presetDefaults,
            scopedDefaults: $this->scopedPresetDefaults,
            presetOverrides: OperationBuilder::normalisePresetOverrides($presetOverrides),
            optimize: $optimize,
            explicitOptions: $explicit,
            audioLossless: $media === 'audio' ? $this->effectiveAudioLossless($uptoIndex) : null,
        );

        return $resolved['wireOptions'];
    }

    /**
     * The media class a `compress` step at `$uptoIndex` actually operates on. FOLD the
     * preceding `convert` steps: each `convert(output_format)` changes the media the next
     * step sees (56N4chXY / N8eESzQN). With no preceding convert, falls back to the
     * original input's media. Mirrors the TS Recipe.compressMediaHint(uptoIndex).
     */
    private function effectiveCompressMedia(int $uptoIndex): ?string
    {
        $media = $this->input->compressMediaHint();
        for ($i = 0; $i < $uptoIndex; $i++) {
            $step = $this->steps[$i];
            if ($step->opType === 'convert') {
                $fmt = $step->options['output_format'] ?? null;
                if (\is_string($fmt)) {
                    $media = self::resolveConvertOutputMedia($media, $fmt);
                }
            }
        }

        return $media;
    }

    /**
     * Whether the media a `compress` step at `$uptoIndex` operates on is lossless audio —
     * from the most recent preceding `convert` target (flac/wav) when there is one, else
     * the original input. Mirrors the TS Recipe.compressAudioLossless(uptoIndex).
     */
    private function effectiveAudioLossless(int $uptoIndex): bool
    {
        for ($i = $uptoIndex - 1; $i >= 0; $i--) {
            $step = $this->steps[$i];
            if ($step->opType === 'convert') {
                $fmt = $step->options['output_format'] ?? null;

                return \is_string($fmt) ? OperationBuilder::detectAudioLossless("f.{$fmt}") : false;
            }
        }

        return $this->input->compressAudioLosslessHint();
    }

    /**
     * Media of a `convert` step's output given its source media. Reuses the extension
     * classifier on a synthetic `f.<format>`, with ONE guard: a video source converted to
     * `ogg` stays video (an OGG *video* container — `ogg` otherwise lands in the audio
     * extension list, mis-resolving a video output to audio). video->`gif` is left as the
     * classifier's `image` result. Per the 56N4chXY plan review. Mirrors the TS
     * _resolveConvertOutputMedia.
     */
    private static function resolveConvertOutputMedia(?string $source, string $outputFormat): ?string
    {
        if ($source === 'video' && \strtolower($outputFormat) === 'ogg') {
            return 'video';
        }

        return OperationBuilder::detectCompressMedia("f.{$outputFormat}");
    }

    /**
     * Coerce an `optimize` argument to an {@see OptimizeFor} case. Mirrors the
     * operation-first builder's coercion so a string value validates the same
     * way and fails early with the same {@see GislConfigError}.
     */
    /** @internal Shared with {@see MergedRecipe} for post-combine `compress()`. */
    public static function coerceOptimize(OptimizeFor|string|null $raw): ?OptimizeFor
    {
        if ($raw === null || $raw instanceof OptimizeFor) {
            return $raw;
        }

        $level = OptimizeFor::tryFrom($raw);
        if ($level === null) {
            $allowed = \implode(', ', \array_map(static fn (OptimizeFor $o): string => $o->value, OptimizeFor::cases()));
            throw new GislConfigError(
                "compress 'optimize' must be one of {$allowed}; got '{$raw}'.",
                reason: 'invalid_optimize',
                conflictingFields: ['optimize'],
            );
        }
        return $level;
    }
}
