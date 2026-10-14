<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

use Gisl\Generated\OpenApi\Model\WorkflowCreateResponse;
use Gisl\Sdk\Cancellation;
use Gisl\Sdk\Errors\GislApiError;
use Gisl\Sdk\Errors\GislLongFormConcurrencyError;
use Gisl\Sdk\Errors\GislProbePendingError;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\GislAnonymousClient;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\Http\RateLimitHeaders;
use Gisl\Sdk\ProbeWaitOptions;
use Gisl\Sdk\WorkflowCreatePayload;

/**
 * dql51via: recovery from a `422 probe_pending` on workflow create. Mirrors TS
 * `probe-pending.ts`. Per the contract's recovery rule it waits for the named
 * job's upload probe(s), then re-creates the SAME payload; an upload whose
 * probe is `not_applicable` (never probed) is skipped, not waited on. A no-op
 * when the server never refuses.
 *
 * A signed-in recovery has no create-count cap: the budget alone ends it. It
 * polls the probe patiently (`ProbeWaitOptions::$patient`), spaces its creates
 * by the doubling backoff below, and waits out a create refused with a plain
 * `429 TOO_MANY_REQUESTS` (4v7Vwvcw, the PHP half of Hal5c41E).
 *
 * Rethrows the ORIGINAL typed refusal when recovery is disabled, the budget
 * cannot fit the next wait or the probe does not land within it, the probe
 * lands `corrupt` / `unsupported_codec` (the contract says do not retry), or
 * the refusal names no job whose upload is in the payload. Throws
 * GislTimeoutError when the deadline would pass first.
 *
 * Budget and deadline are measured with {@see BuilderInternals::nowMs()}, wall
 * clock, as before and as the TS half's `Date.now()`: a wall-clock jump moves
 * them. Accepted, to keep one time source per component.
 *
 * @internal
 */
final class ProbePendingRecovery
{
    /**
     * The first gap between two creates of one recovery when no Retry-After
     * asks for more; doubles per create. Paces a guest's re-creates and, since
     * 4v7Vwvcw, a signed-in caller's: a signed-in `probe_pending` refusal costs
     * a `workflow_create` token (compression_api WorkflowController.php:264-270),
     * and the Free tier allows 10/min, 50/h and 200/day
     * (TieredRateLimiterService.php, an API implementation fact, not contract).
     * Measured from the previous create's START, this schedule makes at most 6
     * creates in any 60 s and about 36 in 900 s.
     */
    public const GUEST_BACKOFF_BASE_MS = 1_000;

    /** The longest the doubling create backoff grows to (guest and signed-in). */
    public const GUEST_BACKOFF_MAX_MS = 30_000;

    /**
     * Timer slack allowed for the last create at the budget boundary: a
     * wake-up later than this past the budget rethrows instead of creating.
     */
    private const BOUNDARY_SLACK_MS = 1_000;

    /**
     * How far past the server's gate opening (the first refusal plus
     * PROBE_WAIT_BOUND_MS) the boundary create starts: past the DEFAULT budget's
     * end, or inside an explicit budget that reaches it (a shorter one gets
     * none). The server
     * stops gating only when `waitedSeconds > 900`, strict, in WHOLE seconds
     * from the upload's timestamp (compression_api
     * CreateWorkflowCommandHandler.php:3095-3096), so a create at exactly 900 s
     * can still be refused: it must land about 1 s plus the truncation after
     * the bound. 2 s covers both.
     */
    private const BOUNDARY_MARGIN_MS = 2_000;

    /**
     * The default recovery budget for EVERY caller: the contract's
     * `video.probe_wait_bound_seconds` (900 s; anonymous-policy.yaml, api.yaml's
     * probe_pending description, and the API's PROBE_PENDING_MAX_WAIT_SECONDS).
     * Past that bound after the upload the API stops answering `probe_pending`
     * and proceeds, so waiting longer is pointless and giving up sooner fails a
     * run the server would accept (fNSQUeDS, Hal5c41E). No generated runtime
     * constant carries it, so it is a literal pinned to the policy by
     * scripts/tests/test_guest_create_cap.py. The caller's budget and deadline
     * still win.
     */
    public const PROBE_WAIT_BOUND_MS = 900_000;

    /**
     * @param int|null $probeTimeoutMs ONE budget for the whole recovery - the
     *                                 refusal's Retry-After plus every probe
     *                                 wait and create gap. Default
     *                                 PROBE_WAIT_BOUND_MS (900 s), for guests and
     *                                 signed-in callers alike; past it the
     *                                 refusal is rethrown (codex 6b0efdab28dd).
     *                                 The DEFAULT budget ends with a boundary
     *                                 create about 2 s past it; an explicit one
     *                                 is a strict cap, though one of at least
     *                                 about 902 s makes that create once the
     *                                 server's 900 s bound has passed.
     * @param bool     $enabled        false = rethrow a refusal at once; the
     *                                 ergonomic paths pass probeBeforeCreate
     *                                 (codex b2a3605234db).
     */
    public static function create(
        GislClient $client,
        WorkflowCreatePayload $payload,
        ?int $probeTimeoutMs = null,
        ?int $deadlineMs = null,
        ?Cancellation $cancellation = null,
        bool $enabled = true,
    ): WorkflowCreateResponse {
        $budgetMs = \max(0, $probeTimeoutMs ?? self::PROBE_WAIT_BOUND_MS);
        // Only the DEFAULT budget may create past its own end (the boundary create,
        // below); an explicit budget is a strict cap (codex DISCUSS on #562).
        $defaultBudget = $probeTimeoutMs === null;
        $boundaryMarginMs = $defaultBudget ? self::BOUNDARY_MARGIN_MS : 0;
        $budgetEnd = null;
        // When the server's gate is known to be open: the contract bound after the
        // FIRST refusal, which comes after the upload the server measures from.
        $gateOpenAt = null;
        // Every give-up path rethrows the FIRST refusal, as documented; each
        // retry's own refusal is read only for its Retry-After and job_ref.
        $original = null;
        $guest = $client instanceof GislAnonymousClient;
        // A guest's last create lands AT the budget boundary, not one backoff short of it.
        $guestFinalCreate = false;
        // Signed-in: when the previous create STARTED; the next one is paced from it.
        $lastCreateAt = 0;
        // Signed-in: the one create allowed at the budget boundary has been made.
        $boundaryCreateMade = false;
        for ($attempt = 1; ; $attempt++) {
            // Before EVERY create, the first included (codex ae65d4f34b8e).
            BuilderInternals::throwIfCancelled($cancellation, 'workflow creation');
            $lastCreateAt = BuilderInternals::nowMs();
            try {
                return $client->createWorkflow($payload);
            } catch (GislProbePendingError $refusal) {
                $original ??= $refusal;
            } catch (GislApiError $e) {
                // Signed-in, mid-recovery: our own re-creates spent the
                // workflow_create bucket, so a plain rate-limit 429 is waited out,
                // not fatal. A guest keeps rethrowing it, as do a 429 on the FIRST
                // create, DUPLICATE_IN_PROGRESS (card 8EjLIvkE) and the long-form
                // concurrency 429, which no back-off clears.
                if (!$guest && $original !== null && $budgetEnd !== null && self::isCreateRateLimit($e)) {
                    $retryAfterMs = RateLimitHeaders::parseRetryAfterMs($e->responseHeaders['retry-after'] ?? null);
                    self::paceCreate($lastCreateAt, $attempt, $retryAfterMs, $budgetEnd, null, $defaultBudget, $original, $deadlineMs, $cancellation);
                    continue;
                }
                throw $e;
            }
            if (!$enabled) {
                throw $original;
            }
            // Signed-in: the boundary create was the last one.
            if ($boundaryCreateMade) {
                throw $original;
            }
            $jobRef = $refusal->typedPayload->getJobRef();
            $fileIds = self::uploadFileIdsForJob($payload, \is_string($jobRef) ? $jobRef : null);
            if (!$guest && $fileIds === []) {
                throw $original;
            }
            // One budget for the WHOLE recovery, Retry-After included (codex b08a5036e468).
            if ($budgetEnd === null || $gateOpenAt === null) {
                $recoveryStart = BuilderInternals::nowMs();
                $budgetEnd = $recoveryStart + $budgetMs;
                $gateOpenAt = $recoveryStart + self::PROBE_WAIT_BOUND_MS;
            }
            // ONE rule for every budget: the boundary create lands BOUNDARY_MARGIN_MS
            // past the open gate, when the caller gave no budget (the default, which
            // then ends that margin late) or an explicit one that reaches it (within
            // its cap).
            $boundaryAt = $gateOpenAt + self::BOUNDARY_MARGIN_MS;
            $boundaryAllowed = $defaultBudget || $boundaryAt <= $budgetEnd;

            // The contract's Retry-After is the suggested delay before the next
            // poll/retry (codex 089af94beb8e).
            $retryAfterMs = RateLimitHeaders::parseRetryAfterMs($refusal->responseHeaders['retry-after'] ?? null);
            // anonymous-policy 2.1.0 (5dJrOdVC): the probe endpoint is sign-in only,
            // so a guest RETRIES THE CREATE after Retry-After, or a backoff when the
            // refusal carries none (doubling, capped at GUEST_BACKOFF_MAX_MS), until the budget runs out.
            $delayMs = match (true) {
                $retryAfterMs !== null && $retryAfterMs > 0 => $retryAfterMs,
                $guest => self::backoffMs($attempt),
                default => 0,
            };
            if ($guest) {
                if ($guestFinalCreate) {
                    throw $original;
                }
                // When the next wait would cross the budget, wait only what is left
                // and make ONE last create at the boundary: the server starts
                // accepting just past the policy bound (codex 781cae520a36). Under
                // the default budget the wait runs BOUNDARY_MARGIN_MS past it, since
                // the server's check is strict on whole seconds; an explicit budget
                // is honoured exactly. The deadline still wins.
                $now = BuilderInternals::nowMs();
                $waitMs = $delayMs;
                if ($now + $waitMs >= $budgetEnd) {
                    $waitMs = \max(0, $budgetEnd + $boundaryMarginMs - $now);
                    $guestFinalCreate = true;
                }
                if ($deadlineMs !== null && $now + $waitMs >= $deadlineMs) {
                    throw self::timeout();
                }
                BuilderInternals::sleepMs($waitMs, $cancellation, 'the workflow could be re-created');
                // A late wake-up must not create past the deadline (codex 08b28b31ad2f),
                // nor past the budget beyond a timer's slack (codex a7a76673694a).
                if ($deadlineMs !== null && BuilderInternals::nowMs() >= $deadlineMs) {
                    throw self::timeout();
                }
                if (BuilderInternals::nowMs() > $budgetEnd + $boundaryMarginMs + self::BOUNDARY_SLACK_MS) {
                    throw $original;
                }
                continue;
            }
            if ($delayMs > 0) {
                self::budgetLeft($delayMs, $budgetEnd, $deadlineMs, $original);
                BuilderInternals::sleepMs($delayMs, $cancellation, 'the workflow could be re-created');
            }
            $atBoundary = false;
            foreach ($fileIds as $fileId) {
                // Polling stops once the server's gate is known to be open: past it a
                // create is accepted whether or not the probe has landed.
                $probeEnd = \min($budgetEnd, $gateOpenAt);
                $probeLeft = \max(0, \min(
                    self::budgetLeft(0, $budgetEnd, $deadlineMs, $original),
                    $probeEnd - BuilderInternals::nowMs(),
                ));
                $deadlineLeft = $deadlineMs === null ? $probeLeft : $deadlineMs - BuilderInternals::nowMs();
                $waited = $client->waitForProbe($fileId, new ProbeWaitOptions(
                    timeoutMs: \min($probeLeft, $deadlineLeft),
                    cancellation: $cancellation,
                    // Spaced polls, and a probe 429 waited out: the per-user probe
                    // bucket is 10/min on Free, which a 900 s wait at the default
                    // cadence would exhaust.
                    patient: true,
                ));
                // A never-probed upload (e.g. a watermark image beside the video) has
                // no probe to wait for, so it cannot be what the gate is holding: move
                // on to the job's other uploads, then re-create (8L4JJMx6). A slow
                // answer must not carry the recovery past its budget (codex 5dd8528c4a6d).
                if ($waited->reason === 'not_applicable') {
                    self::budgetLeft(0, $budgetEnd, $deadlineMs, $original);
                    continue;
                }
                if ($waited->landed) {
                    // Typed as the generated enum model, but hydrated as its string value.
                    /** @var mixed $status */
                    $status = $waited->probe?->getProbeStatus();
                    $statusName = \is_scalar($status) ? (string) $status : '';
                    if ($statusName === 'corrupt' || $statusName === 'unsupported_codec') {
                        throw $original;
                    }
                    continue;
                }
                // The probe did not land. A passed deadline is a timeout, not a refusal.
                $now = BuilderInternals::nowMs();
                if ($deadlineMs !== null && $now >= $deadlineMs) {
                    throw self::timeout();
                }
                // ONE last create past the open gate, when the boundary is allowed
                // (above), the gate clamp rather than the deadline ended the wait,
                // and only on time. Safe because the server's bound runs from the
                // UPLOAD, while gateOpenAt is measured from the first refusal, which
                // comes after the upload: so boundaryAt > upload + 900 s, and by then
                // the server has stopped gating.
                if (
                    $waited->reason === 'timeout'
                    && $boundaryAllowed
                    && $probeLeft <= $deadlineLeft
                    && $now >= $probeEnd
                    && $now <= $probeEnd + self::BOUNDARY_SLACK_MS
                ) {
                    $atBoundary = true;
                    break;
                }
                throw $original;
            }
            if (!$atBoundary && $deadlineMs !== null && BuilderInternals::nowMs() >= $deadlineMs) {
                throw new GislTimeoutError('Probe landed but maxWait elapsed before the workflow could be re-created');
            }
            self::paceCreate($lastCreateAt, $attempt, $retryAfterMs, $budgetEnd, $atBoundary ? $boundaryAt : null, $defaultBudget, $original, $deadlineMs, $cancellation);
            $boundaryCreateMade = $atBoundary;
        }
    }

    /**
     * Signed-in: sleep until the next create may start - the previous create's
     * start plus max(Retry-After, doubling backoff); the boundary create waits
     * until `$boundaryAt`. A start past the budget rethrows `$firstRefusal`
     * without creating (the default budget's boundary create: past `$boundaryAt`
     * plus timer slack); one past the deadline throws GislTimeoutError.
     */
    private static function paceCreate(
        int $lastCreateAt,
        int $createsMade,
        ?int $retryAfterMs,
        int $budgetEnd,
        ?int $boundaryAt,
        bool $defaultBudget,
        GislProbePendingError $firstRefusal,
        ?int $deadlineMs,
        ?Cancellation $cancellation,
    ): void {
        $pacedAt = $lastCreateAt + \max($retryAfterMs ?? 0, self::backoffMs($createsMade));
        $startAt = $boundaryAt !== null ? \max($pacedAt, $boundaryAt) : $pacedAt;
        $latestStart = ($boundaryAt ?? $budgetEnd) + self::BOUNDARY_SLACK_MS;
        // The default budget's boundary create is scheduled past the budget by
        // design; under an explicit budget nothing is scheduled past it (strict cap).
        $scheduleLimit = $boundaryAt !== null && $defaultBudget ? $latestStart : $budgetEnd;
        if ($deadlineMs !== null && $startAt >= $deadlineMs) {
            throw self::timeout();
        }
        if ($startAt > $scheduleLimit) {
            throw $firstRefusal;
        }
        $waitMs = $startAt - BuilderInternals::nowMs();
        if ($waitMs > 0) {
            BuilderInternals::sleepMs($waitMs, $cancellation, 'the workflow could be re-created');
        }
        // A late timer must not create past the deadline, nor past the budget
        // beyond a timer's slack (codex 08b28b31ad2f, a7a76673694a).
        if ($deadlineMs !== null && BuilderInternals::nowMs() >= $deadlineMs) {
            throw self::timeout();
        }
        if (BuilderInternals::nowMs() > $latestStart) {
            throw $firstRefusal;
        }
    }

    /**
     * The doubling create backoff for the `$createsMade`-th gap. An int shift
     * with a bounded exponent: creates are uncapped, and `2 **` turns into a
     * float (then overflows) as they grow.
     */
    private static function backoffMs(int $createsMade): int
    {
        return \min(self::GUEST_BACKOFF_BASE_MS << \min($createsMade - 1, 15), self::GUEST_BACKOFF_MAX_MS);
    }

    /**
     * A plain rate-limit refusal of a create: a 429 carrying `TOO_MANY_REQUESTS`.
     * The SDK has no dedicated class for it, so it is a base GislApiError; the
     * long-form concurrency 429 is excluded by class, since waiting does not
     * clear it.
     */
    private static function isCreateRateLimit(GislApiError $e): bool
    {
        return !($e instanceof GislLongFormConcurrencyError)
            && $e->statusCode === 429
            && $e->errorCode === 'TOO_MANY_REQUESTS';
    }

    private static function timeout(): GislTimeoutError
    {
        return new GislTimeoutError('maxWait elapsed while recovering from probe_pending');
    }

    /**
     * Ms left in the recovery budget before a step of `$stepMs`: the deadline
     * crossing is a timeout, the budget crossing rethrows the refusal.
     */
    private static function budgetLeft(int $stepMs, int $budgetEnd, ?int $deadlineMs, GislProbePendingError $refusal): int
    {
        $now = BuilderInternals::nowMs();
        if ($deadlineMs !== null && $now + $stepMs >= $deadlineMs) {
            throw self::timeout();
        }
        if ($now + $stepMs > $budgetEnd) {
            throw $refusal;
        }
        return $budgetEnd - $now;
    }

    /**
     * The upload file ids a refusal is about. `$jobRef` is the job's own id, or
     * the server's `job_N` token for an id-less job at index N. An unmatched ref
     * yields [] so the caller rethrows rather than guessing.
     *
     * @return list<string>
     */
    public static function uploadFileIdsForJob(WorkflowCreatePayload $payload, ?string $jobRef): array
    {
        if ($jobRef === null) {
            return [];
        }
        $job = null;
        foreach ($payload->jobs as $candidate) {
            if ($candidate->id === $jobRef) {
                $job = $candidate;
                break;
            }
        }
        if ($job === null && \preg_match('/^job_(\d+)$/', $jobRef, $m) === 1) {
            $candidate = $payload->jobs[(int) $m[1]] ?? null;
            if ($candidate !== null && $candidate->id === null) {
                $job = $candidate;
            }
        }
        if ($job === null) {
            return [];
        }
        $sources = [$job->source];
        foreach ($job->inputs ?? [] as $input) {
            $sources[] = $input['source'] ?? null;
        }
        $ids = [];
        foreach ($sources as $source) {
            if (\is_array($source) && ($source['type'] ?? null) === 'upload' && \is_string($source['file_id'] ?? null)
                && !\in_array($source['file_id'], $ids, true)) {
                $ids[] = $source['file_id'];
            }
        }
        return $ids;
    }
}
