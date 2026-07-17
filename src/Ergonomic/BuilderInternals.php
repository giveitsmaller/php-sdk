<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

use Gisl\Generated\OpenApi\Model\SseEventType;
use Gisl\Generated\OpenApi\Model\WorkflowStatusResponse;
use Gisl\Sdk\Cancellation;
use Gisl\Sdk\Errors\GislAbortError;
use Gisl\Sdk\Errors\GislNetworkError;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislSseEvent;

/**
 * @internal
 *
 * Shared helpers for the ergonomic-builder family ({@see OperationBuilder},
 * {@see MergeBuilder}, future builders). Mirrors the TS reference exports
 * `_consumeSseToTerminal` / `_pollToTerminal` / `_checkAborted` /
 * `_parseMaxWait` from `packages/typescript/src/builder.ts` that
 * `packages/typescript/src/merge.ts` imports.
 *
 * Lives in the public namespace so the parity-adapter and unit tests can
 * reach it under PSR-4; the `@internal` annotation marks it as not part of
 * the SDK's public surface — callers MUST NOT depend on these helpers.
 *
 * Every method is `public static`. The instance helpers in
 * {@see OperationBuilder} previously held `private static` versions of the
 * pure helpers and `private` versions of the await-terminal trio; both
 * variants are now consolidated here. {@see OperationBuilder} delegates.
 */
final class BuilderInternals
{
    /**
     * Wall-clock milliseconds since the Unix epoch. PHP's `microtime(true)`
     * returns seconds as float; multiply + cast for ms-int precision.
     */
    public static function nowMs(): int
    {
        return (int) (\microtime(true) * 1_000);
    }

    /**
     * Best-effort probe-before-create for the multipart-video inputs of a
     * multi-input recipe (fan-out / merge / archive). PHP is sequential (no
     * Promise.all), so to keep the aggregate wall-clock bounded by the TOTAL
     * budget rather than N×timeout, a shared budget is tracked: each
     * {@see GislClient::maybeWaitForVideoProbe()} call gets a per-call
     * `timeoutMs = max(0, total - elapsed)`. Never-bounce — a give-up just
     * proceeds; genuine failures / a cancelled token propagate.
     *
     * The TOTAL budget is CAPPED to the remaining `$deadlineMs` (maxWait) budget
     * when a deadline is set (the `run()` path), so the waits cannot push
     * createWorkflow past the caller's deadline: an UNSET `$probeTimeoutMs`
     * under a deadline becomes the remaining budget (never the 30000 default),
     * and a set value is clamped to it. When `$deadlineMs` is null (the
     * `submit()` fire-and-forget path), the total is `$probeTimeoutMs` (default
     * 30000), uncapped.
     *
     * Mirrors the TS reference's concurrent `Promise.all` of per-file waits
     * (each bounded by the same capped timeout, so wall-clock stays ~timeout).
     *
     * @param list<array{fileId: string, isVideo: bool, sizeBytes: int|null}> $probeTargets
     */
    public static function waitForVideoProbes(
        GislClient $client,
        array $probeTargets,
        ?bool $probeBeforeCreate,
        ?int $probeTimeoutMs,
        ?Cancellation $cancellation,
        ?int $deadlineMs = null,
    ): void {
        if ($probeTargets === []) {
            return;
        }
        if ($deadlineMs !== null) {
            // Cap the total budget to the remaining maxWait. An UNSET (null)
            // probeTimeoutMs becomes the remaining budget (never the 30s
            // default); a NEGATIVE value clamps to 0 (fires zero probes), not
            // the default — distinct from null.
            $remainingDeadlineMs = \max(0, $deadlineMs - self::nowMs());
            $totalMs = $probeTimeoutMs === null
                ? $remainingDeadlineMs
                : \min(\max(0, $probeTimeoutMs), $remainingDeadlineMs);
        } else {
            $totalMs = $probeTimeoutMs === null ? 30_000 : \max(0, $probeTimeoutMs);
        }
        $startMs = self::nowMs();
        foreach ($probeTargets as $target) {
            $remainingMs = \max(0, $totalMs - (self::nowMs() - $startMs));
            // Budget exhausted — skip the remaining probes entirely (a 0-budget
            // wait would just return immediately after the pre-request check).
            if ($remainingMs <= 0) {
                break;
            }
            $client->maybeWaitForVideoProbe(
                $target['fileId'],
                $probeBeforeCreate ?? true,
                $target['isVideo'],
                $target['sizeBytes'],
                $remainingMs,
                $cancellation,
            );
        }
    }

    /**
     * Cap a best-effort probe-before-create timeout to the remaining `maxWait`
     * budget so the probe wait can never push createWorkflow past the caller's
     * deadline. Mirrors the TS `_cappedProbeTimeoutMs`. Under a deadline an
     * UNSET `$probeTimeoutMs` becomes the remaining budget (never the 30000
     * waitForProbe default); a set value is clamped to it. With no deadline (the
     * `submit()` path) `$probeTimeoutMs` passes through unchanged.
     */
    public static function cappedProbeTimeoutMs(?int $probeTimeoutMs, ?int $deadlineMs): ?int
    {
        if ($deadlineMs === null) {
            return $probeTimeoutMs;
        }
        $remainingMs = \max(0, $deadlineMs - self::nowMs());
        return $probeTimeoutMs !== null ? \min($probeTimeoutMs, $remainingMs) : $remainingMs;
    }

    /**
     * Cooperative-cancellation checkpoint. Throws {@see GislAbortError} when the
     * caller's {@see Cancellation} token has been cancelled. A null token (the
     * default) is a no-op. Call this at the same boundaries the `maxWait`
     * deadline is checked — before/after uploads, before workflow creation, and
     * between SSE frames / poll iterations.
     */
    public static function throwIfCancelled(?Cancellation $cancellation, string $context): void
    {
        if ($cancellation !== null && $cancellation->isCancelled()) {
            throw new GislAbortError("Cancelled before {$context}.");
        }
    }

    /**
     * Coerce a generated-model getter return value to a non-null string.
     * The openapi-generator declares enum-class getters as returning e.g.
     * `\…\OperationType` (an object type) but at runtime they yield raw
     * strings (the const values). PHPStan rejects `(string) $x` on those
     * declared object types; this helper bridges via `is_string` + cast.
     */
    public static function coerceString(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }
        if ($value === null) {
            return '';
        }
        return \is_scalar($value) ? (string) $value : '';
    }

    public static function coerceNullableString(mixed $value): ?string
    {
        return $value === null ? null : self::coerceString($value);
    }

    /**
     * Format a generated-model timestamp as UTC ISO-8601 with millisecond
     * precision and `Z` suffix (e.g. `2026-05-27T11:00:00.123Z`). Mirrors
     * `Date.toISOString()` in the TS reference at
     * `packages/typescript/src/builder.ts:769-773` — `RFC3339` would
     * silently diverge (`+00:00` suffix, no millis).
     */
    public static function formatIso8601Utc(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            $utc = $value->getTimezone()->getName() === 'UTC'
                ? $value
                : (new \DateTimeImmutable())
                    ->setTimestamp($value->getTimestamp())
                    ->setTimezone(new \DateTimeZone('UTC'));
            return $utc->format('Y-m-d\TH:i:s.v\Z');
        }
        return self::coerceNullableString($value);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function projectProcessingProgress(array $data): ProcessingProgressEvent
    {
        return new ProcessingProgressEvent(
            progress: (float) ($data['progress'] ?? 0.0),
            jobRef: (string) ($data['job_ref'] ?? $data['jobRef'] ?? ''),
            operationId: (string) ($data['operation_id'] ?? $data['operationId'] ?? ''),
            status: isset($data['status']) ? (string) $data['status'] : null,
            stage: isset($data['stage']) ? (string) $data['stage'] : null,
            phaseInputIndex: isset($data['phase_input_index']) ? (int) $data['phase_input_index']
                : (isset($data['phaseInputIndex']) ? (int) $data['phaseInputIndex'] : null),
            phaseTotalInputs: isset($data['phase_total_inputs']) ? (int) $data['phase_total_inputs']
                : (isset($data['phaseTotalInputs']) ? (int) $data['phaseTotalInputs'] : null),
        );
    }

    public static function callableOrNull(mixed $value, string $label): ?\Closure
    {
        if ($value === null) {
            return null;
        }
        if (!\is_callable($value)) {
            throw new \InvalidArgumentException("{$label} must be callable when set.");
        }
        return \Closure::fromCallable($value);
    }

    /**
     * Wait for `$workflowId` to reach a terminal status. SSE-first when
     * `$useSSE` is true with a clean fallback to poll on network failure
     * or clean stream-ended-without-terminal. Throws {@see GislTimeoutError}
     * if `$deadlineMs` elapses.
     *
     * @param \Closure(ProgressEvent): void|null $onProgress
     */
    public static function awaitTerminal(
        GislClient $client,
        string $workflowId,
        int $deadlineMs,
        ?\Closure $onProgress,
        bool $useSSE,
        ?int $pollIntervalMs,
        ?Cancellation $cancellation = null,
    ): WorkflowStatusResponse {
        if ($useSSE) {
            try {
                return self::consumeSseToTerminal($client, $workflowId, $deadlineMs, $onProgress, $cancellation);
            } catch (GislTimeoutError $e) {
                // Caller-deadline elapsed during SSE — propagate directly,
                // do NOT fall back to poll (the deadline is already done).
                throw $e;
            } catch (GislNetworkError $e) {
                // PSR-18 transport failed mid-SSE — try poll.
            } catch (SseStreamEndedWithoutTerminal $e) {
                // Clean server close with no terminal frame — try poll.
            }
            // Anything else (GislApiError subclasses for 401/402/etc.,
            // caller `onProgress` exceptions, framework errors)
            // PROPAGATES. Specifically: `GislError extends
            // \RuntimeException`, so a bare `\RuntimeException` arm
            // would silently swallow auth/balance/feature errors and
            // re-issue the same doomed request via poll (codex r2 high
            // 93a6f1be1fcd / round-2 reaffirmation).
        }
        return self::pollToTerminal($client, $workflowId, $deadlineMs, $pollIntervalMs, $cancellation);
    }

    /**
     * @param \Closure(ProgressEvent): void|null $onProgress
     */
    public static function consumeSseToTerminal(
        GislClient $client,
        string $workflowId,
        int $deadlineMs,
        ?\Closure $onProgress,
        ?Cancellation $cancellation = null,
    ): WorkflowStatusResponse {
        self::throwIfCancelled($cancellation, "SSE wait for workflow {$workflowId}");
        if (self::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError(
                "Workflow {$workflowId} did not complete before maxWait deadline.",
                $workflowId,
            );
        }

        // Wire SSE event names use dot notation (per
        // `generated/php/openapi/lib/Model/SseEventType.php` and the
        // OpenAPI spec at v2.15.3). Hand-typing underscore strings
        // (`workflow_completed` etc.) silently mismatched the wire and
        // run() would never see a terminal SSE frame — fall through to
        // poll on every invocation. Codex r2 high 1a0/3a0 caught this.
        $terminalEvents = [
            SseEventType::WORKFLOW_COMPLETED,
            SseEventType::WORKFLOW_FAILED,
            SseEventType::WORKFLOW_PARTIALLY_FAILED,
        ];
        $events = $client->streamEvents($workflowId);
        foreach ($events as $event) {
            /** @var GislSseEvent $event */
            if ($onProgress !== null && $event->event === SseEventType::OPERATION_PROGRESS && \is_array($event->data)) {
                $onProgress(self::projectProcessingProgress($event->data));
            }
            if (\in_array($event->event, $terminalEvents, true)) {
                return $client->getWorkflowStatus($workflowId);
            }
            self::throwIfCancelled($cancellation, "SSE wait for workflow {$workflowId}");
            if (self::nowMs() >= $deadlineMs) {
                throw new GislTimeoutError(
                    "Workflow {$workflowId} did not complete before maxWait deadline.",
                    $workflowId,
                );
            }
        }

        // Stream ended cleanly without terminal — the poll fallback in
        // awaitTerminal() takes over. Use a SEALED marker exception so
        // the caller's GislApiError / onProgress exceptions cannot be
        // confused with this transport-level outcome (codex r2 high
        // 93a6f1be1fcd reaffirmation).
        throw new SseStreamEndedWithoutTerminal(
            "SSE stream ended for workflow {$workflowId} without terminal event.",
        );
    }

    public static function pollToTerminal(
        GislClient $client,
        string $workflowId,
        int $deadlineMs,
        ?int $pollIntervalMs,
        ?Cancellation $cancellation = null,
    ): WorkflowStatusResponse {
        // Codex TS r1 medium 89130e3ea75d — guard against 0/negative/NaN
        // pollIntervalMs that would hammer getWorkflowStatus.
        if ($pollIntervalMs === null) {
            $intervalMs = 2_000;
        } elseif ($pollIntervalMs < 100) {
            $intervalMs = 100;
        } else {
            $intervalMs = $pollIntervalMs;
        }

        $terminal = \Gisl\Sdk\WorkflowConstants::TERMINAL_STATUSES;
        while (true) {
            self::throwIfCancelled($cancellation, "poll wait for workflow {$workflowId}");
            if (self::nowMs() >= $deadlineMs) {
                throw new GislTimeoutError(
                    "Workflow {$workflowId} did not complete before maxWait deadline.",
                    $workflowId,
                );
            }
            $status = $client->getWorkflowStatus($workflowId);
            $statusStr = self::coerceString($status->getStatus());
            if (\in_array($statusStr, $terminal, true)) {
                return $status;
            }
            if (self::nowMs() >= $deadlineMs) {
                throw new GislTimeoutError(
                    "Workflow {$workflowId} did not complete before maxWait deadline.",
                    $workflowId,
                );
            }
            if (self::nowMs() + $intervalMs >= $deadlineMs) {
                throw new GislTimeoutError(
                    "Workflow {$workflowId} did not complete before maxWait deadline.",
                    $workflowId,
                );
            }
            // Abort before sleeping out the interval — a cancel that arrived
            // during the status fetch should not wait a full poll cycle.
            self::throwIfCancelled($cancellation, "poll wait for workflow {$workflowId}");
            \usleep($intervalMs * 1_000);
        }
    }
}
