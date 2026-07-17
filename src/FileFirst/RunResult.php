<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

use Gisl\Generated\OpenApi\Model\JobDownload;
use Gisl\Generated\OpenApi\Model\WorkflowStatusResponse;
use Gisl\Sdk\Ergonomic\BuilderInternals;
use Gisl\Sdk\Errors\GislItemFailedError;
use Gisl\Sdk\Errors\GislNoSuchKeyError;
use Gisl\Sdk\Errors\GislSinkError;

/**
 * Result of a file-first run — the value the file-first layer's `run()` /
 * `Handle::wait()` / `Handle::result()` return (producers land FF2b/FF5).
 *
 * Coexists with the operation-first {@see \Gisl\Sdk\Ergonomic\Result}
 * (different FQCN) until FF6 removes the operation-first layer. The
 * file-first shape is flatter and adds an always-present per-input
 * partition (`succeeded` / `failed`) so one bad input in a multi-input run
 * doesn't sink the rest.
 *
 * Mirrors the TS `RunResult` class in `packages/typescript/src/file-first.ts`.
 *
 * Field notes:
 *  - `url`: single-output sugar — the lone artifact's URL when exactly one
 *    output exists, else null.
 *  - `ok`: true iff `failed` is empty. (NOT a list — the partition lists are
 *    `succeeded` / `failed`; resolves the design doc's `ok` bool-vs-list
 *    contradiction.)
 *  - `state`: lifecycle state (`completed` | `failed` | ...). Named `state`,
 *    NOT `status`, matching the file-first `StatusSnapshot.state`.
 *  - sinks fetch via the injected {@see Downloader}; a result with no
 *    downloader throws {@see GislSinkError} (reason `downloader_unavailable`).
 */
final class RunResult
{
    /**
     * Job id/ref for the DOWNSTREAM job carrying post-`sole_op` steps. A
     * `sole_op` op (image_watermark / video_watermark / merge — ADR-0025) MUST
     * be the only op in its job, so when a caller chains `compress()` /
     * `convert()` / `thumbnail()` / `transform()` after `watermark()` /
     * `merge()`, those steps lower into this separate job that consumes the
     * sole_op output via `job_output`. When present it is the TERMINAL
     * deliverable, so `run()` / the {@see \Gisl\Sdk\Ergonomic\Handle} project
     * THIS job's output, and the status-shape detectors accept it alongside the
     * sole_op + `src_{i}` refs. Mirrors the TS `_POST_STEP_JOB_REF` in
     * `file-first.ts`. PIiUit28.
     */
    public const POST_STEP_JOB_REF = 'post';

    /** Single-output sugar: the lone artifact's URL, or null for 0 / >1 outputs. */
    public readonly ?string $url;

    /** True iff {@see $failed} is empty. */
    public readonly bool $ok;

    /**
     * Whether any output missed its requested byte target. Derived from the
     * per-output {@see OutputFile::$targetSizeMet}: null when NO artifact
     * reports a target-size outcome (not a target_size run); otherwise true iff
     * some artifact's `targetSizeMet === false`.
     */
    public readonly ?bool $targetSizeMissed;

    /**
     * @param list<OutputFile>  $artifacts All outputs produced by the run.
     * @param list<ItemResult>  $succeeded Per-input successes (always present;
     *                                     one entry for a single-recipe run).
     * @param list<ItemFailure> $failed    Per-input failures (empty on full success).
     * @param Downloader|null   $downloader Streams outputs to disk for the sinks;
     *                                      producers inject it, no-I/O contexts omit it.
     */
    public function __construct(
        public readonly string $workflowId,
        public readonly string $state,
        public readonly array $artifacts,
        public readonly array $succeeded,
        public readonly array $failed,
        private readonly ?Downloader $downloader = null,
    ) {
        $this->url = \count($artifacts) === 1 ? $artifacts[0]->url : null;
        $this->ok = $failed === [];
        // Derive the target-size signal: null when NO artifact reports an
        // outcome (every targetSizeMet null — not a target_size run); otherwise
        // true iff some artifact's targetSizeMet === false. Mirrors the TS
        // RunResult constructor exactly.
        $anyReported = false;
        $anyMissed = false;
        foreach ($artifacts as $artifact) {
            if ($artifact->targetSizeMet !== null) {
                $anyReported = true;
                if ($artifact->targetSizeMet === false) {
                    $anyMissed = true;
                }
            }
        }
        $this->targetSizeMissed = $anyReported ? $anyMissed : null;
    }

    /**
     * Flatten a terminal workflow status + its downloads into a RunResult.
     *
     * Shared by {@see Recipe::run()} (passes its recipe key) and the file-first
     * {@see \Gisl\Sdk\Ergonomic\Handle} reattach surface (`Handle::wait()` /
     * `Handle::result()` — passes `null` because a reattached handle carries no
     * recipe key).
     *
     * **Partition invariant (carries a prior codex-review fix — do NOT let it
     * drift):** success is ONLY `state === 'completed'`. Every other terminal
     * state — `failed`, `partially_failed`, `cancelled`, `expired`,
     * `paused_insufficient_credits` — partitions into `$failed` so a caller's
     * `ok`/`succeeded` check can never treat a cancelled/expired/paused run as a
     * clean result.
     *
     * @internal Consumed by {@see Recipe::run()} and the file-first Handle; not
     *           part of the caller-facing surface.
     *
     * @param list<JobDownload> $jobDownloads
     */
    public static function fromTerminalDownloads(
        string $workflowId,
        WorkflowStatusResponse $finalStatus,
        array $jobDownloads,
        ?string $key,
        ?Downloader $downloader = null,
    ): self {
        // Flatten to the lean OutputFile[] (the four file-first fields only).
        $artifacts = [];
        foreach ($jobDownloads as $job) {
            foreach ($job->getFiles() ?? [] as $file) {
                $artifacts[] = new OutputFile(
                    url: BuilderInternals::coerceString($file->getDownloadUrl()),
                    filename: BuilderInternals::coerceString($file->getFilename()),
                    sizeBytes: (int) ($file->getSizeBytes() ?? 0),
                    operation: BuilderInternals::coerceString($file->getOperation()),
                    chosenQuality: $file->getChosenQuality(),
                    targetSizeMet: $file->getTargetSizeMet(),
                    measuredQuality: $file->getMeasuredQuality(),
                    qualityMetric: $file->getQualityMetric(),
                );
            }
        }

        $state = BuilderInternals::coerceString($finalStatus->getStatus());
        if ($state === 'completed') {
            $succeeded = [new ItemResult($key, $artifacts)];
            $failed = [];
        } else {
            // First failing op across ALL jobs (downloads path is whole-workflow
            // scoped); read errorMessage AND errorCode from the SAME op.
            $errorMessage = null;
            $errorCode = null;
            foreach ($finalStatus->getJobs() ?? [] as $job) {
                foreach ($job->getOperations() ?? [] as $op) {
                    if ($op->getErrorMessage() !== null || $op->getErrorCode() !== null) {
                        $errorMessage = $op->getErrorMessage();
                        $errorCode = $op->getErrorCode();
                        break 2;
                    }
                }
            }
            $succeeded = [];
            $failed = [new ItemFailure(
                $key,
                new GislItemFailedError($key, $state, $errorMessage, $errorCode),
            )];
        }

        return new self(
            workflowId: $workflowId,
            state: $state,
            artifacts: $artifacts,
            succeeded: $succeeded,
            failed: $failed,
            downloader: $downloader,
        );
    }

    /**
     * Flatten a terminal MULTI-JOB workflow (the `$client->files([...])`
     * fan-out) into a partitioned RunResult — one job per input file, keyed by
     * the `file-{i}` job ref the {@see FilesRecipe} lowering assigns. The
     * `succeeded` / `failed` partition is PER JOB, so one bad input does not
     * sink the rest.
     *
     * Join model: `$finalStatus->getJobs()` carries each job's status +
     * `operations[]` (for the error message); `$jobDownloads` carries each
     * job's output files. Both are joined on the job `ref` ("file-{i}"); the
     * partition key is the index `"{i}"` parsed out of that ref (or the
     * caller-supplied key in `$keyByRef`). The flat `artifacts[]` is every
     * job's outputs in job order.
     *
     * **Partition invariant (mirrors {@see fromTerminalDownloads()} PER JOB —
     * do NOT let it drift):** a job is a SUCCESS only when its status
     * `=== 'completed'`. Any other per-job status partitions that job into
     * `$failed` (with that job's first operation error message, scoped to THAT
     * job only).
     *
     * @internal Consumed by {@see FilesRecipe::run()}; not part of the
     *           caller-facing surface.
     *
     * @param list<JobDownload>          $jobDownloads
     * @param array<string, string|null> $keyByRef Map of job ref => partition key.
     */
    public static function fromTerminalMultiJob(
        string $workflowId,
        WorkflowStatusResponse $finalStatus,
        array $jobDownloads,
        array $keyByRef,
        ?Downloader $downloader = null,
    ): self {
        // Group downloads by job ref so a job's outputs are flattened AFTER the
        // per-job partition is decided (grouping is unrecoverable post-flatten).
        $filesByRef = [];
        foreach ($jobDownloads as $job) {
            $filesByRef[BuilderInternals::coerceString($job->getRef())] = $job->getFiles() ?? [];
        }

        $artifacts = [];
        $succeeded = [];
        $failed = [];

        foreach ($finalStatus->getJobs() ?? [] as $job) {
            $ref = BuilderInternals::coerceString($job->getRef());
            $key = $keyByRef[$ref] ?? self::jobIndexFromRef($ref);

            $outputs = [];
            foreach ($filesByRef[$ref] ?? [] as $file) {
                $outputs[] = new OutputFile(
                    url: BuilderInternals::coerceString($file->getDownloadUrl()),
                    filename: BuilderInternals::coerceString($file->getFilename()),
                    sizeBytes: (int) ($file->getSizeBytes() ?? 0),
                    operation: BuilderInternals::coerceString($file->getOperation()),
                    chosenQuality: $file->getChosenQuality(),
                    targetSizeMet: $file->getTargetSizeMet(),
                    measuredQuality: $file->getMeasuredQuality(),
                    qualityMetric: $file->getQualityMetric(),
                );
            }
            // The flat artifacts[] keeps every job's outputs in job order.
            foreach ($outputs as $output) {
                $artifacts[] = $output;
            }

            $status = BuilderInternals::coerceString($job->getStatus());
            if ($status === 'completed') {
                $succeeded[] = new ItemResult($key, $outputs);
            } else {
                // Per-job scoped: read the error from THIS job's ops only.
                $errorMessage = null;
                $errorCode = null;
                foreach ($job->getOperations() ?? [] as $op) {
                    if ($op->getErrorMessage() !== null || $op->getErrorCode() !== null) {
                        $errorMessage = $op->getErrorMessage();
                        $errorCode = $op->getErrorCode();
                        break;
                    }
                }
                $failed[] = new ItemFailure(
                    $key,
                    new GislItemFailedError($key, $status, $errorMessage, $errorCode),
                );
            }
        }

        return new self(
            workflowId: $workflowId,
            state: BuilderInternals::coerceString($finalStatus->getStatus()),
            artifacts: $artifacts,
            succeeded: $succeeded,
            failed: $failed,
            downloader: $downloader,
        );
    }

    /** Derive the partition key `"{i}"` from a `file-{i}` job ref; the ref verbatim otherwise. */
    private static function jobIndexFromRef(string $ref): string
    {
        return \str_starts_with($ref, 'file-') ? \substr($ref, \strlen('file-')) : $ref;
    }

    /**
     * True when a terminal status describes a homogeneous `files([...])`
     * fan-out — at least one job and EVERY job ref is `file-{i}` (the ids the
     * {@see \Gisl\Sdk\FileFirst\FilesRecipe} lowering assigns). A single-file
     * {@see Recipe} omits the job id, so its job carries a non-`file-N` ref and
     * this is false.
     *
     * The data-driven seam that lets {@see \Gisl\Sdk\Ergonomic\Handle::wait()}/
     * `result()` pick the per-job producer ({@see fromTerminalMultiJob}) over
     * the single-output one for a fan-out WITHOUT a construction-time marker —
     * so a fan-out reattached via `client->workflow(id)` still partitions per
     * job. Mirrors the TS `isFanoutStatus` in `file-first.ts`.
     */
    public static function isFanoutStatus(WorkflowStatusResponse $finalStatus): bool
    {
        $jobs = $finalStatus->getJobs() ?? [];
        if ($jobs === []) {
            return false;
        }
        foreach ($jobs as $job) {
            if (\preg_match('/^file-\d+$/', BuilderInternals::coerceString($job->getRef())) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * True when a terminal status describes a fluent `files([...])->merge(...)`
     * combine — at least one job ref `merge` and every OTHER job ref is
     * `src_{i}` (the ids the {@see \Gisl\Sdk\FileFirst\MergedRecipe} lowering
     * assigns). Lets the {@see \Gisl\Sdk\Ergonomic\Handle} project ONLY the
     * merged output (filtering the `src_*` passthrough plumbing) even after a
     * `client->workflow(id)` reattach. Mutually exclusive with
     * {@see isFanoutStatus} (a fan-out's refs are all `file-{i}`). Mirrors the
     * TS `isMergeStatus` in `file-first.ts`.
     */
    public static function isMergeStatus(WorkflowStatusResponse $finalStatus): bool
    {
        $jobs = $finalStatus->getJobs() ?? [];
        if ($jobs === []) {
            return false;
        }
        $hasMerge = false;
        foreach ($jobs as $job) {
            $ref = BuilderInternals::coerceString($job->getRef());
            if ($ref === 'merge') {
                $hasMerge = true;
                continue;
            }
            // The downstream post-`sole_op` steps job (PIiUit28) is part of a merge DAG.
            if ($ref === self::POST_STEP_JOB_REF) {
                continue;
            }
            if (\preg_match('/^src_\d+$/', $ref) !== 1) {
                return false;
            }
        }

        return $hasMerge;
    }

    /**
     * True when a terminal status describes a fluent `files([...])->archive(...)`
     * bundle — at least one job ref `archive` and every OTHER job ref is
     * `src_{i}` (the ids the {@see \Gisl\Sdk\FileFirst\ArchivedRecipe} lowering
     * assigns). Lets the {@see \Gisl\Sdk\Ergonomic\Handle} project ONLY the
     * archive output (filtering the `src_*` passthrough plumbing) even after a
     * `client->workflow(id)` reattach. Mirrors the TS `isArchiveStatus`.
     */
    public static function isArchiveStatus(WorkflowStatusResponse $finalStatus): bool
    {
        $jobs = $finalStatus->getJobs() ?? [];
        if ($jobs === []) {
            return false;
        }
        $hasArchive = false;
        foreach ($jobs as $job) {
            $ref = BuilderInternals::coerceString($job->getRef());
            if ($ref === 'archive') {
                $hasArchive = true;
                continue;
            }
            if (\preg_match('/^src_\d+$/', $ref) !== 1) {
                return false;
            }
        }

        return $hasArchive;
    }

    /**
     * True when a terminal status describes a fluent `file(...)->watermark(overlay)`
     * — at least one job ref `watermark` and every OTHER job ref is `src_{i}`
     * (the ids the {@see \Gisl\Sdk\FileFirst\WatermarkedRecipe} lowering assigns:
     * `src_0` base, `src_1` overlay). Lets {@see \Gisl\Sdk\Ergonomic\Handle} AND
     * {@see \Gisl\Sdk\FileFirst\WatermarkedRecipe::run()} project ONLY the
     * watermark output (filtering the `src_*` passthrough plumbing) even after a
     * `client->workflow(id)` reattach. Mirrors the TS `isWatermarkStatus`.
     */
    public static function isWatermarkStatus(WorkflowStatusResponse $finalStatus): bool
    {
        $jobs = $finalStatus->getJobs() ?? [];
        if ($jobs === []) {
            return false;
        }
        $hasWatermark = false;
        foreach ($jobs as $job) {
            $ref = BuilderInternals::coerceString($job->getRef());
            if ($ref === 'watermark') {
                $hasWatermark = true;
                continue;
            }
            // The downstream post-`sole_op` steps job (PIiUit28) is part of a watermark DAG.
            if ($ref === self::POST_STEP_JOB_REF) {
                continue;
            }
            if (\preg_match('/^src_\d+$/', $ref) !== 1) {
                return false;
            }
        }

        return $hasWatermark;
    }

    /**
     * Address a succeeded input by the `key:` given to `file()`. Duplicate
     * keys are not valid input — the producer enforces key uniqueness (a
     * later ticket); the first match is returned.
     *
     * @throws GislNoSuchKeyError when no succeeded entry has that key (a
     *         keyless run always throws — it is positionally addressable only).
     */
    public function byKey(string $key): ItemResult
    {
        foreach ($this->succeeded as $item) {
            if ($item->key === $key) {
                return $item;
            }
        }
        throw new GislNoSuchKeyError("No result for key '{$key}'.");
    }

    /**
     * Write the single output to `$path`. Requires EXACTLY ONE artifact.
     *
     * @throws GislSinkError reason `not_single_output` when the run produced
     *         0 or >1 outputs; reason `downloader_unavailable` when no
     *         downloader is bound.
     */
    public function toFile(string $path): void
    {
        if (\count($this->artifacts) !== 1) {
            throw new GislSinkError(
                'toFile() requires exactly one output; this run produced '
                    . \count($this->artifacts) . '. Use downloadTo() for multi-output runs.',
                reason: 'not_single_output',
            );
        }
        $this->requireDownloader()->downloadTo($this->artifacts[0]->url, $path);
    }

    /**
     * Download every output into `$dir` (filename per output), in output
     * order. Returns the {@see Manifest} of local paths written.
     *
     * @throws GislSinkError reason `partial_failure` when `$failOnPartial`
     *         and the run had failed inputs; reason `downloader_unavailable`
     *         when no downloader is bound.
     */
    public function downloadTo(string $dir, bool $failOnPartial = false): Manifest
    {
        if ($failOnPartial && $this->failed !== []) {
            throw new GislSinkError(
                'downloadTo(failOnPartial: true) but the run had '
                    . \count($this->failed) . ' failed input(s).',
                reason: 'partial_failure',
            );
        }
        if ($dir === '') {
            throw new GislSinkError(
                "downloadTo(): the directory argument is empty. Pass a target directory "
                    . "(use '.' for the current directory).",
                reason: 'invalid_directory',
            );
        }
        $downloader = $this->requireDownloader();
        $base = rtrim($dir, '/\\');
        // Resolve destinations first so a basename collision fails loudly BEFORE
        // any byte is written — silently overwriting an earlier output is data loss.
        // basename() also strips any directory component from a server-supplied
        // filename so a value like "../x" or "a/b" cannot escape $dir.
        $names = array_map(
            static fn (OutputFile $a): string => basename($a->filename),
            $this->artifacts,
        );
        // Collision key is case-folded: many destination filesystems (macOS,
        // NTFS) are case-insensitive, so "a.jpg" and "A.jpg" hit the same file.
        $seen = [];
        foreach ($names as $name) {
            $key = strtolower($name);
            if (isset($seen[$key])) {
                throw new GislSinkError(
                    "downloadTo(): two outputs resolve to the same filename '{$name}' in "
                        . "'{$dir}' (case-insensitively). Download them to separate directories.",
                    reason: 'duplicate_filename',
                );
            }
            $seen[$key] = true;
        }
        $paths = [];
        foreach ($this->artifacts as $i => $artifact) {
            $dest = $base . \DIRECTORY_SEPARATOR . $names[$i];
            $downloader->downloadTo($artifact->url, $dest);
            $paths[] = $dest;
        }
        return new Manifest($paths);
    }

    /**
     * Plain-array projection. Field order fixed to match the TS reference
     * for the cross-language shape assertion (FF1) + harness fixture (FF2b).
     *
     * @return array{
     *     workflowId: string,
     *     state: string,
     *     ok: bool,
     *     targetSizeMissed?: bool,
     *     url?: string,
     *     artifacts: list<array{url: string, filename: string, sizeBytes: int, operation: string, chosenQuality?: int, targetSizeMet?: bool, measuredQuality?: float, qualityMetric?: string}>,
     *     succeeded: list<array{key: string|null, outputs: list<array{url: string, filename: string, sizeBytes: int, operation: string, chosenQuality?: int, targetSizeMet?: bool, measuredQuality?: float, qualityMetric?: string}>}>,
     *     failed: list<array{key: string|null, error: string, state: string, errorMessage?: string, errorCode?: string}>
     * }
     */
    public function toArray(): array
    {
        $out = [
            'workflowId' => $this->workflowId,
            'state' => $this->state,
            'ok' => $this->ok,
        ];
        // Omit `targetSizeMissed` when null so non-target-size runs keep the
        // common-case shape (TS omits undefined). Emitted immediately after
        // `ok`, before `url`, to match the TS toJSON() field order.
        if ($this->targetSizeMissed !== null) {
            $out['targetSizeMissed'] = $this->targetSizeMissed;
        }
        // Omit `url` when null so the serialised shape matches the TS
        // reference, where `undefined` is dropped by `JSON.stringify` (same
        // convention as Ergonomic\Artifact::toArray()).
        if ($this->url !== null) {
            $out['url'] = $this->url;
        }
        return $out + [
            'artifacts' => array_map(
                static fn (OutputFile $o): array => $o->toArray(),
                $this->artifacts,
            ),
            'succeeded' => array_map(
                static fn (ItemResult $i): array => $i->toArray(),
                $this->succeeded,
            ),
            'failed' => array_map(
                static fn (ItemFailure $f): array => $f->toArray(),
                $this->failed,
            ),
        ];
    }

    private function requireDownloader(): Downloader
    {
        if ($this->downloader === null) {
            throw new GislSinkError(
                'This result has no downloader bound, so its outputs cannot be '
                    . 'written to disk here (e.g. a browser / no-I/O context). '
                    . 'Fetch each output from its URL instead.',
                reason: 'downloader_unavailable',
            );
        }
        return $this->downloader;
    }
}
