<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

use Gisl\Generated\OpenApi\Model\CreditQuote;
use Gisl\Sdk\WorkflowConstants;

/**
 * A non-blocking snapshot of a workflow's lifecycle state, returned by
 * {@see Handle::status()}. `$state` is the RAW wire `WorkflowStatus` value,
 * verbatim (`pending` | `in_progress` | `completed` | `failed` |
 * `partially_failed` | `paused_insufficient_credits` | `cancelled` |
 * `expired`). There is NO `phase` field — phase is an SSE-only concept; the
 * status response carries no phase.
 *
 * Mirrors the TS `StatusSnapshot` in `packages/typescript/src/handle.ts`.
 */
final class StatusSnapshot
{
    public function __construct(
        public readonly string $workflowId,
        public readonly string $state,
        // The workflow's live reservation quote from the status response: the
        // most it can be charged (`getMaxHoldCredits()`) and how much of that
        // is held, charged and released right now. PLANNED on the api and
        // read-tolerant: null until the API emits quotes and for a workflow
        // without one. The generated contract model. Kept OUT of
        // {@see toArray()}, but `json_encode()` of the snapshot includes it
        // (public property).
        public readonly ?CreditQuote $quote = null,
    ) {
    }

    /**
     * True when {@see $state} is one of the terminal states (`completed`,
     * `failed`, `partially_failed`, `cancelled`, `expired`,
     * `paused_insufficient_credits`); false for `pending` / `in_progress`.
     * Reuses {@see WorkflowConstants::TERMINAL_STATUSES}.
     */
    public function isTerminal(): bool
    {
        return \in_array($this->state, WorkflowConstants::TERMINAL_STATUSES, true);
    }

    /**
     * Plain-array projection. Mirrors the TS `toJSON()`.
     *
     * @return array{workflowId: string, state: string}
     */
    public function toArray(): array
    {
        return [
            'workflowId' => $this->workflowId,
            'state' => $this->state,
        ];
    }
}
