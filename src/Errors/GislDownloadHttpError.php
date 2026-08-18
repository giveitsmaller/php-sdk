<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

use Gisl\Sdk\Http\RateLimitHeaders;

/**
 * A download URL answered with a **non-2xx status**. The server was reached and
 * replied; it simply refused. Distinct from {@see GislTransportError} because
 * retrying is usually pointless — and {@see self::retryable()} says so
 * honestly, derived from the status rather than fixed for the class.
 *
 * `$status` is carried as a field so a consumer distinguishing a permanent 404
 * from a transient 503 does not have to parse the message string — the second
 * half of `t2qCrjdr`.
 *
 * Raised on result-download fetches (signed URLs), NOT on GISL-API calls: an
 * API non-2xx carries a contract error envelope and surfaces as the matching
 * {@see GislApiError} subclass instead.
 *
 * Still **is-a** `GislNetworkError`, so existing `catch (GislNetworkError $e)`
 * is unaffected.
 *
 * Mirrors `packages/typescript/src/errors.ts:GislDownloadHttpError`.
 */
final class GislDownloadHttpError extends GislNetworkError
{
    /** The HTTP status the download URL responded with. */
    public readonly int $status;

    public function __construct(string $message, int $status, ?\Throwable $previous = null)
    {
        parent::__construct($message, $previous);
        $this->status = $status;
    }

    /**
     * Whether retrying this download could plausibly succeed. Derived from the
     * status by the same rule the API errors use (408 / 429 / 5xx), so a 404
     * reports `false` and a 503 reports `true` — the distinction the unsplit
     * class could not express.
     *
     * A METHOD rather than a property, matching
     * {@see GislApiError::retryable()}; the TS side spells it as a getter
     * because that is idiomatic there.
     */
    public function retryable(): bool
    {
        return RateLimitHeaders::isApiRetryableStatus($this->status);
    }
}
