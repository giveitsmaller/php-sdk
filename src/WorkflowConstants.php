<?php

declare(strict_types=1);

namespace Gisl\Sdk;

use Gisl\Generated\OpenApi\Model\WorkflowStatus;

/**
 * Workflow lifecycle constants — terminal-status set + polling defaults.
 *
 * Mirrors `packages/typescript/src/client.ts`:
 *   - `DEFAULT_POLL_INTERVAL_MS` (line ~137)
 *   - `DEFAULT_POLL_TIMEOUT_MS`  (line ~138)
 *   - `TERMINAL_STATUSES`        (line ~146)
 *
 * Per ticket I24, `cancelled` and `expired` are terminal — a workflow cannot
 * leave either state. `paused_insufficient_credits` is technically a
 * soft-pause but {@see GislClient::waitForWorkflow()} treats it as terminal:
 * the workflow only resumes on caller action (top-up + resume), so polling
 * blindly is the wrong behaviour. The caller can inspect `pausedDetail` and
 * drive the resume flow.
 */
final class WorkflowConstants
{
    public const DEFAULT_POLL_INTERVAL_MS = 2_000;
    public const DEFAULT_POLL_TIMEOUT_MS = 600_000; // 10 min

    /**
     * Statuses that {@see GislClient::waitForWorkflow()} returns immediately
     * on. Lookup is via {@see in_array} with strict comparison; the array is
     * a list of the wire-stable status strings (lowercase snake_case from
     * the v2 contract).
     *
     * @var list<string>
     */
    public const TERMINAL_STATUSES = [
        WorkflowStatus::COMPLETED,
        WorkflowStatus::FAILED,
        WorkflowStatus::PARTIALLY_FAILED,
        WorkflowStatus::CANCELLED,
        WorkflowStatus::EXPIRED,
        WorkflowStatus::PAUSED_INSUFFICIENT_CREDITS,
    ];

    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }
}
