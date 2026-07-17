<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislPerInputOptionsNotSupportedError;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\Errors\GislUndeclaredAssetError;
use Gisl\Sdk\Errors\GislUnusedAssetError;
use Gisl\Sdk\Cancellation;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\Http\UploadSource;
use Gisl\Sdk\JobDefinitionPayload;
use Gisl\Sdk\OperationDef;
use Gisl\Sdk\Sources;
use Gisl\Sdk\UploadOptions;
use Gisl\Sdk\WorkflowCreatePayload;

/**
 * Merge-compose layer for the SDK ergonomic surface (PHP P3 / dxIeLVbP).
 * Pattern-port of the TS reference at `packages/typescript/src/merge.ts`.
 *
 *     $client->merge([$a, $b, $c], new MergeOptions(transition: 'fade'))
 *         ->sequence([
 *             Merge::asset($a),
 *             Merge::clip($b, new ClipOptions(transition: 'crossfade', crossfadeDuration: 1.5)),
 *             Merge::asset($c),
 *         ])
 *         ->run(new RunOptions(maxWait: '5m'));
 *
 * Separates WHAT (declared asset set) from ORDER (the timeline). Each
 * unique declared asset uploads ONCE per run even when referenced many
 * times in the sequence; without a sequence, declared order is used with
 * no per-input options.
 *
 * Wire-truth boundaries (lowering.md §sequences, mirrored from TS):
 *  - Image merges: NO `per_input_options` (image-merge clips with any
 *    options raise {@see GislPerInputOptionsNotSupportedError} at plan
 *    time); the merge-level `transition` applies between every join.
 *  - Audio merges: per-input `transition`/`crossfade_duration`/
 *    `gap_duration`; merge-level may also carry `gap_duration`.
 *  - Video merges: per-input `transition`/`crossfade_duration`;
 *    merge-level DROPS `gap_duration` (TS R2 medium ab2422e56ea0).
 *
 * Local validation runs BEFORE any upload (`planSequence()`). The three
 * sequence-level failures each raise a dedicated subclass of
 * {@see GislConfigError}, mirroring the TS reference one-for-one:
 *  - undeclared sequence ref → {@see GislUndeclaredAssetError}
 *    (carries `$assetId` + `$declaredAssets`);
 *  - declared-but-unsequenced asset → {@see GislUnusedAssetError}
 *    (carries `$unusedAssets`), unless `allowUnusedAssets` is set;
 *  - per-input options on an image merge →
 *    {@see GislPerInputOptionsNotSupportedError} (carries `$mediaKind`).
 * Catching `GislConfigError` still catches all three. Other plan-time
 * guards (input bounds, invalid target-size, image output_type) remain
 * bare {@see GislConfigError}, matching the TS reference.
 *
 * Differences from the TS reference (all PHP-idiomatic):
 *  - No `AbortSignal` — same constraint as {@see OperationBuilder}: only
 *    the wall-clock `maxWait` deadline aborts.
 *  - No `Blob` analogue — assets are filesystem paths or pre-uploaded
 *    file_ids (`HandleAsset`). Bytes-input is out of scope for P3.
 *  - Constructor takes `array $assets` (not variadic) — matches the
 *    rest of the SDK (`compress($input, $options)`) and avoids the TS
 *    reference's runtime-sniff for "is the last arg an options object".
 */
final class MergeBuilder
{
    /** @var list<ClipEntry|Asset>|null */
    private ?array $sequenceEntries = null;

    /**
     * @param list<Asset> $assets Declared asset set. Each unique asset
     *                            uploads once per run.
     */
    public function __construct(
        private readonly GislClient $client,
        private readonly array $assets,
        private readonly MergeOptions $opOptions,
    ) {
    }

    /**
     * Pin the merge play order. Each entry must reference an asset that
     * was declared in the parent `merge(...)` call. Repeats are allowed
     * and deduped on upload (one upload per unique declared asset).
     *
     * @param list<ClipEntry|Asset> $entries
     */
    public function sequence(array $entries): self
    {
        $this->sequenceEntries = $entries;
        return $this;
    }

    public function run(RunOptions $options): Result
    {
        $deadlineMs = BuilderInternals::nowMs() + MaxWait::parse($options->maxWait);
        $onProgress = BuilderInternals::callableOrNull($options->onProgress, 'RunOptions::$onProgress');

        // 1. Validate locally BEFORE any upload.
        $plan = $this->planSequence();

        // 2. Upload each unique asset exactly ONCE. Pass the deadline + the
        // cancellation token so the upload loop can abort mid-batch on a slow
        // connection or a caller cancel.
        /** @var list<array{fileId: string, isVideo: bool, sizeBytes: int|null}> $probeTargets */
        $probeTargets = [];
        $uploadedByAssetId = $this->uploadUniqueAssets($plan, $onProgress, $deadlineMs, $options->cancellation, $probeTargets);

        BuilderInternals::throwIfCancelled($options->cancellation, 'merge workflow creation');
        if (BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError(
                'Upload(s) completed but maxWait elapsed before merge workflow could be created.',
            );
        }

        // Best-effort probe-before-create for the multipart-video inputs
        // (sequential with a shared budget; never-bounce). The total budget is
        // capped to the remaining maxWait so the waits cannot push
        // createWorkflow past the caller's deadline.
        BuilderInternals::waitForVideoProbes($this->client, $probeTargets, $options->probeBeforeCreate, $options->probeTimeoutMs, $options->cancellation, $deadlineMs);
        // A cancel arriving during a FINAL successful probe request must not
        // still create the workflow (the probe waits return landed without a
        // final cancel re-check), so check here BEFORE createWorkflow.
        BuilderInternals::throwIfCancelled($options->cancellation, 'merge workflow creation');
        // RE-CHECK the deadline AFTER the probe waits (they consume time).
        if (BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError(
                'Probe wait completed but maxWait elapsed before merge workflow could be created.',
            );
        }

        // 3. Build + create the merge workflow.
        $payload = $this->buildPayload($plan, $uploadedByAssetId, callbackUrl: null);
        $created = $this->client->createWorkflow($payload);

        // 4. Wait to terminal status.
        $workflowId = $created->getWorkflowId() ?? '';
        $finalStatus = BuilderInternals::awaitTerminal(
            client: $this->client,
            workflowId: $workflowId,
            deadlineMs: $deadlineMs,
            onProgress: $onProgress,
            useSSE: $options->useSSE,
            pollIntervalMs: $options->pollIntervalMs,
            cancellation: $options->cancellation,
        );

        // 5. Fetch downloads + project.
        BuilderInternals::throwIfCancelled($options->cancellation, 'downloads fetch');
        if (BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError(
                "Merge workflow {$workflowId} reached terminal status but maxWait elapsed before downloads could be fetched.",
                $workflowId,
            );
        }
        $downloads = $this->client->getWorkflowDownloads($workflowId);
        // TDqmkWpX: the maxWait deadline also covers the downloads fetch itself —
        // re-check AFTER the call so a slow getWorkflowDownloads cannot return a
        // success past the advertised whole-run deadline.
        if (BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError(
                "Merge workflow {$workflowId} downloads fetch completed after maxWait elapsed.",
                $workflowId,
            );
        }

        // p0SuJEeK — project ONLY the merge job's output. getWorkflowDownloads
        // returns a download group per terminal job, which now INCLUDES the
        // `passthrough` source jobs (their output is the unchanged upload).
        // Those are plumbing, not the merge deliverable — surfacing them as
        // artifacts would pollute the Result with the raw inputs. The merge
        // job's ref is 'merge' (see buildPayload); source jobs are 'src_N'.
        $mergeDownloads = \array_values(\array_filter(
            $downloads->getDownloads() ?? [],
            static fn ($d): bool => BuilderInternals::coerceString($d->getRef()) === 'merge',
        ));

        return OperationBuilder::projectResult(
            $finalStatus,
            $mergeDownloads,
            $this->opOptionsForResolved($plan->mediaKind),
        );
    }

    public function submit(SubmitOptions $options): Handle
    {
        $plan = $this->planSequence();
        /** @var list<array{fileId: string, isVideo: bool, sizeBytes: int|null}> $probeTargets */
        $probeTargets = [];
        $uploadedByAssetId = $this->uploadUniqueAssets($plan, onProgress: null, deadlineMs: null, cancellation: $options->cancellation, probeTargets: $probeTargets);

        BuilderInternals::throwIfCancelled($options->cancellation, 'merge workflow creation');

        // Best-effort probe-before-create for the multipart-video inputs
        // (sequential with a shared budget; never-bounce).
        BuilderInternals::waitForVideoProbes($this->client, $probeTargets, $options->probeBeforeCreate, $options->probeTimeoutMs, $options->cancellation);

        // A cancel during the final probe wait must not still create (parity
        // with run()).
        BuilderInternals::throwIfCancelled($options->cancellation, 'merge workflow creation');

        $payload = $this->buildPayload($plan, $uploadedByAssetId, callbackUrl: $options->webhook);
        $created = $this->client->createWorkflow($payload);

        return new Handle(
            workflowId: $created->getWorkflowId() ?? '',
            webhookSecret: $created->getWebhookSecret(),
        );
    }

    // ---------------------------------------------------------------------

    /**
     * Resolve declared assets + sequence (or fall back to declared order),
     * dedupe by identity, and run the local validators.
     */
    private function planSequence(): SequencePlan
    {
        // Build the declared-asset map keyed by identity.
        $declared = [];
        foreach ($this->assets as $a) {
            $id = self::assetIdentity($a);
            if (!isset($declared[$id])) {
                $declared[$id] = $a;
            }
        }

        // Use the explicit sequence if set; otherwise the declared order as-is.
        $rawEntries = $this->sequenceEntries ?? array_values($this->assets);

        $mediaKind = $this->inferMediaKind();
        /** @var list<PositionEntry> $positions */
        $positions = [];
        /** @var array<string, true> $refIds */
        $refIds = [];
        foreach ($rawEntries as $entry) {
            $isClip = $entry instanceof ClipEntry;
            $assetRef = $isClip ? $entry->asset : $entry;
            $id = self::assetIdentity($assetRef);
            if (!isset($declared[$id])) {
                throw new GislUndeclaredAssetError($id, array_keys($declared));
            }
            $refIds[$id] = true;
            if ($isClip) {
                $opts = $entry->options;
                if (!$opts->isEmpty() && $mediaKind === 'image') {
                    throw new GislPerInputOptionsNotSupportedError('image');
                }
                $positions[] = new PositionEntry($id, $opts);
            } else {
                $positions[] = new PositionEntry($id, new ClipOptions());
            }
        }

        // Unused-asset check — only when sequence is set AND not bypassed.
        if ($this->sequenceEntries !== null && $this->opOptions->allowUnusedAssets !== true) {
            $unused = array_values(array_diff(array_keys($declared), array_keys($refIds)));
            if (\count($unused) > 0) {
                throw new GislUnusedAssetError($unused);
            }
        }

        // Restrict upload set to sequenced assets when allowUnusedAssets bypassed the check.
        // Mirrors TS R1 medium edb1bb641d81 — saves wasted bandwidth.
        $uploadSet = $this->sequenceEntries === null
            ? $declared
            : array_intersect_key($declared, $refIds);

        // Enforce merge schema input bounds (min_inputs: 2, max_inputs: 10).
        // Counted on positions (repeats included), not unique assets.
        // Mirrors TS R1 medium 5c86b67c979b.
        $positionCount = \count($positions);
        if ($positionCount < 2) {
            throw new GislConfigError(
                "merge requires at least 2 inputs (got {$positionCount}). Declare more assets or check the sequence.",
            );
        }
        if ($positionCount > 10) {
            throw new GislConfigError(
                "merge accepts at most 10 inputs (got {$positionCount}). Reduce the sequence or split the merge.",
            );
        }

        // Validate merge-level options BEFORE upload. Codex R1 medium
        // a93bd61d39a9 — parseSizeString used to fire from buildPayload()
        // AFTER uploadUniqueAssets, so a `targetSize: 'garbage'` typo would
        // burn N uploads before the throw.
        //
        // Gated to video (codex #176 r3 DCJUvvfA) — `target_size_bytes` only
        // crosses the wire for video merges (see wireMergeOptions); for
        // image/audio the field is silently dropped, so validating its string
        // form would reject a merge over a value that never leaves the SDK.
        if ($mediaKind === 'video'
            && $this->opOptions->targetSize !== null
            && \is_string($this->opOptions->targetSize)
        ) {
            self::parseSizeString($this->opOptions->targetSize);
        }

        // Image merges require an `output_type` (per the generated merge schema
        // at `generated/typescript/operations/schemas/merge.yaml` — image kind
        // marks `output_type` required). Codex R1 medium b53ad6d22f53 — without
        // this check, the SDK would upload all assets then receive a server-
        // side validation failure instead of a free local failure.
        if ($mediaKind === 'image'
            && $this->opOptions->output === null
            && $this->opOptions->outputType === null
        ) {
            throw new GislConfigError(
                'image merges require an explicit output_type — set MergeOptions(output: "video"|"gif") or '
                . 'MergeOptions(outputType: ...). The server rejects image merge requests with no output_type.',
            );
        }

        // Preflight every asset BEFORE any upload — a non-seekable/non-readable
        // Merge::resource() or a missing/unreadable path anywhere must fail fast,
        // not after earlier assets have already uploaded (codex VOxtu0RZ-B4).
        foreach ($uploadSet as $asset) {
            if ($asset instanceof ResourceAsset) {
                UploadSource::assertUploadableStream($asset->resource);
            } elseif ($asset instanceof PathAsset) {
                UploadSource::fromPath($asset->path);
            }
        }

        return new SequencePlan($mediaKind, $positions, $uploadSet);
    }

    /**
     * @return "video"|"audio"|"image"
     */
    private function inferMediaKind(): string
    {
        if ($this->opOptions->mediaKind !== null) {
            return $this->opOptions->mediaKind;
        }
        $first = $this->assets[0] ?? null;
        if ($first instanceof PathAsset) {
            $lower = strtolower($first->path);
            if (preg_match('/\.(jpe?g|png|webp|avif|gif|heic|tiff?)$/', $lower) === 1) {
                return 'image';
            }
            if (preg_match('/\.(mp3|wav|flac|aac|ogg|m4a)$/', $lower) === 1) {
                return 'audio';
            }
            return 'video';
        }
        return 'video';
    }

    /**
     * Upload each unique asset in `$plan->uniqueAssets` exactly once.
     * Handles short-circuit to their pre-uploaded `file_id`. Paths route
     * through `GislClient::uploadFile()`. Checks `$deadlineMs` between
     * uploads when supplied (TS R1 medium 797b4113431f).
     *
     * @param \Closure(ProgressEvent): void|null $onProgress
     * @param list<array{fileId: string, isVideo: bool, sizeBytes: int|null}>|null $probeTargets
     *        Out-parameter: each freshly-uploaded asset records its probe-gate
     *        inputs here. Per-asset video detection — merge's inferMediaKind is
     *        the OUTPUT media, not each input's. A HandleAsset carries no local
     *        mime/size, so it is never probed; a ResourceAsset carries no
     *        filename/contentType signal, so it is treated as non-video.
     * @return array<string, string>  Asset-id → uploaded `file_id`.
     */
    private function uploadUniqueAssets(
        SequencePlan $plan,
        ?\Closure $onProgress,
        ?int $deadlineMs,
        ?Cancellation $cancellation = null,
        ?array &$probeTargets = null,
    ): array {
        $uploaded = [];
        $total = \count($plan->uniqueAssets);
        $done = 0;
        foreach ($plan->uniqueAssets as $id => $asset) {
            BuilderInternals::throwIfCancelled($cancellation, "merge upload (after {$done} of {$total} assets)");
            if ($deadlineMs !== null && BuilderInternals::nowMs() >= $deadlineMs) {
                throw new GislTimeoutError(
                    "maxWait elapsed mid-upload (after {$done} of {$total} merge assets).",
                );
            }
            if ($asset instanceof HandleAsset) {
                $uploaded[$id] = $asset->fileId;
                $done++;
                continue;
            }
            $uploadOpts = null;
            if ($onProgress !== null) {
                $uploadOpts = new UploadOptions(
                    onProgress: static function (int $uploadedBytes, int $totalBytes) use ($onProgress): void {
                        $onProgress(new UploadProgressEvent($uploadedBytes, $totalBytes));
                    },
                );
            }
            // A path or a seekable stream resource (VOxtu0RZ-B4) uploads now.
            if ($asset instanceof ResourceAsset) {
                \assert(\is_resource($asset->resource));
                $uploadTarget = $asset->resource;
                // A ResourceAsset carries no filename/contentType signal, so the
                // media class cannot be inferred — treat it as non-video (skip).
                $isVideo = false;
            } else {
                /** @var PathAsset $asset */
                $uploadTarget = $asset->path;
                $isVideo = OperationBuilder::detectCompressMedia($asset->path) === 'video';
            }
            $resp = $this->client->uploadFile($uploadTarget, $uploadOpts);
            $uploaded[$id] = $resp->getFileId() ?? '';
            if ($probeTargets !== null) {
                $probeTargets[] = [
                    'fileId' => $resp->getFileId() ?? '',
                    'isVideo' => $isVideo,
                    'sizeBytes' => $resp->getSizeBytes(),
                ];
            }
            $done++;
        }
        return $uploaded;
    }

    /**
     * Build the merge `WorkflowCreatePayload`. Emits a single
     * multi-input `JobDefinitionPayload`:
     *  - `inputs[]` carries one entry per SEQUENCE POSITION (repeats
     *    included) with `source: {type: upload, file_id}` and the
     *    media-kind-appropriate `per_input_options`.
     *  - `operations[0]` is `{type: merge, options: <merge-level opts>}`.
     *
     * @param array<string, string> $uploadedByAssetId
     */
    private function buildPayload(
        SequencePlan $plan,
        array $uploadedByAssetId,
        ?string $callbackUrl,
    ): WorkflowCreatePayload {
        // p0SuJEeK — the API rejects upload-direct multi-input
        // (`MultiInputSource` excludes the `upload` leaf: "use type=job_output").
        // So each uploaded asset is wrapped in its OWN single-input `passthrough`
        // source job, and the merge job references those via `job_output` — the
        // shape the v2.35.0 `v2_merge_two_uploads` example prescribes. One source
        // job per UNIQUE asset (in first-seen position order); a repeated asset
        // re-uses its src job. `passthrough` is a lossless inert op (it does NOT
        // get the implicit compress an empty `operations: []` job would).
        $srcIdByAsset = [];
        $sourceJobs = [];
        foreach ($plan->positions as $pos) {
            if (isset($srcIdByAsset[$pos->assetId])) {
                continue;
            }
            if (!isset($uploadedByAssetId[$pos->assetId])) {
                // Defensive — planSequence should have rejected this.
                throw new \LogicException(
                    "Asset '{$pos->assetId}' was never uploaded — internal MergeBuilder bug.",
                );
            }
            $srcId = 'src_' . \count($sourceJobs);
            $srcIdByAsset[$pos->assetId] = $srcId;
            $sourceJobs[] = new JobDefinitionPayload(
                operations: [new OperationDef(type: 'passthrough')],
                id: $srcId,
                source: Sources::upload($uploadedByAssetId[$pos->assetId]),
            );
        }

        $inputs = [];
        foreach ($plan->positions as $pos) {
            // Defensive — srcIdByAsset was populated for every position's asset above.
            if (!isset($srcIdByAsset[$pos->assetId])) {
                throw new \LogicException(
                    "Asset '{$pos->assetId}' has no source job — internal MergeBuilder bug.",
                );
            }
            $input = ['source' => Sources::jobOutput($srcIdByAsset[$pos->assetId])];
            // Image merges never emit per_input_options (planSequence already
            // rejects opts on image-merge clips). Video/audio emit a
            // narrowed projection by media kind.
            if ($plan->mediaKind !== 'image') {
                $wireOpts = self::wirePerInputOptions($pos->options, $plan->mediaKind);
                if (\count($wireOpts) > 0) {
                    $input['per_input_options'] = $wireOpts;
                }
            }
            $inputs[] = $input;
        }

        $mergeOpts = self::wireMergeOptions($this->opOptions, $plan->mediaKind);

        $mergeJob = new JobDefinitionPayload(
            operations: [new OperationDef(type: 'merge', options: $mergeOpts)],
            id: 'merge',
            inputs: $inputs,
        );

        return new WorkflowCreatePayload(
            jobs: [...$sourceJobs, $mergeJob],
            callbackUrl: $callbackUrl,
        );
    }

    /**
     * Strip SDK-only fields before exposing on `resolvedOptions.applied`.
     * Codex R1 medium 4b583705c2f9 — drop fields the wire silently
     * filtered (e.g. `gap_duration` on video/image), so callers don't see
     * a "the option was applied" report for an option that never crossed
     * the wire.
     *
     * @param "video"|"audio"|"image" $mediaKind
     * @return array<string, mixed>
     */
    private function opOptionsForResolved(string $mediaKind): array
    {
        // Mirror `wireMergeOptions`'s per-media allowlist so the caller's
        // "applied" view tracks what actually crossed the wire. Codex R1
        // medium 4b583705c2f9 + R2 medium a5aa664e6c74.
        $out = [];
        if ($this->opOptions->output !== null) {
            $out['output'] = $this->opOptions->output;
        }
        if ($this->opOptions->outputType !== null) {
            $out['outputType'] = $this->opOptions->outputType;
        }
        if ($this->opOptions->transition !== null) {
            $out['transition'] = $this->opOptions->transition;
        }

        if ($mediaKind === 'video' || $mediaKind === 'audio') {
            if ($this->opOptions->crossfadeDuration !== null) {
                $out['crossfadeDuration'] = $this->opOptions->crossfadeDuration;
            }
            if ($this->opOptions->normalizeAudio !== null) {
                $out['normalizeAudio'] = $this->opOptions->normalizeAudio;
            }
        }

        if ($mediaKind === 'audio' && $this->opOptions->gapDuration !== null) {
            $out['gapDuration'] = $this->opOptions->gapDuration;
        }

        if ($mediaKind === 'video') {
            if ($this->opOptions->reEncodeMode !== null) {
                $out['reEncodeMode'] = $this->opOptions->reEncodeMode;
            }
            if ($this->opOptions->codec !== null) {
                $out['codec'] = $this->opOptions->codec;
            }
            if ($this->opOptions->crf !== null) {
                $out['crf'] = $this->opOptions->crf;
            }
            if ($this->opOptions->preset !== null) {
                $out['preset'] = $this->opOptions->preset;
            }
            if ($this->opOptions->targetResolution !== null) {
                $out['targetResolution'] = $this->opOptions->targetResolution;
            }
            if ($this->opOptions->targetSize !== null) {
                $out['targetSize'] = $this->opOptions->targetSize;
            }
        }

        if ($mediaKind === 'image') {
            if ($this->opOptions->transitionDuration !== null) {
                $out['transitionDuration'] = $this->opOptions->transitionDuration;
            }
            if ($this->opOptions->fps !== null) {
                $out['fps'] = $this->opOptions->fps;
            }
            if ($this->opOptions->durationPerImage !== null) {
                $out['durationPerImage'] = $this->opOptions->durationPerImage;
            }
            if ($this->opOptions->delay !== null) {
                $out['delay'] = $this->opOptions->delay;
            }
            if ($this->opOptions->loopCount !== null) {
                $out['loopCount'] = $this->opOptions->loopCount;
            }
            if ($this->opOptions->videoFormat !== null) {
                $out['videoFormat'] = $this->opOptions->videoFormat;
            }
        }

        return $out;
    }

    /**
     * Asset identity for dedupe. Handles use their fileId; paths use the
     * EXACT caller-provided string (no trim, no trailing-slash strip);
     * resources use their stream id (referential identity — the SAME handle
     * twice collapses to one upload, two distinct handles do not).
     * Mirrors TS R2 medium bb500566a683 — exact identity ensures the upload
     * call matches the dedupe identity character-for-character.
     */
    private static function assetIdentity(Asset $a): string
    {
        if ($a instanceof HandleAsset) {
            return "handle:{$a->fileId}";
        }
        if ($a instanceof PathAsset) {
            return "path:{$a->path}";
        }
        if ($a instanceof ResourceAsset) {
            return 'resource:' . \get_resource_id($a->resource);
        }
        // Unreachable — the Asset interface is sealed via the Merge factory.
        throw new \LogicException('Unknown Asset variant: ' . get_class($a));
    }

    /**
     * Project the merge-level option bag into the wire shape per media
     * kind. `gap_duration` is audio-only at the merge level (TS R2 medium
     * ab2422e56ea0).
     *
     * @param "video"|"audio"|"image" $mediaKind
     * @return array<string, mixed>
     */
    /**
     * @internal Shared with {@see \Gisl\Sdk\FileFirst\MergedRecipe} so the fluent
     *           `files()->merge()` lowers merge-level options identically to the
     *           operation-first `client->merge()`. Pure function — no state.
     *
     * @return array<string, mixed>
     */
    public static function wireMergeOptions(MergeOptions $opts, string $mediaKind): array
    {
        // Per-media wire allowlists (mirrors generated/typescript/operations/merge.ts):
        //   video: output_type, transition, crossfade_duration, normalize_audio,
        //          re_encode_mode, codec, crf, preset, target_resolution,
        //          target_size_bytes, encoding_mode
        //   audio: output_type, transition, crossfade_duration, gap_duration, normalize_audio
        //   image: output_type, transition, transition_duration, fps,
        //          duration_per_image, delay, loop_count, video_format
        // Codex R2 medium a5aa664e6c74 — without per-media gating, callers
        // hit a server-side 422 only AFTER paying for uploads. Drop the
        // disallowed fields locally instead.
        $out = [];
        if ($opts->output !== null) {
            $out['output_type'] = $opts->output;
        }
        if ($opts->outputType !== null) {
            $out['output_type'] = $opts->outputType;
        }
        if ($opts->transition !== null) {
            $out['transition'] = $opts->transition;
        }

        if ($mediaKind === 'video' || $mediaKind === 'audio') {
            if ($opts->crossfadeDuration !== null) {
                $out['crossfade_duration'] = $opts->crossfadeDuration;
            }
            if ($opts->normalizeAudio !== null) {
                $out['normalize_audio'] = $opts->normalizeAudio;
            }
        }

        if ($mediaKind === 'audio' && $opts->gapDuration !== null) {
            $out['gap_duration'] = $opts->gapDuration;
        }

        if ($mediaKind === 'video') {
            if ($opts->reEncodeMode !== null) {
                $out['re_encode_mode'] = $opts->reEncodeMode;
            }
            if ($opts->codec !== null) {
                $out['codec'] = $opts->codec;
            }
            if ($opts->crf !== null) {
                $out['crf'] = $opts->crf;
            }
            if ($opts->preset !== null) {
                $out['preset'] = $opts->preset;
            }
            if ($opts->targetResolution !== null) {
                $out['target_resolution'] = $opts->targetResolution;
            }
            if ($opts->targetSize !== null) {
                $out['target_size_bytes'] = \is_int($opts->targetSize)
                    ? $opts->targetSize
                    : self::parseSizeString($opts->targetSize);
                $out['encoding_mode'] = 'target_size';
            }
        }

        if ($mediaKind === 'image') {
            if ($opts->transitionDuration !== null) {
                $out['transition_duration'] = $opts->transitionDuration;
            }
            if ($opts->fps !== null) {
                $out['fps'] = $opts->fps;
            }
            if ($opts->durationPerImage !== null) {
                $out['duration_per_image'] = $opts->durationPerImage;
            }
            if ($opts->delay !== null) {
                $out['delay'] = $opts->delay;
            }
            if ($opts->loopCount !== null) {
                $out['loop_count'] = $opts->loopCount;
            }
            if ($opts->videoFormat !== null) {
                $out['video_format'] = $opts->videoFormat;
            }
        }

        return $out;
    }

    /**
     * Project a {@see ClipOptions} into the per-input wire shape.
     * `gap_duration` is audio-only on per-input too (TS R1 medium 128404fa16a9).
     *
     * @param "video"|"audio"|"image" $mediaKind  (image is excluded upstream)
     * @return array<string, mixed>
     */
    private static function wirePerInputOptions(ClipOptions $opts, string $mediaKind): array
    {
        $out = [];
        if ($opts->transition !== null) {
            $out['transition'] = $opts->transition;
        }
        if ($opts->crossfadeDuration !== null) {
            $out['crossfade_duration'] = $opts->crossfadeDuration;
        }
        if ($opts->gapDuration !== null && $mediaKind === 'audio') {
            $out['gap_duration'] = $opts->gapDuration;
        }
        return $out;
    }

    private static function parseSizeString(string $s): int
    {
        $trimmed = trim($s);
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(KB|MB|GB|B)?$/i', $trimmed, $m) !== 1) {
            throw new GislConfigError("Invalid targetSize string '{$s}' — expected '<num>[B|KB|MB|GB]'.");
        }
        $n = (float) $m[1];
        $unit = strtoupper($m[2] ?? 'B');
        return match ($unit) {
            'B' => (int) round($n),
            'KB' => (int) round($n * 1_000),
            'MB' => (int) round($n * 1_000_000),
            'GB' => (int) round($n * 1_000_000_000),
            default => throw new GislConfigError("Unknown size unit '{$unit}'."),
        };
    }
}
