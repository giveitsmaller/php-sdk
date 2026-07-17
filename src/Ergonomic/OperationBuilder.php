<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

use Gisl\Generated\OpenApi\Model\OperationDownload;
use Gisl\Generated\OpenApi\Model\WorkflowStatusResponse;
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
 * Operation-builder layer for the SDK ergonomic surface (PHP P2 /
 * 7QXkzoIi). Pattern-port of the TS reference at
 * `packages/typescript/src/builder.ts`.
 *
 * Composes a {@see GislClient} — does NOT subclass. Each
 * `$ergonomicClient->compress($input, $options)` (and the four sibling
 * factories on {@see \Gisl\Sdk\GislErgonomicClient}) returns an
 * `OperationBuilder`; calling `->run(RunOptions)` orchestrates the full
 * upload → createWorkflow → wait → getWorkflowDownloads → flat `Result`
 * projection chain. `->submit(SubmitOptions)` skips the wait + downloads
 * steps and returns a lighter {@see Handle} instead.
 *
 * Wire-truth boundaries:
 *  - `Result::$artifacts` is a FLAT projection of `WorkflowDownloadResponse`
 *    (`downloads[].files[]`) with `url` aliasing `downloadUrl`. Every other
 *    field is verbatim from `OperationDownload`.
 *  - `RunOptions::$onProgress` callbacks receive a **SDK-SYNTHESISED
 *    discriminated union**: `UploadProgressEvent` from
 *    `UploadOptions::onProgress` (byte-counter only, no wire field for it)
 *    and `ProcessingProgressEvent` projecting `SseOperationProgressData`
 *    verbatim. The `phase` discriminator is SDK-added; the wire does NOT
 *    carry it.
 *
 * The await-terminal trio (`consumeSseToTerminal`/`pollToTerminal`/
 * `awaitTerminal`) and the pure helpers (`nowMs`, `coerceString`,
 * `formatIso8601Utc`, etc.) live on {@see BuilderInternals} so
 * {@see MergeBuilder} can reuse them without duplication. The TS reference
 * mirrors this by `export`ing the same helpers from `builder.ts` for
 * `merge.ts` to consume.
 *
 * Differences from the TS reference, all PHP-idiomatic:
 *  - `AbortSignal` is replaced by a cooperative {@see \Gisl\Sdk\Cancellation}
 *    token (passed via {@see RunOptions::$cancellation} /
 *    {@see SubmitOptions::$cancellation}); PHP has no event-loop abort, so
 *    cancellation is checked between steps alongside the `maxWait` deadline
 *    (VOxtu0RZ-B3). Mid-request transfer abort remains a follow-up (VOxtu0RZ-B4).
 *  - Input is `string` filesystem path only (matches existing
 *    `GislClient::uploadFile()`'s contract); no Blob/File analogue
 *    (in-memory/stream input is VOxtu0RZ-B4).
 *  - The SSE generator cannot be interrupted mid-frame on a blocking
 *    read. The deadline check fires between yielded frames, and the
 *    poll-fallback path is preferred for callers concerned about quiet
 *    streams (set `RunOptions::$useSSE = false`).
 */
final class OperationBuilder
{
    /**
     * @param array<string, mixed> $opOptions Operation options. For compress these may include the
     *                                         ergonomic `optimize` (OptimizeFor|string) and
     *                                         `presetOverrides` (a `*CompressPresetOptions` leaf DTO
     *                                         or a camelCase array) keys, consumed by the resolver.
     * @param PresetDefaults|null  $presetDefaults       Client-scope preset defaults wired through from
     *                                                   `Gisl::create(presetDefaults: ...)` (P6). When
     *                                                   set AND opType is `compress`, run()/submit()
     *                                                   walk the resolver before building the payload.
     * @param PresetDefaults|null  $scopedPresetDefaults Scoped defaults from `withPresetDefaults(...)`
     *                                                   (P7 — `5k3ZWo6B`); null in P6.
     */
    public function __construct(
        private readonly GislClient $client,
        private readonly string $opType,
        private readonly string $input,
        private readonly array $opOptions,
        private readonly ?PresetDefaults $presetDefaults = null,
        private readonly ?PresetDefaults $scopedPresetDefaults = null,
    ) {
    }

    /**
     * Run the preset resolver for compress operations and return the
     * resolved `{wireOptions, resolvedOptions}` tuple. For non-compress
     * operations (or when the input has no recognised compress media
     * fingerprint), returns the legacy passthrough — `opOptions` direct to
     * the wire, `resolvedOptions: null` (the projector then emits the
     * placeholder shape). Throws {@see GislConfigError} for invalid combos
     * BEFORE any network round-trip. Mirrors `builder.ts:405-439`.
     *
     * @return array{wireOptions: array<string, mixed>, resolvedOptions: ResolvedOptions|null}
     */
    private function resolve(): array
    {
        if ($this->opType !== 'compress') {
            return ['wireOptions' => $this->opOptions, 'resolvedOptions' => null];
        }
        $media = self::detectCompressMedia($this->input);
        if ($media === null) {
            // Unknown media (unrecognised extension) — passthrough, no
            // preset resolution. Mirrors builder.ts:410-414.
            return ['wireOptions' => $this->opOptions, 'resolvedOptions' => null];
        }

        $explicit = $this->opOptions;
        $optimizeRaw = $explicit['optimize'] ?? null;
        unset($explicit['optimize']);
        $presetOverridesRaw = $explicit['presetOverrides'] ?? null;
        unset($explicit['presetOverrides']);

        $resolved = PresetResolver::resolveCompress(
            media: $media,
            presetDefaults: $this->presetDefaults,
            scopedDefaults: $this->scopedPresetDefaults,
            presetOverrides: self::normalisePresetOverrides($presetOverridesRaw),
            optimize: self::coerceOptimize($optimizeRaw),
            explicitOptions: $explicit,
            audioLossless: $media === 'audio' ? self::detectAudioLossless($this->input) : null,
        );

        return ['wireOptions' => $resolved['wireOptions'], 'resolvedOptions' => $resolved['resolvedOptions']];
    }

    /**
     * Detect the compress media class from a filesystem path's extension.
     * Returns null for unrecognised extensions (caller falls back to
     * passthrough). MIME-less PHP analogue of `_detectCompressMedia`
     * (`builder.ts:67-113`) — PHP inputs are always filesystem paths.
     *
     * @internal Exposed for unit tests.
     *
     * @return 'image'|'audio'|'video'|'document_office'|'document_odf'|'document_epub'|'document_pdf'|null
     */
    public static function detectCompressMedia(string $input): ?string
    {
        $dot = \strrpos($input, '.');
        if ($dot === false) {
            return null;
        }
        $ext = \strtolower(\substr($input, $dot + 1));
        if ($ext === '') {
            return null;
        }
        if (\in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif', 'tiff', 'tif', 'bmp', 'heic', 'heif'], true)) {
            return 'image';
        }
        if (\in_array($ext, ['mp3', 'aac', 'm4a', 'ogg', 'oga', 'flac', 'wav', 'opus'], true)) {
            return 'audio';
        }
        if (\in_array($ext, ['mp4', 'mov', 'mkv', 'webm', 'avi', 'wmv', 'flv', 'm4v'], true)) {
            return 'video';
        }
        if ($ext === 'epub') {
            return 'document_epub';
        }
        if ($ext === 'pdf') {
            return 'document_pdf';
        }
        if (\in_array($ext, ['odt', 'ods', 'odp'], true)) {
            return 'document_odf';
        }
        if (\in_array($ext, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'], true)) {
            return 'document_office';
        }
        return null;
    }

    /**
     * Detect the compress media class from a MIME type (fFwaKsN5). The canonical
     * media signal for a resource input carrying a `contentType` hint — preferred
     * over the filename extension. Mirrors the MIME branch of the TS
     * `_detectCompressMedia` (`builder.ts`). Returns null for unrecognised MIMEs.
     *
     * @internal Exposed for {@see \Gisl\Sdk\FileFirst\FileInput::compressMediaHint()} + unit tests.
     *
     * @return 'image'|'audio'|'video'|'document_office'|'document_odf'|'document_epub'|'document_pdf'|null
     */
    public static function detectCompressMediaFromMime(string $mime): ?string
    {
        if (\str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if (\str_starts_with($mime, 'audio/')) {
            return 'audio';
        }
        if (\str_starts_with($mime, 'video/')) {
            return 'video';
        }
        if ($mime === 'application/epub+zip') {
            return 'document_epub';
        }
        if ($mime === 'application/pdf') {
            return 'document_pdf';
        }
        if (\in_array($mime, [
            'application/vnd.oasis.opendocument.text',
            'application/vnd.oasis.opendocument.spreadsheet',
            'application/vnd.oasis.opendocument.presentation',
        ], true)) {
            return 'document_odf';
        }
        if (\in_array($mime, [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/msword',
            'application/vnd.ms-excel',
            'application/vnd.ms-powerpoint',
        ], true)) {
            return 'document_office';
        }
        return null;
    }

    /**
     * Best-effort classification of whether an audio path is LOSSLESS
     * (flac/wav) vs lossy, by filename extension. The worker rejects
     * `bitrate` on lossless audio (compress.yaml / contracts iakhSy3E), so
     * the preset resolver uses this to drop the shipped-preset bitrate for
     * clear-cut lossless inputs.
     *
     * Detection is filename-only — it CANNOT probe the actual codec, so any
     * ambiguous or unknown input classifies as lossy (keep bitrate). The
     * worker stays authoritative: a user-supplied bitrate on a lossless file
     * still reaches the wire and earns a deliberate 422. Filename-only PHP
     * analogue of `_detectAudioLossless` (`builder.ts`); the MIME branch is
     * {@see detectAudioLosslessFromMime()}.
     *
     * @internal Exposed for {@see \Gisl\Sdk\FileFirst\FileInput} + unit tests.
     */
    public static function detectAudioLossless(string $input): bool
    {
        $dot = \strrpos($input, '.');
        if ($dot === false) {
            return false;
        }
        $ext = \strtolower(\substr($input, $dot + 1));
        if ($ext === '') {
            return false;
        }
        return \in_array($ext, ['flac', 'wav'], true);
    }

    /**
     * Best-effort LOSSLESS-audio classification from a MIME type (the
     * canonical signal for a resource input carrying a `contentType` hint —
     * preferred over the filename extension). Mirrors the MIME branch of the
     * TS `_detectAudioLossless` (`builder.ts`). Returns true only for the five
     * lossless audio MIMEs; everything else (including non-audio) is false.
     *
     * @internal Exposed for {@see \Gisl\Sdk\FileFirst\FileInput} + unit tests.
     */
    public static function detectAudioLosslessFromMime(string $mime): bool
    {
        // Strip MIME parameters (`audio/flac; codecs=flac`) before the exact-set
        // lookup so a parameterised type still classifies (codex 18b6b684).
        $bareMime = \strtolower(\trim(\explode(';', $mime, 2)[0]));
        return \in_array($bareMime, [
            'audio/flac',
            'audio/x-flac',
            'audio/wav',
            'audio/x-wav',
            'audio/wave',
        ], true);
    }

    private static function coerceOptimize(mixed $raw): ?OptimizeFor
    {
        if ($raw === null) {
            return null;
        }
        if ($raw instanceof OptimizeFor) {
            return $raw;
        }
        if (\is_string($raw)) {
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
        $type = \get_debug_type($raw);
        throw new GislConfigError(
            "compress 'optimize' must be an OptimizeFor or its string value; got {$type}.",
            reason: 'invalid_optimize',
            conflictingFields: ['optimize'],
        );
    }

    /**
     * @return object|array<string, mixed>|null
     */
    public static function normalisePresetOverrides(mixed $raw): object|array|null
    {
        if ($raw === null || \is_object($raw)) {
            return $raw;
        }
        if (\is_array($raw)) {
            /** @var array<string, mixed> $raw */
            return $raw;
        }
        $type = \get_debug_type($raw);
        throw new GislConfigError(
            "compress 'presetOverrides' must be a *CompressPresetOptions instance or a camelCase array; got {$type}.",
            reason: 'invalid_preset_overrides',
            conflictingFields: ['presetOverrides'],
        );
    }

    /**
     * Execute the operation end-to-end. Uploads the input, creates the
     * workflow, waits to a terminal status (via SSE with poll fallback),
     * fetches downloads, and projects to a flat {@see Result}. Throws
     * {@see GislTimeoutError} if `RunOptions::$maxWait` elapses before
     * the workflow reaches a terminal status.
     */
    public function run(RunOptions $options): Result
    {
        $deadlineMs = BuilderInternals::nowMs() + MaxWait::parse($options->maxWait);
        $onProgress = BuilderInternals::callableOrNull($options->onProgress, 'RunOptions::$onProgress');

        // 0. Resolve presets FIRST so a GislConfigError fails the call
        // before any I/O — the SDK promised fail-early for invalid combos.
        // Mirrors builder.ts:453-455.
        $resolved = $this->resolve();

        // Honour an already-cancelled token before spending the upload.
        BuilderInternals::throwIfCancelled($options->cancellation, 'upload');

        // 1. Upload — emits UploadProgressEvent from the byte counter.
        $uploadOpts = null;
        if ($onProgress !== null) {
            $uploadOpts = new UploadOptions(
                onProgress: static function (int $uploadedBytes, int $totalBytes) use ($onProgress): void {
                    $onProgress(new UploadProgressEvent($uploadedBytes, $totalBytes));
                },
            );
        }
        $uploadResp = $this->client->uploadFile($this->input, $uploadOpts);

        // Codex TS r2 medium 9a117f04eb59 — check the deadline AFTER upload
        // so a slow upload doesn't proceed to createWorkflow past the
        // caller's deadline.
        BuilderInternals::throwIfCancelled($options->cancellation, 'workflow creation');
        if (BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError(
                'Upload completed but maxWait elapsed before workflow could be created.',
            );
        }

        // Best-effort probe-before-create for a multipart video upload
        // (never-bounce). Capped to the remaining maxWait budget so a slow probe
        // cannot push createWorkflow past the caller's deadline.
        $this->client->maybeWaitForVideoProbe(
            $uploadResp->getFileId() ?? '',
            $options->probeBeforeCreate ?? true,
            self::detectCompressMedia($this->input) === 'video',
            $uploadResp->getSizeBytes(),
            BuilderInternals::cappedProbeTimeoutMs($options->probeTimeoutMs, $deadlineMs),
            $options->cancellation,
        );
        // A cancel arriving during the FINAL successful probe request must not
        // still create the workflow (maybeWaitForVideoProbe returns landed
        // without a final cancel re-check), so check here BEFORE createWorkflow.
        BuilderInternals::throwIfCancelled($options->cancellation, 'workflow creation');
        // RE-CHECK the deadline AFTER the probe wait (it consumes time).
        if (BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError(
                'Probe wait completed but maxWait elapsed before workflow could be created.',
            );
        }

        // 2. Build + create the workflow with the resolved wire options.
        $job = new JobDefinitionPayload(
            operations: [new OperationDef(type: $this->opType, options: $resolved['wireOptions'])],
            id: 'op',
            source: Sources::upload($uploadResp->getFileId() ?? ''),
        );
        $created = $this->client->createWorkflow(new WorkflowCreatePayload(jobs: [$job]));

        // 3. Wait to terminal status.
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

        // 4. Fetch downloads. Codex TS r1 medium 42a6ea3b6102 — the
        // maxWait deadline covers upload + create + wait + downloads, so
        // check the deadline before issuing the downloads request rather
        // than letting a slow getWorkflowDownloads silently exceed it.
        BuilderInternals::throwIfCancelled($options->cancellation, 'downloads fetch');
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

        return self::projectResult(
            $finalStatus,
            $downloads->getDownloads() ?? [],
            $resolved['wireOptions'],
            $resolved['resolvedOptions'],
        );
    }

    /**
     * Fire-and-forget: upload the input + create the workflow with a
     * `callback_url` wired to {@see SubmitOptions::$webhook}, then
     * return a {@see Handle} (workflowId + webhookSecret when the
     * server returned one) without waiting. The webhook receives
     * completion notification and the secret is the HMAC-verifier seed.
     */
    public function submit(SubmitOptions $options): Handle
    {
        // Resolve presets before any I/O so a GislConfigError fails the
        // call before the upload — same fail-early contract as run().
        $resolved = $this->resolve();
        BuilderInternals::throwIfCancelled($options->cancellation, 'upload');
        $uploadResp = $this->client->uploadFile($this->input);

        // A cancel that arrived DURING the upload must not still create a
        // workflow (parity with run() + MergeBuilder::submit()).
        BuilderInternals::throwIfCancelled($options->cancellation, 'workflow creation');

        // Best-effort probe-before-create for a multipart video upload (never-bounce).
        $this->client->maybeWaitForVideoProbe(
            $uploadResp->getFileId() ?? '',
            $options->probeBeforeCreate ?? true,
            self::detectCompressMedia($this->input) === 'video',
            $uploadResp->getSizeBytes(),
            $options->probeTimeoutMs,
            $options->cancellation,
        );

        // A cancel during the final probe wait must not still create (parity
        // with run() — maybeWaitForVideoProbe returns landed without a final
        // cancel re-check).
        BuilderInternals::throwIfCancelled($options->cancellation, 'workflow creation');

        $job = new JobDefinitionPayload(
            operations: [new OperationDef(type: $this->opType, options: $resolved['wireOptions'])],
            id: 'op',
            source: Sources::upload($uploadResp->getFileId() ?? ''),
        );
        $payload = new WorkflowCreatePayload(
            jobs: [$job],
            callbackUrl: $options->webhook,
        );
        $created = $this->client->createWorkflow($payload);

        return new Handle(
            workflowId: $created->getWorkflowId() ?? '',
            webhookSecret: $created->getWebhookSecret(),
        );
    }

    /**
     * Fan-out chain scaffold. Today this returns a {@see MapEachBuilder}
     * whose `run()` runs the parent builder + fans the fn out over each
     * resulting {@see Artifact}. See {@see MapEachBuilder}'s class-level
     * docblock for the KNOWN LIMITATION carried over from TS T6 / P4.
     */
    public function mapEach(callable $fn): MapEachBuilder
    {
        return new MapEachBuilder($this, $fn);
    }

    /**
     * @param array<string, mixed>                                $appliedOptions
     * @param array<int, \Gisl\Generated\OpenApi\Model\JobDownload>|null $jobDownloads
     *   (The openapi-generator emits the parent collection as
     *   `array<JobDownload>` rather than `list<JobDownload>`; callers
     *   pass the getter's return value through unchanged.)
     * @param ResolvedOptions|null $resolvedOptionsOverride P6 — when provided (the compress resolver
     *                                                      path), supplants the placeholder projection.
     *                                                      MergeBuilder + the passthrough path omit it
     *                                                      and receive the legacy empty-buckets shape.
     *                                                      Mirrors `_projectResult`'s arg at builder.ts:900.
     *
     * @internal Exposed for {@see MapEachBuilder} + {@see MergeBuilder}
     *           reuse. Not part of the public API.
     */
    public static function projectResult(
        WorkflowStatusResponse $status,
        ?array $jobDownloads,
        array $appliedOptions,
        ?ResolvedOptions $resolvedOptionsOverride = null,
    ): Result {
        $artifacts = [];
        foreach ($jobDownloads ?? [] as $job) {
            $jobId = BuilderInternals::coerceString($job->getJobId());
            $ref = BuilderInternals::coerceString($job->getRef());
            foreach ($job->getFiles() ?? [] as $file) {
                /** @var OperationDownload $file */
                $artifacts[] = new Artifact(
                    url: BuilderInternals::coerceString($file->getDownloadUrl()),
                    filename: BuilderInternals::coerceString($file->getFilename()),
                    sizeBytes: (int) ($file->getSizeBytes() ?? 0),
                    operation: BuilderInternals::coerceString($file->getOperation()),
                    operationId: BuilderInternals::coerceString($file->getOperationId()),
                    jobId: $jobId,
                    ref: $ref,
                    pageIndex: $file->getPageIndex(),
                    position: $file->getPosition(),
                );
            }
        }

        // Codex TS r1 medium 5d098e0f135e — error diagnostics live on
        // OperationResponse.errorCode/errorMessage, NOT on JobResponse.
        $jobs = [];
        foreach ($status->getJobs() ?? [] as $j) {
            $jobOps = [];
            foreach ($j->getOperations() ?? [] as $op) {
                $jobOps[] = new OperationBreakdown(
                    id: BuilderInternals::coerceString($op->getId()),
                    type: BuilderInternals::coerceString($op->getType()),
                    status: BuilderInternals::coerceString($op->getStatus()),
                    progress: $op->getProgress() !== null ? (float) $op->getProgress() : null,
                    errorCode: $op->getErrorCode(),
                    errorMessage: $op->getErrorMessage(),
                );
            }
            $jobs[] = new JobBreakdown(
                jobId: BuilderInternals::coerceString($j->getJobId()),
                ref: BuilderInternals::coerceString($j->getRef()),
                status: BuilderInternals::coerceString($j->getStatus()),
                operations: $jobOps,
            );
        }

        $createdAtStr = BuilderInternals::formatIso8601Utc($status->getCreatedAt());
        $updatedAtStr = BuilderInternals::formatIso8601Utc($status->getUpdatedAt());

        return new Result(
            workflowId: BuilderInternals::coerceString($status->getWorkflowId()),
            status: BuilderInternals::coerceString($status->getStatus()),
            createdAt: $createdAtStr,
            updatedAt: $updatedAtStr,
            artifacts: $artifacts,
            jobs: $jobs,
            url: \count($artifacts) === 1 ? $artifacts[0]->url : null,
            resolvedOptions: $resolvedOptionsOverride ?? new ResolvedOptions(
                preset: null,
                applied: $appliedOptions,
                overrides: [],
                presetVersion: PresetResolver::PRESET_VERSION,
                sources: ResolvedOptionsSources::empty(),
            ),
        );
    }
}
