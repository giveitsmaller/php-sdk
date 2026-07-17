<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

use Gisl\Generated\OpenApi\Model\JobDownload;
use Gisl\Generated\OpenApi\Model\WorkflowStatusResponse;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislResultNotReadyError;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\Cancellation;
use Gisl\Sdk\FileFirst\RunResult;
use Gisl\Sdk\FileFirst\StreamingDownloader;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\WorkflowConstants;

/**
 * Handle to a created workflow. Carries `workflowId` + an optional
 * `webhookSecret` (the data {@see OperationBuilder::submit()} /
 * {@see MergeBuilder::submit()} return) and, when reattached via
 * `client->workflow(id)`, an optional bound {@see GislClient}.
 *
 * The bound client is OPTIONAL (mirrors how {@see RunResult} binds its
 * {@see \Gisl\Sdk\FileFirst\Downloader}): the data fields + {@see toArray()}
 * stay byte-identical whether or not a client is present, so the
 * operation-first/merge `submit()` back-compat fixture (`{workflowId,
 * webhookSecret}` via `toArray()`) holds — the new `$client` parameter is
 * appended LAST and defaults to null, so the existing positional
 * `new Handle($workflowId, $webhookSecret)` call sites keep compiling.
 * When the client is absent, {@see status()}/{@see wait()}/{@see result()}
 * throw {@see GislConfigError} (reason `no_client`).
 *
 * A handle built via `client->workflow(id)` has NO recipe key, so its
 * {@see RunResult} is keyless (`succeeded[].key === null`) — address its
 * outputs positionally / via the sinks rather than `byKey()`.
 *
 * `webhookSecret` is the verifier seed for HMAC-signature checking of the
 * inbound webhook payload (per server contract). It is only present when the
 * server returned one — callers SHOULD treat its absence as a non-fatal
 * omission rather than an authentication error.
 *
 * Mirrors the TS `Handle` class at `packages/typescript/src/handle.ts`.
 */
final class Handle
{
    public function __construct(
        public readonly string $workflowId,
        public readonly ?string $webhookSecret = null,
        private readonly ?GislClient $client = null,
        // The recipe's result-addressing key, threaded from a file-first
        // `submit()` ({@see \Gisl\Sdk\FileFirst\Recipe::submit()}) so the
        // {@see RunResult} from `wait()`/`result()` is keyed
        // (`succeeded[].key === $key`). It is NOT secret (a plain readonly
        // field, unlike `$client`) but is deliberately kept OUT of
        // {@see toArray()} so the operation-first/merge `submit()` back-compat
        // shape (`{workflowId, webhookSecret?}`) stays byte-identical. A
        // reattached handle (`client->workflow(id)`) passes no key → null →
        // keyless RunResult.
        public readonly ?string $key = null,
    ) {
    }

    /**
     * Fetch the workflow's current status once (non-blocking) and project it
     * to a {@see StatusSnapshot}.
     *
     * @throws GislConfigError reason `no_client` when no client is bound.
     */
    public function status(): StatusSnapshot
    {
        $client = $this->requireClient();
        $status = $client->getWorkflowStatus($this->workflowId);

        return new StatusSnapshot(
            $this->workflowId,
            BuilderInternals::coerceString($status->getStatus()),
        );
    }

    /**
     * Block until the workflow reaches a terminal state (SSE with poll
     * fallback), then fetch its downloads and project to a {@see RunResult}.
     * This is the ONLY blocking accessor on a `Handle`.
     *
     * @param string|int|null $maxWait Wall-clock deadline for the wait +
     *                                 downloads. String suffix
     *                                 (`'2h'`/`'30m'`/`'120s'`) or
     *                                 milliseconds; defaults to 600s.
     * @param (callable(ProgressEvent): void)|null $onProgress
     * @param Cancellation|null $cancellation Cooperative cancellation token —
     *        cancel it to abort the wait early with a
     *        {@see \Gisl\Sdk\Errors\GislAbortError} (checked between SSE frames /
     *        poll iterations, like the `maxWait` deadline).
     *
     * @throws GislConfigError reason `no_client` when no client is bound.
     * @throws GislTimeoutError when `$maxWait` elapses before terminal.
     */
    public function wait(
        string|int|null $maxWait = null,
        ?callable $onProgress = null,
        ?Cancellation $cancellation = null,
    ): RunResult {
        $client = $this->requireClient();
        $deadlineMs = BuilderInternals::nowMs() + MaxWait::parse($maxWait ?? 600_000);
        $onProgressClosure = BuilderInternals::callableOrNull($onProgress, 'Handle::wait() $onProgress');

        $finalStatus = BuilderInternals::awaitTerminal(
            client: $client,
            workflowId: $this->workflowId,
            deadlineMs: $deadlineMs,
            onProgress: $onProgressClosure,
            useSSE: true,
            pollIntervalMs: null,
            cancellation: $cancellation,
        );

        BuilderInternals::throwIfCancelled($cancellation, 'downloads fetch');
        if (BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError(
                "Workflow {$this->workflowId} reached terminal status but maxWait elapsed before downloads could be fetched.",
                $this->workflowId,
            );
        }
        $downloads = $client->getWorkflowDownloads($this->workflowId);
        // TDqmkWpX: the maxWait deadline also covers the downloads fetch itself —
        // re-check AFTER the call so a slow getWorkflowDownloads cannot return a
        // success past the advertised whole-run deadline.
        if (BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError(
                "Workflow {$this->workflowId} downloads fetch completed after maxWait elapsed.",
                $this->workflowId,
            );
        }

        return $this->project($finalStatus, \array_values($downloads->getDownloads() ?? []));
    }

    /**
     * Non-blocking result accessor. Fetches the workflow status once: if the
     * workflow is terminal, fetches its downloads and projects to a
     * {@see RunResult}; if it is NOT terminal, throws
     * {@see GislResultNotReadyError}. Never waits or polls — use {@see wait()}
     * to block.
     *
     * @throws GislConfigError reason `no_client` when no client is bound.
     * @throws GislResultNotReadyError when the workflow is not yet terminal.
     */
    public function result(): RunResult
    {
        $client = $this->requireClient();
        $status = $client->getWorkflowStatus($this->workflowId);
        $state = BuilderInternals::coerceString($status->getStatus());
        if (!\in_array($state, WorkflowConstants::TERMINAL_STATUSES, true)) {
            throw new GislResultNotReadyError($this->workflowId, $state);
        }
        $downloads = $client->getWorkflowDownloads($this->workflowId);

        return $this->project($status, \array_values($downloads->getDownloads() ?? []));
    }

    /**
     * Project a terminal status + its per-job downloads into a {@see RunResult},
     * choosing the producer DATA-DRIVEN off the wire (not a construction-time
     * marker, so a fan-out reattached via `client->workflow(id)` — which
     * carries no marker — still partitions per job):
     *
     *  - A `files([...])` fan-out (every job ref is `file-{i}`, see
     *    {@see RunResult::isFanoutStatus()}) → {@see RunResult::fromTerminalMultiJob()}
     *    with an empty `keyByRef`, so each input's key is recovered from its
     *    `file-{i}` ref (`"0"`, `"1"`, …).
     *  - Anything else (the single-file {@see \Gisl\Sdk\FileFirst\Recipe} path)
     *    → {@see RunResult::fromTerminalDownloads()} keyed by this handle's
     *    `$key` (the recipe key from a file-first `submit()`, or null on reattach).
     *
     * @param list<JobDownload> $jobDownloads
     */
    private function project(WorkflowStatusResponse $finalStatus, array $jobDownloads): RunResult
    {
        $downloader = new StreamingDownloader();
        if (RunResult::isFanoutStatus($finalStatus)) {
            return RunResult::fromTerminalMultiJob(
                workflowId: $this->workflowId,
                finalStatus: $finalStatus,
                jobDownloads: $jobDownloads,
                keyByRef: [],
                downloader: $downloader,
            );
        }

        // A fluent `files([...])->merge(...)` combine — project ONLY the merged
        // output, filtering the `src_*` passthrough plumbing (which re-exposes
        // the raw inputs). Matches MergedRecipe::run()'s `ref === 'merge'` filter
        // so a submitted/reattached merge handle never surfaces the input
        // artifacts alongside the combined output (codex c1).
        if (RunResult::isMergeStatus($finalStatus)) {
            $mergeDownloads = \array_values(\array_filter(
                $jobDownloads,
                static fn ($d): bool => BuilderInternals::coerceString($d->getRef()) === 'merge',
            ));

            return RunResult::fromTerminalDownloads(
                workflowId: $this->workflowId,
                finalStatus: $finalStatus,
                jobDownloads: $mergeDownloads,
                key: null,
                downloader: $downloader,
            );
        }

        // A fluent `files([...])->archive(...)` bundle — project ONLY the
        // archive output, filtering the `src_*` passthrough plumbing (codex c1
        // mirror for archive).
        if (RunResult::isArchiveStatus($finalStatus)) {
            $archiveDownloads = \array_values(\array_filter(
                $jobDownloads,
                static fn ($d): bool => BuilderInternals::coerceString($d->getRef()) === 'archive',
            ));

            return RunResult::fromTerminalDownloads(
                workflowId: $this->workflowId,
                finalStatus: $finalStatus,
                jobDownloads: $archiveDownloads,
                key: null,
                downloader: $downloader,
            );
        }

        // A fluent `file(...)->watermark(overlay)` — project ONLY the watermark
        // output, filtering the `src_*` (base/overlay) passthrough plumbing.
        // Matches WatermarkedRecipe::run()'s `ref === 'watermark'` filter.
        if (RunResult::isWatermarkStatus($finalStatus)) {
            $watermarkDownloads = \array_values(\array_filter(
                $jobDownloads,
                static fn ($d): bool => BuilderInternals::coerceString($d->getRef()) === 'watermark',
            ));

            return RunResult::fromTerminalDownloads(
                workflowId: $this->workflowId,
                finalStatus: $finalStatus,
                jobDownloads: $watermarkDownloads,
                key: null,
                downloader: $downloader,
            );
        }

        return RunResult::fromTerminalDownloads(
            workflowId: $this->workflowId,
            finalStatus: $finalStatus,
            jobDownloads: $jobDownloads,
            key: $this->key,
            downloader: $downloader,
        );
    }

    /**
     * Plain-array projection. Field order (`workflowId`, then `webhookSecret`
     * when present) and the omit-when-null behaviour are byte-identical to the
     * prior `Handle::toArray()` so the operation-first/merge `submit()` parity
     * fixture holds. The bound client is NEVER serialised.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $out = ['workflowId' => $this->workflowId];
        if ($this->webhookSecret !== null) {
            $out['webhookSecret'] = $this->webhookSecret;
        }

        return $out;
    }

    private function requireClient(): GislClient
    {
        if ($this->client === null) {
            throw new GislConfigError(
                'This handle has no client bound, so it cannot query the workflow. '
                    . 'Use recipe->run() to execute and get a RunResult directly, or reattach via client->workflow(id).',
                reason: 'no_client',
            );
        }

        return $this->client;
    }
}
