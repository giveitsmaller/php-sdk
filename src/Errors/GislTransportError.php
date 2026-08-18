<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * The transport could not deliver a usable response: DNS, TCP, TLS, a
 * mid-stream EOF, a PSR-18 `ClientExceptionInterface`, or a PSR-7 stream read
 * that failed part-way through. **Always retryable** — nothing about these says
 * the request was wrong, only that it did not get through.
 *
 * The underlying transport exception is preserved as `$previous`.
 *
 * Raised by {@see \Gisl\Sdk\GislClient} wherever the PSR-18 client throws, and
 * by {@see \Gisl\Sdk\FileFirst\StreamingDownloader} when a download source
 * cannot be opened at all (a destination-WRITE failure is
 * {@see GislSinkError}).
 *
 * Split out of {@see GislNetworkError} by `t2qCrjdr` — see that class for why
 * one `retryable` could not be honest for both this and a 404. Still **is-a**
 * `GislNetworkError`, so existing `catch (GislNetworkError $e)` is unaffected.
 *
 * Mirrors `packages/typescript/src/errors.ts:GislTransportError`.
 */
final class GislTransportError extends GislNetworkError
{
    /**
     * Always `true`. The request did not get through; nothing about that says
     * it was wrong, so retrying is the correct advice.
     *
     * Present as a real method rather than only as prose — an unbacked claim in
     * a docblock is the exact defect `t2qCrjdr` exists to remove, and shipping
     * the split without it would have reproduced it one level down.
     *
     * A METHOD rather than a property, matching
     * {@see GislApiError::retryable()} and {@see GislDownloadHttpError::retryable()}.
     */
    public function retryable(): bool
    {
        return true;
    }
}
