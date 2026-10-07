<?php

declare(strict_types=1);

namespace Gisl\Sdk;

/**
 * One typed SSE frame yielded by {@see GislClient::streamEvents()}.
 *
 * Mirrors the TS `GislSseEvent` (`packages/typescript/src/types.ts`):
 *
 * - **A contract event** (one of {@see self::NAMED_EVENTS}, the eight
 *   `SseEventType` values) keeps its name in `$event`, and `$name` is null.
 * - **Any other name** becomes `$event === 'unknown'`, with the raw name in
 *   `$name`. That covers a frame with no `event:` line (`$name === 'message'`,
 *   the SSE default) and a server event literally named `unknown`, so
 *   `$event === 'unknown'` always means "not a contract event" and a switch on
 *   `$event` never confuses the two (5CJkDr8s, parity with TS iOcpCt6L).
 *
 * Deliberately NO `id` and NO `retry` properties. Codex round 1 on B2.1 was
 * explicit on this: `id:` is ignored (the SDK does not implement Last-Event-ID
 * reconnection) and `retry:` is ignored — **not because something else
 * honours it, but because THIS SDK NEVER RECONNECTS**, so a server-suggested
 * interval has no consumer. Surfacing either field would imply behaviour the
 * SDK does not provide.
 *
 * `$data` is the JSON-decoded `data:` field as a plain associative array
 * (snake_case keys preserved exactly as the server emits them — the SDK does
 * not camelCase-convert SSE payloads; TS types the same keys with its
 * `Sse*Wire` interfaces). Frames whose `data:` body fails to JSON-decode are
 * SKIPPED inside the parser rather than yielded with a string fallback —
 * long-running consumers should not break on garbled frames, and a
 * plain-string `data` would defeat the typed-event promise.
 */
final class GislSseEvent
{
    /** `$event` value for every frame whose name is not a contract event. */
    public const UNKNOWN = 'unknown';

    /**
     * The contract's `SseEventType` values: the only names kept in `$event`.
     * `GislClientSseTest::testNamedEventsMatchTheGeneratedContractEnum` fails if
     * the generated enum stops matching this list.
     */
    public const NAMED_EVENTS = [
        'operation.progress',
        'operation.completed',
        'operation.failed',
        'job.completed',
        'job.failed',
        'workflow.completed',
        'workflow.failed',
        'workflow.partially_failed',
    ];

    /**
     * @param ?string $name The raw frame name when `$event` is `'unknown'`; null otherwise.
     */
    public function __construct(
        public readonly string $event,
        public readonly mixed $data,
        public readonly ?string $name = null,
    ) {
    }
}
