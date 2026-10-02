<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * The transport that delivered a run's terminal status (v0JhuD8V). Exposed as
 * `Result::$transport` and `RunResult::$transport`.
 *
 *  - `Sse` — the `/events` stream delivered the terminal event.
 *  - `Polling` — a `GET /status` poll did. That is the case for
 *    `useSSE: false`, for a client with no declared stream host (a
 *    `baseUrl`-only client: `baseUrl` never moves the stream), and for a run
 *    whose stream opened and then fell back to polling (a clean stream end
 *    without a terminal event, a network error, a refused connect).
 *
 * It is the FINAL transport, one value, not a history: a run that streamed
 * progress and then fell back reports `Polling`. The progress it did stream is
 * visible as processing events on `onProgress`, which only SSE emits.
 *
 * Mirrors the TS `RunTransport` union (`'sse' | 'polling'`); the backing
 * values are the same strings, so `toArray()` and the TS `toJSON()` agree.
 */
enum RunTransport: string
{
    case Sse = 'sse';
    case Polling = 'polling';
}
