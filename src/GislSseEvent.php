<?php

declare(strict_types=1);

namespace Gisl\Sdk;

/**
 * One typed SSE frame yielded by {@see GislClient::streamEvents()}.
 *
 * Mirrors the TS reference type at `packages/typescript/src/types.ts`
 * (`GislSseEvent = { event: string; data: unknown }`) — deliberately NO
 * `id` and NO `retry` properties. Codex round 1 on B2.1 was explicit on
 * this: `id:` is ignored (the SDK does not implement Last-Event-ID
 * reconnection) and `retry:` is ignored — **not because something else
 * honours it, but because THIS SDK NEVER RECONNECTS**, so a
 * server-suggested interval has no consumer. Surfacing either field
 * would imply behaviour the SDK does not provide.
 *
 * ⚠️ This clause previously read "the SDK manages its own reconnection
 * cadence", which asserts the opposite of the truth and sat one line
 * below the correct disclaimer. Corrected 2026-08-29 with the TS
 * reference, which carried the same false claim.
 *
 * `$event` is the SSE `event:` field value, defaulting to `"message"`
 * per the SSE spec when the server omits it.
 *
 * `$data` is the JSON-decoded `data:` field as a plain associative
 * array (snake_case keys preserved exactly as the server emits them —
 * the SDK does not camelCase-convert SSE payloads). Frames whose
 * `data:` body fails to JSON-decode are SKIPPED inside the parser
 * rather than yielded with a string fallback — long-running consumers
 * should not break on garbled frames, and a plain-string `data` would
 * defeat the typed-event promise.
 */
final class GislSseEvent
{
    public function __construct(
        public readonly string $event,
        public readonly mixed $data,
    ) {
    }
}
