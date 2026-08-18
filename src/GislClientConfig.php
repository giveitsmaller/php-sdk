<?php

declare(strict_types=1);

namespace Gisl\Sdk;

use Gisl\Sdk\Errors\GislConfigError;

/**
 * Immutable configuration for {@see GislClient}.
 *
 * Multipart-related fields (`multipartThreshold`, `multipartConcurrency`,
 * `multipartMaxAttempts`, `multipartRetryBaseMs`) drive the multipart upload
 * path. `multipartConcurrency` bounds how many chunk PUTs run in flight at once
 * when the client is built via {@see Gisl::create()} with ext-curl present (the
 * `curl_multi` uploader, z9bDW2iH); 1 forces the sequential path. They are
 * sanitised here so the contract is consistent across both paths.
 *
 * Note on `$timeoutMs`: the SDK records the value but does NOT enforce it
 * directly — PSR-18 has no standardised timeout knob, so concrete enforcement
 * lives on the injected HTTP client (Guzzle: `timeout`/`connect_timeout`;
 * Symfony HttpClient: `timeout`). Callers wanting strict enforcement should
 * pre-configure their PSR-18 client. The field is exposed so consumers can
 * read back what they configured (and so that VOxtu0RZ-B2's retry loop has
 * the deadline in scope when it lands).
 *
 * Sanitisation contract (mirrors packages/typescript/src/client.ts:232-265):
 *   timeout              < 1   -> default; otherwise the integer.
 *   multipartThreshold   < 1   -> default. Floor at the 8 MiB first-chunk
 *                                 contract enforced by the multipart path.
 *   multipartConcurrency < 1   -> default (NOT 1) — zero workers ships an
 *                                 incomplete parts array which the server
 *                                 then rejects, silent corruption from the
 *                                 caller's perspective. Garbage input
 *                                 reasonably means "use the default".
 *   multipartMaxAttempts < 1   -> 1 (one attempt = no retry).
 *   multipartRetryBaseMs < 0   -> 0 (opt-out of backoff).
 */
final class GislClientConfig
{
    public const DEFAULT_TIMEOUT_MS = 30_000;
    public const DEFAULT_MULTIPART_THRESHOLD_BYTES = 10_000_000; // 10 MB (decimal)
    public const DEFAULT_MULTIPART_FIRST_CHUNK_SIZE_BYTES = 8_388_608; // 8 MiB
    public const DEFAULT_MULTIPART_CONCURRENCY = 4;
    public const DEFAULT_MULTIPART_MAX_ATTEMPTS = 3;
    public const DEFAULT_MULTIPART_RETRY_BASE_MS = 500;

    public readonly string $baseUrl;
    /**
     * Host for the **SSE event stream** ({@see GislClient::streamEvents()}), or
     * `null` when nothing declares one for this configuration.
     *
     * The stream is served from a SECOND public entry point, separate from
     * `$baseUrl`: the API host fronts an integration with no
     * response-streaming mode. Setting this moves the stream and **nothing
     * else** — uploads, workflow-create and downloads keep using `$baseUrl`.
     * That is why it is its own field rather than something expressed by
     * overriding `$baseUrl`, which moves every call.
     *
     * ⚠️ `null` is NOT defaulted to `$baseUrl`. An absent stream host stays
     * absent so {@see GislClient::streamEvents()} can fail closed and name the
     * missing declaration; a default here would be the silent derivation the
     * whole mechanism exists to prevent, hidden one layer deeper than the
     * resolver. See {@see Credentials::ENVIRONMENT_STREAM_ENDPOINTS}.
     */
    public readonly ?string $streamBaseUrl;
    public readonly ?string $apiKey;
    /** @var array<string, string> */
    public readonly array $headers;
    /** Timeout in milliseconds. Advisory in this scaffold — see class docblock. */
    public readonly int $timeoutMs;
    public readonly bool $useSessionCookie;
    public readonly int $multipartThresholdBytes;
    public readonly int $multipartConcurrency;
    public readonly int $multipartMaxAttempts;
    public readonly int $multipartRetryBaseMs;
    public readonly ?string $locale;

    /**
     * @param array<string, string> $headers Extra headers merged into every
     *                                       request (custom User-Agent,
     *                                       tracing IDs, etc.).
     * @param int|null $timeout              Per-request timeout in
     *                                       milliseconds (matches the TS
     *                                       SDK's `timeout` field). Stored
     *                                       in `$timeoutMs`. Advisory in
     *                                       this scaffold — see class
     *                                       docblock.
     * @param string|null $locale            BCP-47 language tag (e.g. `'fr-FR'`)
     *                                       sent as `Accept-Language` on every
     *                                       GISL-API request. A dedicated
     *                                       `locale` wins over any
     *                                       `Accept-Language` passed via
     *                                       `$headers` (applied after the
     *                                       `$headers` loop, so it overwrites
     *                                       case-insensitively). An empty string
     *                                       normalises to `null` (treated as
     *                                       unset, matching the TS falsy guard).
     *                                       Mirrors
     *                                       `packages/typescript/src/types.ts:43-54`.
     */
    public function __construct(
        string $baseUrl,
        ?string $apiKey = null,
        array $headers = [],
        ?int $timeout = null,
        bool $useSessionCookie = false,
        ?int $multipartThresholdBytes = null,
        ?int $multipartConcurrency = null,
        ?int $multipartMaxAttempts = null,
        ?int $multipartRetryBaseMs = null,
        ?string $locale = null,
        ?string $streamBaseUrl = null,
    ) {
        // Strip a trailing slash so the request loop can concatenate
        // /api/... paths without duplicating separators.
        $this->baseUrl = rtrim($baseUrl, '/');
        // Appended LAST in the signature deliberately: every existing
        // positional caller keeps working.
        $this->streamBaseUrl = self::normaliseStreamBaseUrl($streamBaseUrl);
        $this->apiKey = $apiKey;
        $this->headers = $headers;

        // useSessionCookie wires up cookie-credentialled fetches for browser
        // SPA flows (login() / logout()). When true, the GislClient holds
        // per-instance mutable session state captured from the login
        // response's Set-Cookie header — see the threading-unsafety note on
        // GislClient's class docblock. Activated in VOxtu0RZ-B2.4 (zxGUQSmI).
        $this->useSessionCookie = $useSessionCookie;

        $this->timeoutMs = $timeout !== null && $timeout >= 1
            ? $timeout
            : self::DEFAULT_TIMEOUT_MS;

        $this->multipartThresholdBytes = self::sanitiseThreshold($multipartThresholdBytes);
        $this->multipartConcurrency = self::sanitiseConcurrency($multipartConcurrency);
        $this->multipartMaxAttempts = self::sanitiseAttempts($multipartMaxAttempts);
        $this->multipartRetryBaseMs = self::sanitiseRetryBaseMs($multipartRetryBaseMs);
        // Normalise an empty-string locale to null so it reads as "unset" —
        // matches the TS parity target, where `if (config.locale)` treats `''`
        // as falsy and never installs an empty Accept-Language
        // (packages/typescript/src/client.ts:527).
        $this->locale = ($locale === '') ? null : $locale;
    }

    /**
     * Normalise a configured stream host to an absolute origin, or `null` when
     * none was supplied. Trailing slashes are stripped so path concatenation
     * does not double-separate.
     *
     * ⚠️ **A PRESENT-BUT-MALFORMED VALUE THROWS RATHER THAN DEGRADING TO
     * `null`, and the distinction is deliberate.** Absent means "nobody
     * declared one" — a legitimate state that `run()` handles by polling. A
     * caller who passed `'/'` or `'stream.example.com'` did declare one, and
     * got it wrong. Quietly converting that to "absent" would send
     * their stream somewhere they did not choose (a bare `'/'` rtrims to `''`,
     * which concatenates into a RELATIVE url) and hand them a poll they never
     * asked for — the silent degradation this whole mechanism exists to
     * refuse, one layer further down.
     *
     * An empty OR WHITESPACE-ONLY string is treated as unset — the two are
     * indistinguishable in intent — matching how `$locale` handles `''` in this
     * same constructor.
     *
     * Kept behaviourally identical to `normaliseStreamBaseUrl` in
     * `packages/typescript/src/client.ts`.
     */
    private static function normaliseStreamBaseUrl(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $scheme = parse_url($trimmed, PHP_URL_SCHEME);
        $host = parse_url($trimmed, PHP_URL_HOST);
        if (!is_string($scheme) || !is_string($host) || $host === '') {
            throw new GislConfigError(
                "streamBaseUrl must be an absolute http(s) URL "
                . "(e.g. https://stream.example.com); got '{$value}'.",
            );
        }
        $scheme = strtolower($scheme);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new GislConfigError(
                "streamBaseUrl must use http or https; got scheme '{$scheme}' in '{$value}'.",
            );
        }

        // A query or fragment cannot survive path concatenation: the events
        // path is appended as a STRING, so 'https://host?token=x' would
        // request '/' with the whole events path buried inside the query
        // value. Rejecting is right rather than stripping — a caller who put
        // a token there meant it to be sent, and silently dropping it would
        // fail later and further away. codex 5793a3be0f7b.
        if (parse_url($trimmed, PHP_URL_QUERY) !== null || parse_url($trimmed, PHP_URL_FRAGMENT) !== null) {
            throw new GislConfigError(
                'streamBaseUrl must not carry a query or fragment '
                . "(the events path is appended to it); got '{$value}'.",
            );
        }

        return rtrim($trimmed, '/');
    }

    private static function sanitiseThreshold(?int $value): int
    {
        if ($value === null || $value < 1) {
            return self::DEFAULT_MULTIPART_THRESHOLD_BYTES;
        }
        // Floor at the 8 MiB first-chunk contract — sub-8MB threshold would
        // route a file too small to satisfy the multipart initiate's
        // first-chunk shape. Mirrors packages/typescript/src/client.ts:332-335.
        return max($value, self::DEFAULT_MULTIPART_FIRST_CHUNK_SIZE_BYTES);
    }

    private static function sanitiseConcurrency(?int $value): int
    {
        if ($value === null || $value < 1) {
            return self::DEFAULT_MULTIPART_CONCURRENCY;
        }
        return $value;
    }

    private static function sanitiseAttempts(?int $value): int
    {
        if ($value === null) {
            return self::DEFAULT_MULTIPART_MAX_ATTEMPTS;
        }
        // Floor at 1 (one attempt = no retry). Mirrors sanitiseAttempts in
        // packages/typescript/src/client.ts:235-238 — but PHP int can't carry
        // NaN/Infinity, so the only branch needed is the < 1 guard.
        return max(1, $value);
    }

    private static function sanitiseRetryBaseMs(?int $value): int
    {
        if ($value === null) {
            return self::DEFAULT_MULTIPART_RETRY_BASE_MS;
        }
        // Floor at 0 (zero opts out of backoff entirely). Mirrors
        // sanitiseBaseMs in packages/typescript/src/client.ts:243-246.
        return max(0, $value);
    }
}
