<?php

declare(strict_types=1);

namespace Gisl\Sdk\Http;

/**
 * Shared HTTP retry / rate-limit header parsing.
 *
 * Extracted so both {@see \Gisl\Sdk\GislClient} (the retry loop) and
 * {@see \Gisl\Sdk\Errors\GislApiError} (the back-off accessors) consume one
 * predicate + one set of parsers without a circular dependency — the parsers
 * were previously private static on GislClient, which GislApiError cannot
 * reach without GislClient reaching back for the error types.
 *
 * Mirrors the TS reference `packages/typescript/src/retry-metadata.ts`.
 */
final class RateLimitHeaders
{
    /** @codeCoverageIgnore */
    private function __construct()
    {
    }

    /**
     * Retryable-status predicate for API responses: 408 Request Timeout,
     * 429 Too Many Requests, or a bounded 5xx (500-599). Identical numeric set
     * to the TS `isApiRetryableStatus`. Deliberately distinct from the S3-PUT
     * chunk-upload retry predicate — do NOT conflate the two.
     */
    public static function isApiRetryableStatus(int $status): bool
    {
        return $status === 408 || $status === 429 || ($status >= 500 && $status <= 599);
    }

    /**
     * Parse an HTTP `Retry-After` header into milliseconds. Accepts the two
     * RFC 9110 forms: delta-seconds (e.g. "5") or an HTTP-date. Returns null
     * for an absent / unparseable value (caller falls back to its own
     * backoff). A non-positive Retry-After (e.g. "0" or a past HTTP-date) is
     * treated as absent so the caller cannot busy-poll to zero.
     */
    public static function parseRetryAfterMs(?string $headerValue): ?int
    {
        if ($headerValue === null) {
            return null;
        }
        $trimmed = \trim($headerValue);
        if ($trimmed === '') {
            return null;
        }
        if (\preg_match('/^\d+$/', $trimmed) === 1) {
            // ⚠️ May be a FLOAT: from ~16 digits `(int) $x * 1000` overflows.
            // The ceiling below turns that into "absent" (hnkwDULJ); without it
            // the `?int` return threw a TypeError out of run().
            $ms = ((int) $trimmed) * 1000;
        } elseif (\preg_match('/^[A-Za-z].*:/', $trimmed) === 1) {
            // A Retry-After HTTP-date (all three RFC 9110 forms — IMF-fixdate,
            // RFC 850, asctime) begins with a weekday name and always carries an
            // "HH:MM:SS" time, so require both a leading letter and a colon
            // before handing the value to PHP's permissive strtotime. This
            // rejects bare numeric tokens ("12.5", "-5") AND relative phrases
            // ("tomorrow", "now", "monday") that strtotime would otherwise
            // coerce into a bogus time — matching the TS parser, where
            // Date.parse returns NaN for all of those.
            $whenSec = \strtotime($trimmed);
            if ($whenSec === false) {
                return null;
            }
            // Against the MILLISECOND clock, truncated as `Date.now()` is.
            // `time()` is whole seconds, so a sub-second window read as zero
            // (codex c37f52484178); rounding the difference still zeroed one
            // under 0.5ms (codex 632b978b38e3). ONE clock snapshot, split into
            // whole seconds and the ms into the current second, so no epoch-ms
            // value is ever formed: that is a float on 32-bit PHP and would fail
            // the is_int check below for every valid date (codex bfa82a00ee0f).
            // A date is whole seconds, so a future one is always >= 1ms away.
            $now = \microtime(true);
            $nowSec = (int) \floor($now);
            $msIntoSecond = (int) \floor(($now - $nowSec) * 1000);
            $ms = ($whenSec - $nowSec) * 1000 - $msIntoSecond;
        } else {
            return null;
        }

        // One ceiling for both SDKs (TS: `Number.isSafeInteger`), so they agree on
        // which values are absurd - and it also catches the float overflow above.
        // A delta that large (~285,000 years) is no instruction anyone can honour.
        // `!is_int` first: on a 32-bit build a merely large delta (3,000,000 s)
        // already overflows to a float below the ceiling (codex 7817c4bdc213),
        // and on 64-bit a 16+ digit one does. Either way it is not an int.
        // @phpstan-ignore function.alreadyNarrowedType (int arithmetic overflows to float at runtime; PHPStan does not model overflow)
        if (!\is_int($ms) || $ms > self::MAX_SAFE_MS) {
            return null;
        }

        return $ms > 0 ? $ms : null;
    }

    /** JavaScript's Number.MAX_SAFE_INTEGER, the TS parser's ceiling. */
    private const MAX_SAFE_MS = 9_007_199_254_740_991;

    /**
     * Public seconds accessor derived from the ms parser (floor of ms / 1000)
     * so the internal retry path keeps its ms precision while callers reading
     * back-off hints get whole seconds. Reads the lowercased `retry-after`
     * key from a response-header map.
     *
     * @param array<string, string> $headers Lowercased response-header map.
     */
    public static function retryAfterSeconds(array $headers): ?int
    {
        $ms = self::parseRetryAfterMs($headers['retry-after'] ?? null);
        if ($ms === null) {
            return null;
        }
        $seconds = \intdiv($ms, 1000);

        // Sub-second futures (1-999ms → floor 0) are treated as absent, matching
        // the TS `retryAfterSecondsFromHeaders` "floored seconds <= 0 → undefined".
        return $seconds > 0 ? $seconds : null;
    }

    /**
     * Parse the tiered rate-limit headers into
     * `{limit, remaining, resetSeconds}`. Present only when all three of
     * `x-ratelimit-limit`, `x-ratelimit-remaining`, `x-ratelimit-reset`
     * parse as integers; otherwise null. `x-ratelimit-reset` is
     * seconds-to-reset (compression_api RateLimitHeadersTrait).
     *
     * @param array<string, string> $headers Lowercased response-header map.
     *
     * @return array{limit: int, remaining: int, resetSeconds: int}|null
     */
    public static function rateLimit(array $headers): ?array
    {
        $limit = self::parseIntHeader($headers['x-ratelimit-limit'] ?? null);
        $remaining = self::parseIntHeader($headers['x-ratelimit-remaining'] ?? null);
        $reset = self::parseIntHeader($headers['x-ratelimit-reset'] ?? null);
        if ($limit === null || $remaining === null || $reset === null) {
            return null;
        }

        return ['limit' => $limit, 'remaining' => $remaining, 'resetSeconds' => $reset];
    }

    /**
     * Parse a header value as a non-negative base-10 integer, trimming
     * surrounding whitespace. Returns null for an absent / negative /
     * non-integer value (missing or malformed rate-limit headers collapse the
     * whole {@see rateLimit()} result to null). Matches the TS
     * `rateLimitFromHeaders` `^\d+$` predicate.
     */
    private static function parseIntHeader(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $trimmed = \trim($value);
        if (\preg_match('/^\d+$/', $trimmed) !== 1) {
            return null;
        }

        return (int) $trimmed;
    }
}
