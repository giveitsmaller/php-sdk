<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * The request was never put on the wire because the HTTP client refused to send
 * it — a malformed URI or an otherwise unsendable request. PSR-18 signals this
 * with {@see \Psr\Http\Client\RequestExceptionInterface}, as distinct from
 * {@see \Psr\Http\Client\NetworkExceptionInterface}.
 *
 * **Never retryable:** re-issuing the identical request fails identically, so
 * backing off only wastes the caller's deadline. That is precisely why it
 * cannot share a class with {@see GislTransportError} — the same one-retryable-
 * for-two-animals problem that produced `t2qCrjdr` in the first place, one
 * level further down.
 *
 * Still **is-a** `GislNetworkError`, so existing `catch (GislNetworkError $e)`
 * is unaffected — including `waitForProbe`'s transient-failure counter, which
 * will now count this and give up, rather than the caller seeing it escape.
 *
 * Mirrors `packages/typescript/src/errors.ts:GislRequestNotSentError`, which is
 * DORMANT there: `fetch` surfaces a network failure and an unsendable request
 * as an indistinguishable `TypeError`, so the TS SDK cannot tell them apart and
 * does not guess.
 */
final class GislRequestNotSentError extends GislNetworkError
{
    /** Always `false` — the request never left, and re-sending it will not change that. */
    public function retryable(): bool
    {
        return false;
    }
}
