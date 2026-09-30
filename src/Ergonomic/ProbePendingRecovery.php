<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

use Gisl\Generated\OpenApi\Model\WorkflowCreateResponse;
use Gisl\Sdk\Cancellation;
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
 * job's upload probe(s), then re-creates the SAME payload; a no-op when the
 * server never refuses.
 *
 * Rethrows the ORIGINAL typed refusal when the probe does not land in time,
 * lands `corrupt` / `unsupported_codec` (the contract says do not retry), the
 * refusal names no job whose upload is in the payload, or MAX_CREATE_ATTEMPTS
 * creates were all refused. Throws GislTimeoutError when the deadline passes
 * first.
 *
 * @internal
 */
final class ProbePendingRecovery
{
    /** A landed probe ends the gate server-side, so 3 creates is headroom, not a retry policy. */
    public const MAX_CREATE_ATTEMPTS = 3;

    /** Default recovery budget when the caller gives no timeout. */
    private const DEFAULT_RECOVERY_BUDGET_MS = 30_000;

    /** A guest's delay before its re-create when the refusal carries no Retry-After. */
    public const GUEST_BACKOFF_BASE_MS = 1_000;

    /**
     * A guest's create cap: `anonymous-policy.yaml` `per_minute.workflow_create`
     * (2). A third create inside the minute would be a 429, not a recovery, so
     * a guest gets ONE re-create. Pinned to the policy by
     * scripts/tests/test_guest_create_cap.py.
     */
    public const GUEST_MAX_CREATE_ATTEMPTS = 2;

    /**
     * @param int|null $probeTimeoutMs ONE budget for the whole recovery - the
     *                                 refusal's Retry-After plus every probe
     *                                 wait. Default 30 s; past it the refusal
     *                                 is rethrown (codex 6b0efdab28dd).
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
        $budgetEnd = null;
        // Every give-up path rethrows the FIRST refusal, as documented; each
        // retry's own refusal is read only for its Retry-After and job_ref.
        $original = null;
        $guest = $client instanceof GislAnonymousClient;
        $maxAttempts = $guest ? self::GUEST_MAX_CREATE_ATTEMPTS : self::MAX_CREATE_ATTEMPTS;
        for ($attempt = 1; ; $attempt++) {
            // Before EVERY create, the first included (codex ae65d4f34b8e).
            BuilderInternals::throwIfCancelled($cancellation, 'workflow creation');
            try {
                return $client->createWorkflow($payload);
            } catch (GislProbePendingError $refusal) {
                $original ??= $refusal;
                if (!$enabled || $attempt >= $maxAttempts) {
                    throw $original;
                }
            }
            $jobRef = $refusal->typedPayload->getJobRef();
            $fileIds = self::uploadFileIdsForJob($payload, \is_string($jobRef) ? $jobRef : null);
            if (!$guest && $fileIds === []) {
                throw $original;
            }
            $budgetEnd ??= BuilderInternals::nowMs() + \max(0, $probeTimeoutMs ?? self::DEFAULT_RECOVERY_BUDGET_MS);

            // The contract's Retry-After is the suggested delay before the next
            // poll/retry (codex 089af94beb8e); slept in <= 1 s slices so a
            // cancel is not ignored for its whole length.
            $retryAfterMs = RateLimitHeaders::parseRetryAfterMs($refusal->responseHeaders['retry-after'] ?? null);
            // anonymous-policy 2.1.0 (5dJrOdVC): the probe endpoint is sign-in only,
            // so a guest RETRIES THE CREATE after Retry-After, or a backoff when the
            // refusal carries none, within GUEST_MAX_CREATE_ATTEMPTS and the budget.
            $delayMs = match (true) {
                $retryAfterMs !== null && $retryAfterMs > 0 => $retryAfterMs,
                $guest => self::GUEST_BACKOFF_BASE_MS,
                default => 0,
            };
            if ($delayMs > 0) {
                self::budgetLeft($delayMs, $budgetEnd, $deadlineMs, $original);
                for ($left = $delayMs; $left > 0; $left -= 1_000) {
                    BuilderInternals::throwIfCancelled($cancellation, 'the workflow could be re-created');
                    \usleep(\min(1_000, $left) * 1_000);
                }
            }
            if ($guest) {
                // After the sleep, before the re-create: a late wake-up must not
                // create past the deadline or the budget (codex 08b28b31ad2f).
                self::budgetLeft(0, $budgetEnd, $deadlineMs, $original);
                continue;
            }
            foreach ($fileIds as $fileId) {
                $budgetLeft = self::budgetLeft(0, $budgetEnd, $deadlineMs, $original);
                $deadlineLeft = $deadlineMs === null ? $budgetLeft : $deadlineMs - BuilderInternals::nowMs();
                $waited = $client->waitForProbe($fileId, new ProbeWaitOptions(
                    timeoutMs: \min($budgetLeft, $deadlineLeft),
                    cancellation: $cancellation,
                ));
                // Typed as the generated enum model, but hydrated as its string value.
                /** @var mixed $status */
                $status = $waited->probe?->getProbeStatus();
                $statusName = \is_scalar($status) ? (string) $status : '';
                if (!$waited->landed || $statusName === 'corrupt' || $statusName === 'unsupported_codec') {
                    throw $original;
                }
            }
            if ($deadlineMs !== null && BuilderInternals::nowMs() >= $deadlineMs) {
                throw new GislTimeoutError('Probe landed but maxWait elapsed before the workflow could be re-created');
            }
        }
    }

    /**
     * Ms left in the recovery budget before a step of `$stepMs`: the deadline
     * crossing is a timeout, the budget crossing rethrows the refusal.
     */
    private static function budgetLeft(int $stepMs, int $budgetEnd, ?int $deadlineMs, GislProbePendingError $refusal): int
    {
        $now = BuilderInternals::nowMs();
        if ($deadlineMs !== null && $now + $stepMs >= $deadlineMs) {
            throw new GislTimeoutError('maxWait elapsed while recovering from probe_pending');
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
