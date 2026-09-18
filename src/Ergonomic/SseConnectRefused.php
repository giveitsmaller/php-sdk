<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

use Gisl\Sdk\Errors\GislApiError;

/**
 * Sealed marker raised by {@see BuilderInternals::consumeSseToTerminal()} when
 * the SSE CONNECT is REFUSED with a retryable status — a `429` on the
 * `events_stream` bucket, or a `503` (3OVNoRxh).
 *
 * The contract declares that refusal retryable, and it clears as soon as
 * another caller closes a stream. So it means "SSE is unavailable for a
 * moment", not "the thing you asked for failed" — and
 * {@see BuilderInternals::awaitTerminal()} catches this class specifically and
 * falls back to polling, which is a working transport and is what a `run()`
 * caller actually asked for.
 *
 * ⚠️ IT WRAPS, IT DOES NOT REPLACE. {@see self::$refusal} is the original
 * {@see GislApiError} with its status, `retryAfterSeconds()` and payload
 * intact, so nothing is lost by the indirection.
 *
 * 🔴 SCOPED TO THE CONNECT, DELIBERATELY. `consumeSseToTerminal()` also calls
 * `getWorkflowStatus()` AFTER a terminal frame, and that call can return the
 * same statuses. Catching "any retryable GislApiError from the SSE path" would
 * sweep that one in too — harmless by luck rather than by design, and it would
 * grow to cover whatever call joins that method next. The wrap happens at
 * exactly one site.
 *
 * ⇒ A DIRECT `streamEvents()` CALLER NEVER SEES THIS. The wrap lives inside
 * `consumeSseToTerminal()`; someone who asked for the stream specifically still
 * gets the raw `GislApiError`.
 *
 * Extends `\RuntimeException`, NOT `GislError` — same reasoning as
 * {@see SseStreamEndedWithoutTerminal}: `GislError` also extends
 * `\RuntimeException`, so a bare `\RuntimeException` catch arm in the
 * dispatcher would swallow auth/balance/feature errors and re-issue a doomed
 * request as a poll. Match this class specifically.
 *
 * Mirrors the TypeScript `SseConnectRefused` marker.
 */
final class SseConnectRefused extends \RuntimeException
{
    public function __construct(
        string $message,
        /** The refusal itself — status, Retry-After, payload, all intact. */
        public readonly GislApiError $refusal,
    ) {
        parent::__construct($message);
    }
}
