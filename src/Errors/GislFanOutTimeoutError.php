<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * A `mapEach` fan-out timed out mid-batch — the deadline elapsed either while a
 * child was still running (the common case) or cleanly between child runs. The
 * parent and some children have ALREADY completed, so re-running the whole batch
 * re-does finished work. This carries their ids so the caller can poll them (via
 * {@see \Gisl\Sdk\GislClient::getWorkflowStatus()} /
 * {@see \Gisl\Sdk\GislClient::getWorkflowDownloads()}) to recover the finished
 * work and re-run ONLY the children that were never created.
 *
 * Extends {@see GislTimeoutError}, so an existing `catch (GislTimeoutError)`
 * still catches it. The inherited {@see GislTimeoutError::$workflowId} carries the
 * IN-FLIGHT child — the one running when the deadline elapsed (a child's own
 * timeout, the common path) — or stays `null` when the deadline elapsed cleanly
 * BETWEEN children. To recover, poll `$workflowId` (if set) + {@see $parentWorkflowId}
 * + {@see $completedWorkflowIds}, then re-run only the children that never started.
 *
 * NOTE on double-charge: the server-side create-dedupe (DSxwCetg) is what
 * prevents a byte-identical child re-create from settling a SECOND charge within
 * the dedup window; this error's job is efficient RECOVERY (skip the completed
 * work) + defense-in-depth, not the sole charge guard.
 *
 * Mirrors `packages/typescript/src/errors.ts:GislFanOutTimeoutError`.
 */
final class GislFanOutTimeoutError extends GislTimeoutError
{
    /**
     * The child workflows that completed before the deadline elapsed.
     *
     * @var list<string>
     */
    public readonly array $completedWorkflowIds;

    /** The parent workflow, which ran to completion before the fan-out began. */
    public readonly ?string $parentWorkflowId;

    /**
     * @param list<string> $completedWorkflowIds
     * @param ?string       $workflowId The in-flight child that timed out mid-run;
     *                                   null for a clean between-children timeout.
     */
    public function __construct(
        string $message,
        array $completedWorkflowIds = [],
        ?string $parentWorkflowId = null,
        ?string $workflowId = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        // The inherited workflowId is the in-flight child (or null between children).
        parent::__construct($message, $workflowId, $code, $previous);
        $this->completedWorkflowIds = \array_values($completedWorkflowIds);
        $this->parentWorkflowId = ($parentWorkflowId === '' ? null : $parentWorkflowId);
    }
}
