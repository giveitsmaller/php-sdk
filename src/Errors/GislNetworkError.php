<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * Base for every failure that happened **off the contract envelope** — the
 * request did not come back as a typed API error, it came back (or failed to)
 * at the transport or raw-HTTP level. Extends {@see GislError} rather than
 * {@see GislApiError} because there is no error envelope to carry.
 *
 * ⚠️ **NEVER THROWN DIRECTLY — it is a hierarchy node, not an error code
 * (`t2qCrjdr`).** Everything that used to throw it now throws
 * {@see GislTransportError} or {@see GislDownloadHttpError}, because the two
 * cases cannot share one honest answer to "should I retry this?":
 *
 *   DNS / TCP / TLS / mid-stream EOF   → retry: YES, transient by nature
 *   a 404 on a signed download URL     → retry: NO, permanent
 *
 * `retryable: true` would recommend retrying a permanent failure and
 * `retryable: false` would discourage retrying a genuine transient one, so
 * contracts correctly refused to declare this class in
 * `sdk-spec/error-taxonomy.yaml` at all. The fix is the split, not a caveat in
 * a description field: **a claim must hold on every path that reaches it.**
 *
 * **Kept as the base ON PURPOSE, so this is not a breaking change.** Every
 * existing `catch (GislNetworkError $e)` — including
 * {@see \Gisl\Sdk\Ergonomic\BuilderInternals::awaitTerminal()}'s SSE
 * poll-fallback and the `waitForProbe` transient-failure counter — keeps
 * catching exactly what it caught before. Narrow to a subclass only where you
 * actually need to tell the two apart.
 *
 * ⚠️ **NO LONGER `final`.** It was, which is why the split needed this file to
 * change at all — same un-finaling as `GislTimeoutError` for
 * `GislFanOutTimeoutError` (`4G4FaA9X`).
 *
 * Mirrors `packages/typescript/src/errors.ts:GislNetworkError`.
 */
class GislNetworkError extends GislError
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
