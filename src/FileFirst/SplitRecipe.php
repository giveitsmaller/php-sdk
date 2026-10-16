<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

use Gisl\Sdk\Cancellation;
use Gisl\Sdk\Ergonomic\Handle;
use Gisl\Sdk\WorkflowCreatePayload;

/**
 * The TERMINAL recipe you're in AFTER `$client->file($x)->split($options)`
 * (DflFw0j1). `split` fans one input into N outputs and is sole_op, so there is
 * no chain after it and no `batch()` entry; this exposes only `run()` /
 * `submit()` (plus `key()` / `stepCount()` / `toWorkflowPayload()`).
 *
 * It delegates to a private {@see Recipe} whose LAST step is the split, so
 * lowering, preflight (unknown keys, the planned `precision: 'exact'` gate) and
 * the run/submit path are exactly the single-input Recipe's. Steps before the
 * split lower into an upstream `pre` job; `run()` then returns only the split
 * job's outputs. Each {@see OutputFile} carries `pageIndex` for PDF outputs and
 * `position` when the server provides it. `toFile()` on a multi-output result
 * throws `not_single_output`; use `downloadTo($dir)` or iterate `artifacts`.
 *
 * Immutable value like {@see Recipe} / {@see ArchivedRecipe}. Mirrors the TS
 * `SplitRecipe` in `packages/typescript/src/file-first.ts`.
 */
final class SplitRecipe
{
    /**
     * @internal Built by {@see Recipe::split()}; not a caller-facing constructor.
     */
    public function __construct(
        private readonly Recipe $recipe,
    ) {
    }

    /** The result-addressing key passed to `file()`, or null. */
    public function key(): ?string
    {
        return $this->recipe->key();
    }

    /** The number of operations chained, the split included (introspection / tests). */
    public function stepCount(): int
    {
        return $this->recipe->stepCount();
    }

    /**
     * Lower to the workflow-create payload: one job when the split is the only
     * step, else a `pre` job feeding a `split` job via `job_output`.
     *
     * @internal Consumed by {@see run()} / {@see submit()} and the parity harness.
     */
    public function toWorkflowPayload(string $fileId, ?string $callbackUrl = null): WorkflowCreatePayload
    {
        return $this->recipe->toWorkflowPayload($fileId, $callbackUrl);
    }

    /**
     * Execute end-to-end (upload, create, wait, downloads) and return the split's
     * N outputs as a {@see RunResult}. Same parameters and errors as
     * {@see Recipe::run()}.
     *
     * @param (callable(\Gisl\Sdk\Ergonomic\ProgressEvent): void)|null $onProgress
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
        return $this->recipe->run(
            $maxWait,
            $onProgress,
            $pollIntervalMs,
            $cancellation,
            $probeBeforeCreate,
            $probeTimeoutMs,
            $useSSE,
        );
    }

    /**
     * Fire-and-forget: upload + create the split workflow (wiring `$webhook` into
     * `callback_url` when given) and return a client-bound {@see Handle}. Same
     * shape as {@see Recipe::submit()}.
     */
    public function submit(
        ?string $webhook = null,
        ?bool $probeBeforeCreate = null,
        ?int $probeTimeoutMs = null,
    ): Handle {
        return $this->recipe->submit($webhook, $probeBeforeCreate, $probeTimeoutMs);
    }
}
