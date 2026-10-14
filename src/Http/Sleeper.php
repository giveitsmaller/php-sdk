<?php

declare(strict_types=1);

namespace Gisl\Sdk\Http;

use Gisl\Sdk\Cancellation;
use Gisl\Sdk\Errors\GislAbortError;

/**
 * The monotonic clock and sleep {@see \Gisl\Sdk\GislClient::waitForProbe()}
 * reads, as ONE seam (4v7Vwvcw). Every time read in that loop goes through
 * {@see nowNs()} and every sleep through {@see sleepUsCancellable()}, so a test
 * that swaps the two primitives drives the production deadline, clamp and
 * slice logic on a fake clock instead of sleeping for real.
 *
 * Only the PRIMITIVES are replaceable (`hrtime(true)` and `usleep`): the
 * slicing and the cancellation check stay above the hook, so a test exercises
 * them rather than a stand-in.
 *
 * @internal
 */
final class Sleeper
{
    /** The longest one uninterrupted sleep; a cancel is noticed within it. */
    private const SLICE_US = 1_000_000;

    /** @var (\Closure(): (int|float))|null */
    private static ?\Closure $nowNsForTesting = null;

    /** @var (\Closure(int): void)|null */
    private static ?\Closure $sleepUsForTesting = null;

    /** @codeCoverageIgnore */
    private function __construct()
    {
    }

    /**
     * Monotonic nanoseconds (`hrtime(true)`). A float on 32-bit builds, where a
     * nanosecond count overflows an int, so callers keep the arithmetic
     * type-agnostic (codex e8c58a71a7ec).
     */
    public static function nowNs(): int|float
    {
        return self::$nowNsForTesting !== null ? (self::$nowNsForTesting)() : \hrtime(true);
    }

    /** One uninterrupted sleep: the primitive a test replaces. */
    public static function sleepUs(int $us): void
    {
        if (self::$sleepUsForTesting !== null) {
            (self::$sleepUsForTesting)($us);
            return;
        }
        \usleep($us);
    }

    /**
     * Sleep `$us` microseconds in slices of at most 1 s, checking `$cancellation`
     * before every slice and after the last, so a long wait (a patient backoff
     * of 30 s, or a 429's Retry-After) is abandoned within one slice of a
     * cancel. Mirrors the TS `cancellableSleep`.
     *
     * @throws GislAbortError
     */
    public static function sleepUsCancellable(int $us, ?Cancellation $cancellation): void
    {
        for ($leftUs = $us; $leftUs > 0; $leftUs -= self::SLICE_US) {
            self::throwIfCancelled($cancellation);
            self::sleepUs(\min(self::SLICE_US, $leftUs));
        }
        self::throwIfCancelled($cancellation);
    }

    /**
     * Swap the two primitives. Pass nulls to restore `hrtime(true)` and `usleep`.
     *
     * @param (\Closure(): (int|float))|null $nowNs
     * @param (\Closure(int): void)|null     $sleepUs
     */
    public static function setForTesting(?\Closure $nowNs, ?\Closure $sleepUs): void
    {
        self::$nowNsForTesting = $nowNs;
        self::$sleepUsForTesting = $sleepUs;
    }

    private static function throwIfCancelled(?Cancellation $cancellation): void
    {
        if ($cancellation?->isCancelled() === true) {
            throw new GislAbortError('Cancelled during probe wait.');
        }
    }
}
