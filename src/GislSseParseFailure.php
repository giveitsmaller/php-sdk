<?php

declare(strict_types=1);

namespace Gisl\Sdk;

/**
 * A typed, non-throwing diagnostic passed to the optional `$onParseError`
 * callback of {@see GislClient::streamEvents()} when an SSE frame's `data:`
 * body fails to JSON-decode (TYNjcjpo).
 *
 * The malformed frame is SKIPPED from the yielded stream — a long-running
 * consumer must not break on one garbled server frame — but the failure is
 * observable via the callback rather than silently lost. When no callback is
 * supplied the frame is dropped silently (the historical default; a
 * `PHP_DEBUG` `error_log` line is still emitted).
 *
 * Mirrors the TypeScript `GislSseParseFailure` interface
 * (`packages/typescript/src/types.ts`); the shape is identical across the two
 * SDKs (cross-SDK parity). This is NOT a throwable — the `Gisl*Error` naming
 * is reserved for exceptions, so this diagnostic value object is named
 * `GislSseParseFailure` to make the non-throwing intent explicit.
 */
final class GislSseParseFailure
{
    public function __construct(
        /** The joined `data:` line(s) that failed to parse. */
        public readonly string $raw,
        /** The frame's event type (or `"message"` when the frame had no `event:` field). */
        public readonly string $event,
        /** The parse error message (the caught `JsonException` message). */
        public readonly string $error,
    ) {
    }
}
