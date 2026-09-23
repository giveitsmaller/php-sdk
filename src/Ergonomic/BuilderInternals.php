<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

use Gisl\Generated\OpenApi\Model\SseEventType;
use Gisl\Generated\OpenApi\Model\WorkflowStatusResponse;
use Gisl\Sdk\Cancellation;
use Gisl\Sdk\Errors\GislAbortError;
use Gisl\Sdk\Errors\GislApiError;
use Gisl\Sdk\Errors\GislNetworkError;
use Gisl\Sdk\Errors\GislStreamHostNotDeclaredError;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislSseEvent;
use Gisl\Sdk\Http\RateLimitHeaders;

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
     * Poll-fallback interval bounds, in milliseconds. Deliberate mirror of
     * MIN_POLL_INTERVAL_MS / DEFAULT_POLL_INTERVAL_MS in
     * packages/typescript/src/builder.ts — the two languages move together.
     *
     * 🔴 THE FLOOR IS SIZED AGAINST A PUBLISHED RATE LIMIT, NOT AGAINST A
     * BUSY-LOOP. The previous value was 100 and its comment said it guarded
     * against values "that would hammer getWorkflowStatus" — what you write
     * when stopping a 0/NaN spin, not when you have asked what the server
     * allows. Same words, different standard (r7bpd7MY).
     *
     * compression_api, read rather than relayed:
     * Identity/Application/RateLimiting/TieredRateLimiterService.php:37-39
     * declares `status_poll` — guarding GET /api/workflows/{id}/status and
     * /downloads — as a SLIDING WINDOW of 60 requests per minute, scaled at
     * consume time (:177, :188) by UserTier::rateLimitMultiplier()
     * (Identity/Domain/Enums/UserTier.php:186-194): Free and Basic x1, Pro x5,
     * Max x15, Enterprise x20.
     *
     * => 100 ms is 600 requests/minute: ten times the Free ceiling, twice
     * Pro's, inside budget only on Max and Enterprise. 1000 ms is the minimum
     * legal interval on the tightest tier, so it is correct on every tier and
     * needs no tier knowledge in the SDK.
     *
     * ⚠️ The window is SLIDING, so it punishes bursts, not just averages, and
     * the budget is keyed per user id (per IP when anonymous) — several SDK
     * instances under one account share one allowance.
     */
    public const MIN_POLL_INTERVAL_MS = 1_000;
    public const DEFAULT_POLL_INTERVAL_MS = 2_000;
    /**
     * Wall-clock milliseconds since the Unix epoch. PHP's `microtime(true)`
     * returns seconds as float; multiply + cast for ms-int precision.
     */
    public static function nowMs(): int
    {
        return self::$clockForTesting !== null
            ? (self::$clockForTesting)()
            : (int) (\microtime(true) * 1_000);
    }

    /** @var (\Closure(): int)|null */
    private static ?\Closure $clockForTesting = null;

    /**
     * Swap the clock `nowMs()` reads (Vf9R7gcV's cooldown tests move time
     * without sleeping). Pass null to restore wall-clock time.
     *
     * @param (\Closure(): int)|null $clock
     */
    public static function setClockForTesting(?\Closure $clock): void
    {
        self::$clockForTesting = $clock;
    }

    /**
     * Per-CLIENT SSE cooldown after a refused connect (Vf9R7gcV).
     *
     * contracts v2.208.0 REQUIRES `Retry-After` on the events 429
     * (`sse_connection_limit_exceeded`, 5 open streams per caller) and 503
     * (`sse_capacity_exhausted`), and says a client MUST NOT request another
     * stream before it elapses. Within one run that already held; ACROSS runs
     * on the same client it did not, so the next run() or Handle wait
     * reconnected at once.
     *
     * Keyed by the client's `sseCooldownKey`, which a `clone` shares, so a
     * derived client is the same caller as its parent; two independently
     * constructed clients are two callers. A WeakMap, so a discarded client
     * takes its entry with it. No `Retry-After` (an API older
     * than v2.208.0) records NOTHING: there is no window to honour. A DIRECT
     * `streamEvents()` caller is untouched. Mirrors TS `sseCooldowns`.
     *
     * @var \WeakMap<object, array{untilMs: int, refusal: GislApiError}>|null
     */
    private static ?\WeakMap $sseCooldowns = null;

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
            } catch (SseConnectRefused $e) {
                // 3OVNoRxh: the connect was refused with a retryable status —
                // SSE is momentarily unavailable, not a failure of the thing
                // this caller asked for. `$e->refusal` keeps the original
                // GislApiError if anyone needs it.
            } catch (GislStreamHostNotDeclaredError $e) {
                // VUozk5Bc: no stream host is DECLARED for this configuration
                // (a configuration nothing declares; both named environments
                // resolve as of contracts v2.195.0). That is not a
                // failure to recover from, it is SSE being unavailable here,
                // and polling is a working transport. Failing hard instead
                // would strand every caller on a host nobody has declared yet.
                // A DIRECT streamEvents() caller still gets the hard error —
                // they asked for the stream specifically; a run() caller asked
                // for a result.
                //
                // ⚠️ MUST sit BELOW the GislTimeoutError arm and above nothing
                // that matters: it is a GislConfigError, so it would otherwise
                // fall through to the propagate-everything-else rule below.
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
        // 🔴 WHY FIRST-TERMINAL IS CORRECT, AND IT IS NOT OBVIOUS (Fk8FWeyO).
        // contracts v2.193.0 declares that a second terminal OperationResult
        // can arrive for the same operation_id and that `completed` SUPERSEDES
        // `failed`, whichever order they arrive in. Read on its own that makes
        // stopping at the first terminal event look like a bug — and the
        // contract says so itself: "a consumer that treats a workflow-level
        // `failed` as final is CORRECT per this contract and may still be wrong
        // in fact".
        //
        // ⇒ IT IS NOT WRONG IN FACT, AND THE REASON IS AN ORDERING IN api.
        // MEASURED BY api AT THEIR main, 2026-09-18 (RELAYED — not re-run here):
        //   · WorkflowStatus::canTransitionTo() returns FALSE for every target
        //     from Completed/Failed/PartiallyFailed/Cancelled/Expired, so a
        //     terminal workflow never returns to non-terminal;
        //   · OperationResultHandler calls $operation->canRetry() and SCHEDULES
        //     THE RETRY *BEFORE* $job->fail(). On that path a job — and so the
        //     workflow — reaches `failed` only once retries are EXHAUSTED or the
        //     failure was non-retryable.
        // ⇒ On the AUTOMATIC retry path there is no window in which a superseding
        // operation.completed can land after a workflow-level `failed`. Nothing to
        // wait for, nothing to poll for, and no latency on a successful run.
        //
        // 🔴 THE SCOPE OF THAT CLAIM IS THE AUTOMATIC PATH, AND NOT MORE. api's
        // measurement said "cannot arise on the AUTOMATIC path"; an earlier draft
        // of this comment DROPPED THAT QUALIFIER and asserted a universal "no
        // window", which a reviewer rejected by pointing at
        // RetryOperationCommandHandler — the MANUAL `POST /retry` route, which the
        // ordering above does not cover and which has not been measured here.
        //
        // ⚠️ WHAT THAT MEANS IN PRACTICE, STATED RATHER THAN GLOSSED: a caller
        // inside run() is waiting on a workflow nobody has manually retried yet —
        // a manual retry is something a human or another service does AFTER being
        // told it failed. So the stop condition is right for run(). It is NOT a
        // licence to treat "first terminal" as universally final elsewhere.
        //
        // ⚠️ THE OTHER BOUNDARY: this rests on api's ordering, not on the contract
        // — the contract leaves the parent explicitly undecided
        // (asyncapi/events.yaml, "THE PARENT IS NOT COVERED BY THIS RULE, AND THAT
        // IS A GAP"). If api ever fails a job BEFORE scheduling its retry, the
        // window opens on the automatic path too and Fk8FWeyO comes back.
        $terminalEvents = [
            SseEventType::WORKFLOW_COMPLETED,
            SseEventType::WORKFLOW_FAILED,
            SseEventType::WORKFLOW_PARTIALLY_FAILED,
        ];
        // 3OVNoRxh: a REFUSED connect (429 on the `events_stream` bucket, or a
        // 503) is declared retryable by the contract and clears when another
        // caller closes a stream. Wrap it so awaitTerminal() can poll instead
        // of handing a run() caller a hard failure for a transport they never
        // asked about.
        //
        // ⚠️ `retryable()`, NOT a literal 429/503 list — that accessor already
        // encodes 408/429/5xx PLUS the generated taxonomy's own flag, and a
        // second copy of the rule here is the one that would go stale.
        //
        // 🔴 THE NARROWING IS THE FEATURE. A 401/402/404 is not retryable, so
        // it still propagates untouched; re-issuing the same doomed request as
        // a poll would mask the real failure.
        //
        // ⚠️ ONLY the connect is wrapped. The `getWorkflowStatus()` call below
        // can return the same statuses, and sweeping it in would be harmless by
        // luck rather than by design.
        // ⚠️ THE CALL ITSELF THROWS, AND THAT IS NOT OBVIOUS FROM THE SIGNATURE.
        // `streamEvents()` is declared `: \Generator`, which usually means a lazy
        // body — but it contains NO `yield`. It performs the request eagerly,
        // dispatches a non-2xx through `unwrapEnvelope()`, and only then RETURNS
        // the generator from `parseSseStream()`. So the connect, and its
        // refusal, happen here.
        //
        // 🔴 MEASURED, BECAUSE I GOT IT BACKWARDS FIRST. I assumed laziness and
        // wrapped a priming `$events->rewind()` instead — and the 429 escaped
        // the catch entirely, because there was nothing left to prime. The
        // backtrace was no help: it framed the throw at the generator's creation
        // line either way. `: \Generator` is a RETURN TYPE, not a promise of
        // deferral; only the presence of `yield` decides that.
        //
        // ⚠️ AND THE CALL IS ALONE IN THE TRY ON PURPOSE. Wrapping the `foreach`
        // below would put the `getWorkflowStatus()` call inside the same try —
        // and that call can return the very statuses this arm treats as "SSE
        // unavailable", so a terminal-frame status hiccup would be silently
        // re-issued as a poll.
        self::$sseCooldowns ??= new \WeakMap();
        $cooldown = self::$sseCooldowns[$client->sseCooldownKey] ?? null;
        if ($cooldown !== null) {
            if (self::nowMs() < $cooldown['untilMs']) {
                throw new SseConnectRefused(
                    "SSE for workflow {$workflowId} not attempted: a stream on this client was "
                        . "refused with {$cooldown['refusal']->statusCode} and its Retry-After has "
                        . 'not elapsed; polling.',
                    $cooldown['refusal'],
                );
            }
            unset(self::$sseCooldowns[$client->sseCooldownKey]);
        }
        try {
            $events = $client->streamEvents($workflowId);
        } catch (GislApiError $e) {
            if ($e->retryable()) {
                // MILLISECONDS, not retryAfterSeconds(): that floors an
                // HTTP-date, so a window could end up to 999ms early or vanish
                // under a second. And never SHORTEN an open window - the longest
                // instruction still binds. Mirrors TS.
                $retryAfterMs = RateLimitHeaders::parseRetryAfterMs($e->responseHeaders['retry-after'] ?? null);
                if ($retryAfterMs !== null) {
                    $untilMs = self::nowMs() + $retryAfterMs;
                    $open = self::$sseCooldowns[$client->sseCooldownKey] ?? null;
                    if ($open === null || $untilMs > $open['untilMs']) {
                        self::$sseCooldowns[$client->sseCooldownKey] = ['untilMs' => $untilMs, 'refusal' => $e];
                    }
                }
                throw new SseConnectRefused(
                    "SSE connect for workflow {$workflowId} was refused with "
                        . "{$e->statusCode}; falling back to polling.",
                    $e,
                );
            }
            throw $e;
        }
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

    /**
     * Clamp rather than reject — a zero/negative value is a caller mistake and
     * should not crash an otherwise valid run. The FLOOR's provenance is on the
     * constant: it is a rate limit, not a spin guard.
     *
     * ⚠️ Public so a test can pin the VALUE exactly. A request-count test over a
     * real deadline cannot tell 1000 ms from 750 — the counts collide inside
     * scheduler jitter (codex d218bd6a0c62) — so the exact test and the
     * behavioural one do different jobs and neither is redundant.
     */
    public static function clampPollIntervalMs(?int $pollIntervalMs): int
    {
        if ($pollIntervalMs === null) {
            return self::DEFAULT_POLL_INTERVAL_MS;
        }

        return $pollIntervalMs < self::MIN_POLL_INTERVAL_MS
            ? self::MIN_POLL_INTERVAL_MS
            : $pollIntervalMs;
    }

    public static function pollToTerminal(
        GislClient $client,
        string $workflowId,
        int $deadlineMs,
        ?int $pollIntervalMs,
        ?Cancellation $cancellation = null,
    ): WorkflowStatusResponse {
        $intervalMs = self::clampPollIntervalMs($pollIntervalMs);

        // The POLL path's stop condition. Same question as the SSE one, same
        // answer — see the long note above the $terminalEvents list
        // (Fk8FWeyO): a terminal workflow status never returns to non-terminal,
        // and api schedules an operation's retry BEFORE failing its job, so
        // `failed` here means retries are exhausted. ⚠️ THIS PATH IS THE EASIER
        // ONE TO MISS — a poll that observes `failed` and stops has exactly the
        // defect the card described, and it is correct for the same reason
        // rather than by luck.
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
