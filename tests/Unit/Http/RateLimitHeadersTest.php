<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Http;

use Gisl\Sdk\Http\RateLimitHeaders;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for W8v4jWzx — the shared retry / rate-limit header parser
 * backing the GislApiError back-off accessors. Mirrors the TS reference tests
 * (packages/typescript/src/retry-metadata.ts). Kept as a per-lang unit test;
 * the TS↔PHP registry parity lives in the generator pytest.
 */
#[CoversClass(RateLimitHeaders::class)]
final class RateLimitHeadersTest extends TestCase
{
    // -----------------------------------------------------------------
    // isApiRetryableStatus — bounded predicate (408 / 429 / 500-599)
    // -----------------------------------------------------------------

    public function testRetryableStatusBoundaries(): void
    {
        // status => expected. 599 / 600 is the upper edge of the 5xx window.
        $cases = [
            408 => true, 429 => true, 500 => true, 503 => true, 599 => true,
            200 => false, 301 => false, 400 => false, 404 => false, 409 => false,
            418 => false, 600 => false, 700 => false,
        ];

        self::assertNotSame([], $cases);

        $failures = [];
        foreach ($cases as $status => $expected) {
            try {
                $actual = RateLimitHeaders::isApiRetryableStatus($status);
                if ($actual !== $expected) {
                    $failures[] = "{$status}: isApiRetryableStatus returned " . \var_export($actual, true)
                        . ', expected ' . \var_export($expected, true);
                }
            } catch (\Throwable $e) {
                $failures[] = "{$status}: threw " . $e::class . ": {$e->getMessage()}";
            }
        }

        self::assertSame([], $failures, \implode("\n", $failures));
    }

    // -----------------------------------------------------------------
    // parseRetryAfterMs
    // -----------------------------------------------------------------

    public function testParseRetryAfterMsDeltaSeconds(): void
    {
        self::assertSame(5000, RateLimitHeaders::parseRetryAfterMs('5'));
        self::assertSame(30000, RateLimitHeaders::parseRetryAfterMs('  30  '));
    }

    public function testParseRetryAfterMsAbsentEmptyMalformed(): void
    {
        self::assertNull(RateLimitHeaders::parseRetryAfterMs(null));
        self::assertNull(RateLimitHeaders::parseRetryAfterMs(''));
        self::assertNull(RateLimitHeaders::parseRetryAfterMs('   '));
        self::assertNull(RateLimitHeaders::parseRetryAfterMs('soon'));
        self::assertNull(RateLimitHeaders::parseRetryAfterMs('12.5'));
    }

    public function testParseRetryAfterMsZeroAndPastAreAbsent(): void
    {
        self::assertNull(RateLimitHeaders::parseRetryAfterMs('0'));
        $past = \gmdate('D, d M Y H:i:s', \time() - 60) . ' GMT';
        self::assertNull(RateLimitHeaders::parseRetryAfterMs($past));
    }

    public function testParseRetryAfterMsFutureHttpDateIsPositive(): void
    {
        $future = \gmdate('D, d M Y H:i:s', \time() + 60) . ' GMT';
        $ms = RateLimitHeaders::parseRetryAfterMs($future);
        self::assertNotNull($ms);
        // Tolerance window around ~60s to avoid clock-boundary flakiness.
        self::assertGreaterThan(50_000, $ms);
        self::assertLessThanOrEqual(61_000, $ms);
    }

    /**
     * hnkwDULJ: from ~16 digits `(int) $x * 1000` overflowed to a float and the
     * `?int` return threw a TypeError out of run(). Absurd deltas are absent.
     */
    public function testParseRetryAfterMsAbsurdDeltaIsAbsentNotATypeError(): void
    {
        self::assertNull(RateLimitHeaders::parseRetryAfterMs('9300000000000000'));
        self::assertNull(RateLimitHeaders::parseRetryAfterMs(\str_repeat('9', 400)));
        // The shared ceiling with TS: Number.MAX_SAFE_INTEGER milliseconds.
        self::assertNull(RateLimitHeaders::parseRetryAfterMs('9007199254741'));
        self::assertSame(9_007_199_254_740_000, RateLimitHeaders::parseRetryAfterMs('9007199254740'));
        // Leading zeros are not magnitude.
        self::assertSame(5_000, RateLimitHeaders::parseRetryAfterMs('0000000000000000005'));
    }

    /**
     * A date one second ahead lands 1..1000ms away and must read as exactly
     * that, not as whole seconds against time() (0 below a second).
     */
    public function testParseRetryAfterMsHttpDateHasMillisecondResolution(): void
    {
        $nextSecond = (int) \floor(\microtime(true)) + 1;
        $ms = RateLimitHeaders::parseRetryAfterMs(\gmdate('D, d M Y H:i:s', $nextSecond) . ' GMT');
        self::assertNotNull($ms, 'a sub-second window must not vanish');
        self::assertGreaterThan(0, $ms);
        self::assertLessThanOrEqual(1_000, $ms);
    }

    public function testParseRetryAfterMsRejectsRelativePhrases(): void
    {
        // The date branch requires a leading letter AND a colon, so a relative
        // phrase never reaches strtotime (which would coerce it into a bogus
        // time). Matches the TS parser where Date.parse returns NaN for these.
        self::assertNull(RateLimitHeaders::parseRetryAfterMs('tomorrow'));
        self::assertNull(RateLimitHeaders::parseRetryAfterMs('monday'));
        self::assertNull(RateLimitHeaders::parseRetryAfterMs('now'));
    }

    // -----------------------------------------------------------------
    // retryAfterSeconds(array)
    // -----------------------------------------------------------------

    public function testRetryAfterSecondsDeltaSeconds(): void
    {
        self::assertSame(45, RateLimitHeaders::retryAfterSeconds(['retry-after' => '45']));
    }

    public function testRetryAfterSecondsFutureHttpDateWithinTolerance(): void
    {
        $future = \gmdate('D, d M Y H:i:s', \time() + 90) . ' GMT';
        $seconds = RateLimitHeaders::retryAfterSeconds(['retry-after' => $future]);
        self::assertNotNull($seconds);
        self::assertGreaterThanOrEqual(80, $seconds);
        self::assertLessThanOrEqual(91, $seconds);
    }

    public function testRetryAfterSecondsRejectsRelativePhrases(): void
    {
        // Regression pin: a relative phrase must not be coerced into a back-off.
        self::assertNull(RateLimitHeaders::retryAfterSeconds(['retry-after' => 'tomorrow']));
        self::assertNull(RateLimitHeaders::retryAfterSeconds(['retry-after' => 'monday']));
    }

    public function testRetryAfterSecondsZeroPastMissingMalformedAreNull(): void
    {
        self::assertNull(RateLimitHeaders::retryAfterSeconds(['retry-after' => '0']));
        $past = \gmdate('D, d M Y H:i:s', \time() - 60) . ' GMT';
        self::assertNull(RateLimitHeaders::retryAfterSeconds(['retry-after' => $past]));
        self::assertNull(RateLimitHeaders::retryAfterSeconds([]));
        self::assertNull(RateLimitHeaders::retryAfterSeconds(['retry-after' => 'abc']));
    }

    // -----------------------------------------------------------------
    // rateLimit(array)
    // -----------------------------------------------------------------

    public function testRateLimitFullSet(): void
    {
        $snap = RateLimitHeaders::rateLimit([
            'x-ratelimit-limit' => '100',
            'x-ratelimit-remaining' => '7',
            'x-ratelimit-reset' => '42',
        ]);
        self::assertSame(['limit' => 100, 'remaining' => 7, 'resetSeconds' => 42], $snap);
    }

    public function testRateLimitAcceptsZeroValues(): void
    {
        $snap = RateLimitHeaders::rateLimit([
            'x-ratelimit-limit' => '0',
            'x-ratelimit-remaining' => '0',
            'x-ratelimit-reset' => '0',
        ]);
        self::assertSame(['limit' => 0, 'remaining' => 0, 'resetSeconds' => 0], $snap);
    }

    public function testRateLimitPartialSetIsNull(): void
    {
        self::assertNull(RateLimitHeaders::rateLimit([
            'x-ratelimit-limit' => '100',
            'x-ratelimit-remaining' => '7',
            // reset omitted
        ]));
        self::assertNull(RateLimitHeaders::rateLimit(['x-ratelimit-limit' => '100']));
    }

    public function testRateLimitMalformedValueIsNull(): void
    {
        self::assertNull(RateLimitHeaders::rateLimit([
            'x-ratelimit-limit' => '100',
            'x-ratelimit-remaining' => 'x',
            'x-ratelimit-reset' => '42',
        ]));
    }

    public function testRateLimitEmptyHeadersIsNull(): void
    {
        self::assertNull(RateLimitHeaders::rateLimit([]));
    }
}
