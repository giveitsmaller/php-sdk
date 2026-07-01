<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

use Gisl\Generated\OpenApi\Model\WorkflowCreateResponse;
use Gisl\Sdk\Cancellation;
use Gisl\Sdk\Ergonomic\ArchiveFormat;
use Gisl\Sdk\Ergonomic\ArchiveRecipeOptions;
use Gisl\Sdk\Ergonomic\BuilderInternals;
use Gisl\Sdk\Ergonomic\Handle;
use Gisl\Sdk\Ergonomic\MaxWait;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\Http\UploadSource;
use Gisl\Sdk\JobDefinitionPayload;
use Gisl\Sdk\OperationDef;
use Gisl\Sdk\Sources;
use Gisl\Sdk\UploadOptions;
use Gisl\Sdk\WorkflowCreatePayload;

/**
 * The single-output recipe you're in AFTER a fluent `files([...])->archive(...)`
 * (FF3b). Archive bundles the N inputs into ONE downloadable archive (zip /
 * tar.gz) — media-agnostic, inputs may mix types. Unlike {@see MergedRecipe},
 * archive is TERMINAL: a zip is the final artefact, so there is no post-combine
 * chain — this exposes only `run()` / `submit()`.
 *
 * **Lowering (one workflow):** each input is uploaded once and wrapped in its
 * own single-input `passthrough` source job (`src_N`); the `archive` job
 * consumes those via `job_output` inputs (array order = entry order) and carries
 * the single `archive` op. The archive job's id is `archive`, so {@see RunResult}
 * projects ONLY its output (the `src_*` passthrough jobs re-expose the raw
 * uploads, which are plumbing).
 *
 * Immutable value like {@see Recipe} / {@see MergedRecipe}. Mirrors the TS
 * `ArchivedRecipe` in `packages/typescript/src/file-first.ts`.
 */
final class ArchivedRecipe
{
    /**
     * @param list<FileInput> $inputs Ordered inputs being bundled.
     */
    public function __construct(
        private readonly array $inputs,
        private readonly ArchiveRecipeOptions $options = new ArchiveRecipeOptions(),
        private readonly ?GislClient $client = null,
    ) {
    }

    /**
     * Execute end-to-end: upload every input, create the archive workflow, await
     * a terminal state (SSE with poll fallback), then resolve ONLY the archive
     * output into a {@see RunResult}. Throws {@see GislTimeoutError} on `$maxWait`.
     *
     * @param (callable(\Gisl\Sdk\Ergonomic\ProgressEvent): void)|null $onProgress
     * @param bool|null $probeBeforeCreate Best-effort probe-before-create for the
     *        VIDEO inputs that went multipart (default true). Pass false to skip.
     * @param int|null $probeTimeoutMs Aggregate timeout (ms) for the probe waits.
     */
    public function run(
        string|int|null $maxWait = null,
        ?callable $onProgress = null,
        ?int $pollIntervalMs = null,
        ?Cancellation $cancellation = null,
        ?bool $probeBeforeCreate = null,
        ?int $probeTimeoutMs = null,
    ): RunResult {
        $client = $this->requireClient();
        $deadlineMs = BuilderInternals::nowMs() + MaxWait::parse($maxWait ?? 300_000);
        $onProgressClosure = BuilderInternals::callableOrNull($onProgress, 'ArchivedRecipe::run() $onProgress');

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
            useSSE: true,
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
        // TDqmkWpX: the maxWait deadline also covers the downloads fetch itself —
        // re-check AFTER the call so a slow getWorkflowDownloads cannot return a
        // success past the advertised whole-run deadline.
        if (BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError(
                "Workflow {$workflowId} downloads fetch completed after maxWait elapsed.",
            );
        }

        // Project ONLY the archive job's output — the `src_*` passthrough jobs
        // re-expose the raw uploads, which are plumbing, not the deliverable.
        $archiveDownloads = \array_values(\array_filter(
            $downloads->getDownloads() ?? [],
            static fn ($d): bool => BuilderInternals::coerceString($d->getRef()) === 'archive',
        ));

        return RunResult::fromTerminalDownloads(
            workflowId: $workflowId,
            finalStatus: $finalStatus,
            jobDownloads: $archiveDownloads,
            key: null,
            downloader: new StreamingDownloader(),
        );
    }

    /**
     * Fire-and-forget: upload + create the archive workflow (wiring `$webhook`
     * into `callback_url` when given), return a client-bound {@see Handle}.
     * Mirrors {@see Recipe::submit()}.
     *
     * @param bool|null $probeBeforeCreate Best-effort probe-before-create for the
     *        VIDEO inputs that went multipart (default true). Pass false to skip.
     * @param int|null $probeTimeoutMs Aggregate timeout (ms) for the probe waits.
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
                'ArchivedRecipe requires a client; build via Gisl::create()->files(...)->archive(...) rather than constructing it directly.',
                reason: 'no_client',
            );
        }
        return $this->client;
    }

    /**
     * Upload every input once, then create ONE archive workflow.
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
        foreach ($this->inputs as $input) {
            if ($input->kind === FileInput::KIND_RESOURCE) {
                UploadSource::assertUploadableStream($input->resource);
                // fFwaKsN5 (codex r2): validate the resource hints up front so a
                // bad hint on a later input fails before earlier inputs upload.
                UploadOptions::assertHintsValid($input->contentType, $input->filename);
            } elseif ($input->kind === FileInput::KIND_PATH) {
                UploadSource::fromPath(BuilderInternals::coerceString($input->path));
            }
        }

        return MultiInputUpload::uploadAllAndCreate(
            $client,
            $this->inputs,
            fn (array $fileIds, ?string $callbackUrl): WorkflowCreatePayload => $this->toWorkflowPayload($fileIds, $callbackUrl),
            $webhook,
            $deadlineMs,
            $onProgressClosure,
            $cancellation,
            $probeBeforeCreate,
            $probeTimeoutMs,
            'archive upload',
            'archive workflow creation',
            'archive',
            'the archive workflow',
        );
    }

    /**
     * Reject an invalid bundle BEFORE any upload fires — the archive schema
     * allows 2–50 inputs (`min/max_inputs`), so a typo'd bundle costs no
     * bandwidth.
     */
    private function validatePreUpload(): void
    {
        $count = \count($this->inputs);
        if ($count < 2) {
            throw new GislConfigError(
                "archive requires at least 2 inputs to bundle (got {$count}).",
                reason: 'too_few_inputs',
            );
        }
        if ($count > 50) {
            throw new GislConfigError(
                "archive accepts at most 50 inputs (got {$count}). Split the bundle or reduce the input list.",
                reason: 'too_many_inputs',
            );
        }
    }

    /**
     * Lower to the archive DAG: one `passthrough` source job per input + one
     * `archive` job consuming them via `job_output`.
     *
     * @param list<string> $fileIds One uploaded file id per input, in order.
     */
    public function toWorkflowPayload(array $fileIds, ?string $callbackUrl = null): WorkflowCreatePayload
    {
        $sourceJobs = [];
        $inputs = [];
        foreach ($fileIds as $i => $fileId) {
            $srcId = 'src_' . $i;
            $sourceJobs[] = new JobDefinitionPayload(
                operations: [new OperationDef(type: 'passthrough')],
                id: $srcId,
                source: Sources::upload($fileId),
            );
            $inputs[] = ['source' => Sources::jobOutput($srcId)];
        }

        $archiveJob = new JobDefinitionPayload(
            operations: [new OperationDef(type: 'archive', options: $this->wireArchiveOptions())],
            id: 'archive',
            inputs: $inputs,
        );

        return new WorkflowCreatePayload(jobs: [...$sourceJobs, $archiveJob], callbackUrl: $callbackUrl);
    }

    /**
     * Project the archive options into the wire shape. Both fields are optional
     * (the server defaults `format` to zip and `folder_structure` to flat), so
     * an omitted option is dropped rather than sent.
     *
     * @return array<string, mixed>
     */
    private function wireArchiveOptions(): array
    {
        $out = [];
        $format = $this->options->format;
        if ($format !== null) {
            $out['format'] = $format instanceof ArchiveFormat ? $format->value : $format;
        }
        if ($this->options->folderStructure !== null) {
            $out['folder_structure'] = $this->options->folderStructure;
        }

        return $out;
    }
}
