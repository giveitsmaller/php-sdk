<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

use Gisl\Generated\OpenApi\Model\WorkflowCreateResponse;
use Gisl\Sdk\Cancellation;
use Gisl\Sdk\Ergonomic\BuilderInternals;
use Gisl\Sdk\Ergonomic\MaxWait;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\Http\UploadSource;
use Gisl\Sdk\JobDefinitionPayload;
use Gisl\Sdk\UploadOptions;
use Gisl\Sdk\WorkflowCreatePayload;

/**
 * The HETEROGENEOUS batch builder value (FF7). `$client->batch([$a, $b, $c])`
 * runs N DISTINCT single-input keyed {@see Recipe}s as ONE workflow, then
 * returns a partitioned {@see RunResult} whose per-recipe results are addressed
 * by each recipe's `key:` (`$result->byKey('hero')`). Unlike {@see FilesRecipe}
 * (ONE shared op-chain fanned across many inputs, keyed by input index), each
 * batch entry carries its OWN input AND its OWN op-chain.
 *
 * **v1 scope (locked):**
 *  - `run()` ONLY — `submit()`/Handle-reattach is a follow-up (a reattached
 *    handle cannot recover caller keys without a batch-status detector +
 *    reversible key↔ref encoding).
 *  - SINGLE-INPUT recipes ONLY — every entry must be a {@see Recipe}; the
 *    multi-input builders ({@see FilesRecipe}/{@see MergedRecipe}/
 *    {@see WatermarkedRecipe}/{@see ArchivedRecipe}) are rejected pre-upload.
 *  - NO cross-recipe upload dedupe — each entry's input uploads 1:1, exactly
 *    like {@see FilesRecipe} does today (a correctness-neutral follow-up).
 *
 * **Lowering (one workflow):** for each entry `$i`, its single-file
 * {@see Recipe::toWorkflowPayload()} is composed to get that entry's one-job
 * payload, then re-wrapped as a job with `id = "b{$i}"` (a distinct namespace
 * from FilesRecipe's `file-{i}` and MergedRecipe's `src_{i}`/`merge` so a future
 * reattach cannot misdetect a batch as a fan-out/merge). All jobs merge into ONE
 * {@see WorkflowCreatePayload}. `keyByRef` maps `"b{$i}" => $entry->key()` so
 * {@see RunResult::fromTerminalMultiJob()} partitions per entry, addressed by
 * the caller's key — one failed entry does not sink the rest.
 *
 * Client-only construction: the ctor takes ONLY the ordered entries + the
 * client — NOT preset defaults, because each entry already captured its own
 * presets at `$client->file(...)` time (batch never rebuilds a Recipe).
 *
 * Mirrors the TS `BatchRecipe` in `packages/typescript/src/file-first.ts`.
 */
final class BatchRecipe
{
    /**
     * @param list<Recipe>    $recipes Ordered batch entries (each addressed by its own key).
     * @param GislClient|null $client  Bound at construction by `$client->batch(...)`;
     *                                 a directly-constructed BatchRecipe has none and
     *                                 {@see run()} throws.
     */
    public function __construct(
        private readonly array $recipes,
        private readonly ?GislClient $client = null,
    ) {
    }

    /** The number of recipes in this batch (introspection / tests). */
    public function recipeCount(): int
    {
        return \count($this->recipes);
    }

    /**
     * Execute the batch end-to-end: validate + preflight EVERY entry, upload each
     * entry's input, create ONE workflow with one job per entry (`id = "b{i}"`),
     * await a terminal state (SSE with poll fallback), then resolve the per-job
     * downloads into a partitioned {@see RunResult} keyed by each entry's `key:`.
     * `partially_failed` is a NORMAL terminal state here — successful entries land
     * in `succeeded`, failed entries in `failed`.
     *
     * Requires a client bound at construction time — `Gisl::create()->batch(...)`
     * wires it; a directly-constructed `BatchRecipe` throws {@see GislConfigError}.
     * Mirrors {@see FilesRecipe::run()}.
     *
     * @param string|int|null $maxWait Wall-clock deadline for the whole run.
     * @param (callable(\Gisl\Sdk\Ergonomic\ProgressEvent): void)|null $onProgress
     * @param int|null $pollIntervalMs Override the poll-fallback interval (ms).
     * @param Cancellation|null $cancellation Cooperative cancellation token —
     *        cancel it to abort the batch early (between uploads / wait frames)
     *        with a {@see \Gisl\Sdk\Errors\GislAbortError}.
     * @param bool|null $probeBeforeCreate Best-effort probe-before-create for the
     *        VIDEO inputs that went multipart (default true). Pass false to skip.
     * @param int|null $probeTimeoutMs Aggregate timeout (ms) for the probe waits.
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
                'BatchRecipe::run() requires a client; build the batch via Gisl::create()->batch(...) rather than constructing BatchRecipe directly.',
                reason: 'no_client',
            );
        }

        // Validate + FULL preflight BEFORE any upload so a later invalid entry
        // never leaves earlier entries' inputs uploaded (codex FF7 #4/#5).
        $this->validateAndPreflight();

        $deadlineMs = BuilderInternals::nowMs() + MaxWait::parse($maxWait ?? 300_000);
        $onProgressClosure = BuilderInternals::callableOrNull($onProgress, 'BatchRecipe::run() $onProgress');

        // 1+2. Upload EVERY entry's input + create ONE multi-job workflow.
        $created = $this->uploadAllAndCreate(
            $deadlineMs,
            $onProgressClosure,
            $cancellation,
            $probeBeforeCreate,
            $probeTimeoutMs,
        );
        $workflowId = $created->getWorkflowId() ?? '';

        // 3. Wait to terminal status — SSE first, poll on a genuine SSE error.
        // `partially_failed` is a normal terminal state here.
        $finalStatus = BuilderInternals::awaitTerminal(
            client: $this->client,
            workflowId: $workflowId,
            deadlineMs: $deadlineMs,
            onProgress: $onProgressClosure,
            useSSE: $useSSE ?? true,
            pollIntervalMs: $pollIntervalMs,
            cancellation: $cancellation,
        );

        // 4. Fetch downloads + project per-job into the partitioned RunResult.
        BuilderInternals::throwIfCancelled($cancellation, 'downloads fetch');
        if (BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError(
                "Workflow {$workflowId} reached terminal status but maxWait elapsed before downloads could be fetched.",
            );
        }
        $downloads = $this->client->getWorkflowDownloads($workflowId);
        // The maxWait deadline also covers the downloads fetch itself — re-check
        // AFTER the call so a slow getWorkflowDownloads cannot return a success
        // past the advertised whole-run deadline (mirrors FilesRecipe::run()).
        if (BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError(
                "Workflow {$workflowId} downloads fetch completed after maxWait elapsed.",
            );
        }

        // keyByRef maps each entry's job ref ("b{i}") to the caller's key, so the
        // per-job partition is addressed by key rather than by index.
        $keyByRef = [];
        foreach ($this->recipes as $i => $entry) {
            $keyByRef["b{$i}"] = $entry->key();
        }

        return RunResult::fromTerminalMultiJob(
            workflowId: $workflowId,
            finalStatus: $finalStatus,
            jobDownloads: \array_values($downloads->getDownloads() ?? []),
            keyByRef: $keyByRef,
            downloader: new StreamingDownloader(),
        );
    }

    /**
     * Lower the batch to a single multi-job workflow-create payload against a
     * list of resolved upload ids (one per entry, in entry order). Each entry
     * `$i` becomes ONE job with `id = "b{$i}"`, its `source: upload($fileIds[$i])`,
     * and its OWN lowered `operations[]`. Composes each entry's single-file
     * {@see Recipe::toWorkflowPayload()} so per-entry media-hints + op-chains
     * resolve independently and lowering logic is not duplicated.
     *
     * @internal Consumed by {@see run()} (after uploading all inputs) and the
     *           cross-language golden-payload lowering test (with fixed ids).
     *           Not caller-facing.
     *
     * When `$callbackUrl` is given it is built INTO the payload (`callback_url`);
     * `run()` passes none (v1 has no `submit()`).
     *
     * @param list<string> $fileIds
     */
    public function toWorkflowPayload(array $fileIds, ?string $callbackUrl = null): WorkflowCreatePayload
    {
        $jobs = [];
        foreach ($this->recipes as $i => $entry) {
            $oneJob = $entry->toWorkflowPayload($fileIds[$i])->jobs[0];
            // Key order (id, source, operations) matches the TS lowering so the
            // JSON-string serialisation is byte-identical across languages.
            $jobs[] = new JobDefinitionPayload(
                operations: $oneJob->operations,
                id: "b{$i}",
                source: $oneJob->source,
            );
        }

        return new WorkflowCreatePayload(jobs: $jobs, callbackUrl: $callbackUrl);
    }

    /**
     * Batch-wide PRE-UPLOAD validation + preflight. One structural pass followed
     * by a full uploadability/lowering preflight — ALL before any upload fires,
     * so a bad entry anywhere in the batch fails fast without leaving earlier
     * entries' inputs uploaded.
     *
     * Guard ORDER is load-bearing (codex FF7 #1): the KNOWN multi-input builders
     * are checked BEFORE the not-a-Recipe catch-all, because they do NOT extend
     * {@see Recipe} and would otherwise be mis-reported as plain type errors.
     * The offending key/index rides the human MESSAGE (not `conflictingFields`,
     * which is documented as camelCase FIELD names).
     */
    private function validateAndPreflight(): void
    {
        if ($this->recipes === []) {
            throw new GislConfigError(
                'batch() requires at least one recipe.',
                reason: 'no_recipes',
            );
        }

        // Structural validation: type + unique non-empty key, for EVERY entry
        // before any preflight (all upload-free).
        $seenKeyIndex = [];
        foreach ($this->recipes as $i => $entry) {
            // ORDER MATTERS: reject the KNOWN multi-input builders FIRST. They do
            // not extend Recipe, so the not-a-Recipe catch-all below would
            // otherwise mis-report them as generic type errors rather than the
            // deferred-feature error.
            if ($entry instanceof FilesRecipe
                || $entry instanceof MergedRecipe
                || $entry instanceof WatermarkedRecipe
                || $entry instanceof ArchivedRecipe
            ) {
                throw new GislConfigError(
                    "batch() entry at index {$i} is a multi-input recipe (" . $entry::class . '); '
                    . 'batch() v1 accepts only single-input recipes built via $client->file(...). '
                    . 'Combining multi-input recipes inside a batch is not yet supported.',
                    reason: 'multi_input_recipe_unsupported',
                );
            }
            if (!($entry instanceof Recipe)) {
                throw new GislConfigError(
                    "batch() entry at index {$i} is not a Recipe (got " . \get_debug_type($entry) . '); '
                    . 'build each entry via $client->file($path, $key).',
                    reason: 'invalid_recipe',
                );
            }
            $key = $entry->key();
            if ($key === null || $key === '') {
                throw new GislConfigError(
                    "batch() entry at index {$i} has no key; every batch entry needs a non-empty key "
                    . '(pass it as $client->file($path, $key)) so its result is addressable via $result->byKey($key).',
                    reason: 'missing_key',
                );
            }
            if (isset($seenKeyIndex[$key])) {
                throw new GislConfigError(
                    "batch() has a duplicate key '{$key}' (entries at index {$seenKeyIndex[$key]} and {$i}); "
                    . 'every batch entry needs a unique key so each result is addressable.',
                    reason: 'duplicate_key',
                );
            }
            $seenKeyIndex[$key] = $i;
        }

        // FULL pre-upload preflight — mirror FilesRecipe::uploadAllAndCreate()'s
        // loop: per-input path/resource uploadability checks AND per-entry op-chain
        // lowering (with a placeholder id) so a non-readable/non-seekable input or
        // an un-lowerable op chain (e.g. compress(optimize) on a hint-less input)
        // anywhere in the batch fails BEFORE any upload.
        foreach ($this->recipes as $entry) {
            $input = $entry->recipeInput();
            if ($input->kind === FileInput::KIND_RESOURCE) {
                UploadSource::assertUploadableStream($input->resource);
                // Validate the resource's filename/contentType hints up front so a
                // bad hint on a LATER entry fails before any earlier input uploads.
                UploadOptions::assertHintsValid($input->contentType, $input->filename);
            } elseif ($input->kind === FileInput::KIND_PATH) {
                // Validate existence/readability now so a missing path later in the
                // batch does not leave earlier inputs uploaded. `fromPath()` throws
                // GislConfigError on a bad path.
                UploadSource::fromPath(BuilderInternals::coerceString($input->path));
            }
            // Lower this entry's op chain with a placeholder id to trigger its
            // per-entry validation (media_unknown etc.) before any upload.
            $entry->toWorkflowPayload('preflight');
        }
    }

    /**
     * Upload every entry's input + create ONE multi-job workflow via the shared
     * {@see MultiInputUpload} tail. The batch-wide validation + preflight
     * ({@see validateAndPreflight()}) has already run, so a bad entry fails
     * before any upload.
     *
     * @param (\Closure(\Gisl\Sdk\Ergonomic\UploadProgressEvent): void)|null $onProgressClosure
     */
    private function uploadAllAndCreate(
        ?int $deadlineMs,
        ?\Closure $onProgressClosure,
        ?Cancellation $cancellation = null,
        ?bool $probeBeforeCreate = null,
        ?int $probeTimeoutMs = null,
    ): WorkflowCreateResponse {
        \assert($this->client !== null);

        return MultiInputUpload::uploadAllAndCreate(
            $this->client,
            \array_map(static fn (Recipe $entry): FileInput => $entry->recipeInput(), $this->recipes),
            fn (array $fileIds, ?string $callbackUrl): WorkflowCreatePayload => $this->toWorkflowPayload($fileIds, $callbackUrl),
            null,
            $deadlineMs,
            $onProgressClosure,
            $cancellation,
            $probeBeforeCreate,
            $probeTimeoutMs,
            'batch upload',
            'workflow creation',
            'batch',
            'workflow',
        );
    }
}
