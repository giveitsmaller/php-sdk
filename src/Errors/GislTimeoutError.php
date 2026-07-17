<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * Client-side wall-clock timeout — the `maxWait` deadline on a `run()` /
 * `wait()` (or the underlying {@see \Gisl\Sdk\GislClient::waitForWorkflow()}
 * poll deadline) elapsed before the workflow reached a terminal status.
 *
 * A timeout does NOT mean the work failed: when {@see $workflowId} is set, the
 * server is still processing that workflow, so poll
 * {@see \Gisl\Sdk\GislClient::getWorkflowStatus()} /
 * {@see \Gisl\Sdk\GislClient::getWorkflowDownloads()} to recover a result that
 * completed after the deadline, instead of re-running (a re-run re-uploads and,
 * for authenticated callers, settles a SECOND charge for the same deliverable).
 *
 * {@see $workflowId} is `null` when the SDK has no id to offer. That is NOT a
 * guarantee that nothing was created or charged: it covers both the safe case
 * (an upload / probe timeout before any workflow existed) AND the AMBIGUOUS
 * case (the `POST /api/workflows` request itself timed out — the server may
 * have created and charged the workflow before its response was lost). Treat an
 * absent id as "cannot auto-recover", not "clean slate": reconcile before
 * re-running rather than assuming nothing happened.
 *
 * Mirrors `packages/typescript/src/errors.ts:GislTimeoutError`. `$code` /
 * `$previous` are preserved from the base {@see \RuntimeException} for
 * exception chaining; `$workflowId` is the SDK-added recovery handle.
 */
final class GislTimeoutError extends GislError
{
    public readonly ?string $workflowId;

    public function __construct(
        string $message,
        ?string $workflowId = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
        // Normalise an empty id to "absent" — an empty string is not a usable
        // recovery handle (some throw sites derive the id as `... ?? ''`).
        $this->workflowId = ($workflowId === '' ? null : $workflowId);
    }
}
