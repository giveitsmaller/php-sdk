<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

use Gisl\Sdk\Cancellation;

/**
 * Call-time options for {@see OperationBuilder::run()}.
 *
 * `maxWait` is MANDATORY (no default). The underlying poll path in
 * {@see \Gisl\Sdk\GislClient::waitForWorkflow()} has a 600_000 ms
 * default that would otherwise leak silently — the ergonomic layer
 * makes the deadline a conscious caller choice. Accepts:
 *  - `int` (milliseconds)
 *  - `string` with suffix: `'500ms'`, `'120s'`, `'30m'`, `'2h'`
 *
 * Cooperative cancellation: pass a {@see Cancellation} token as
 * `$cancellation` and call `$token->cancel()` from elsewhere (e.g. a
 * `pcntl` signal handler) to abort the call early with a
 * {@see \Gisl\Sdk\Errors\GislAbortError}. PHP has no `AbortSignal`
 * analogue, so cancellation is cooperative + between-steps: it is
 * checked at the same boundaries as the `maxWait` deadline (an in-flight
 * HTTP transfer is not interrupted mid-request — that is VOxtu0RZ-B4).
 *
 * Mirrors the TS `RunOptions` interface at
 * `packages/typescript/src/builder.ts:218-240` (`signal?: AbortSignal`).
 * PHP-idiomatic adjustment: `useSSE` defaults to `true` to preserve
 * cross-language parity even though the PHP SSE generator cannot be
 * interrupted mid-frame (the deadline + cancellation checks fire between
 * frames + the poll fallback is reachable).
 */
final class RunOptions
{
    /**
     * @param int|string                                              $maxWait        Mandatory deadline (see class docblock).
     * @param (callable(ProgressEvent $event): void)|null             $onProgress     Receives synthesised progress union.
     * @param bool                                                    $useSSE         Default `true`; set false to force poll fallback.
     * @param int|null                                                $pollIntervalMs Override the poll-fallback interval (ms).
     * @param Cancellation|null                                       $cancellation   Cooperative cancellation token (see class docblock).
     * @param bool|null                                               $probeBeforeCreate Best-effort probe-before-create for a VIDEO upload that went multipart (default true). Pass false to skip the wait.
     * @param int|null                                                $probeTimeoutMs Overall timeout (ms) for the probe-before-create wait.
     */
    public function __construct(
        public readonly int|string $maxWait,
        public readonly mixed $onProgress = null,
        public readonly bool $useSSE = true,
        public readonly ?int $pollIntervalMs = null,
        public readonly ?Cancellation $cancellation = null,
        public readonly ?bool $probeBeforeCreate = null,
        public readonly ?int $probeTimeoutMs = null,
    ) {
    }
}
