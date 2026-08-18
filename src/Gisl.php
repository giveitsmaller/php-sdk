<?php

declare(strict_types=1);

namespace Gisl\Sdk;

use Gisl\Sdk\Errors\GislFeatureRequiresAuthError;
use Gisl\Sdk\Errors\GislMissingCredentialsError;
use Gisl\Sdk\Http\CurlMultiPartUploader;
use Gisl\Sdk\Http\MultipartPartUploader;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Ergonomic-layer entrypoint for the GISL PHP SDK. Wraps the low-level
 * {@see GislClient} (transport, multipart, SSE, downloads) with a
 * credential-chain + endpoint resolver, so application code can write:
 *
 *     $client = Gisl::create();
 *
 * and get a working client targeted at production from any of:
 *  - explicit `apiKey:` arg
 *  - `GISL_API_KEY` env var
 *  - `~/.gisl/credentials` shared-config profile (default profile)
 *
 * Mirrors `packages/typescript/src/gisl.ts` `gisl.create()` (T1
 * `wVU4xHx3`). PHP-idiomatic adjustments:
 *  - `Environment` is a backed string enum (PHP 8.1+) rather than a TS
 *    string union — call sites cannot pass an unknown explicit value,
 *    so the "unknown environment" fail-closed throw the TS reference
 *    enforces by hand is structurally implicit here.
 *  - Anonymous-capable operation gating (TS `wrapAnonymous`, which uses
 *    a `Proxy`) is plumbed internally only — {@see internalAnonymous()}
 *    constructs a {@see GislClient} that bypasses the credential chain
 *    entirely. The public surface intentionally does NOT expose a
 *    `Gisl::anonymous()` method: the allowlist
 *    ({@see Gisl::ANONYMOUS_ALLOWLIST}) is empty, so a public anonymous
 *    factory would ship dead surface. When the free-tier launch lands a
 *    non-empty allowlist (per `docs/plans/sdk-cross-language-foundation.md`
 *    §4.10), a follow-up will widen the public surface + implement the
 *    per-operation gate as part of the operation builder (P2).
 */
final class Gisl
{
    /**
     * Operations that may be invoked on an internally-anonymous client
     * without raising an auth error. Empty until the free-tier launch
     * decision lands — see class docblock.
     *
     * Consumers must not depend on the emptiness today. The gate that
     * uses this allowlist (per-operation method check) lands with P2's
     * operation builder; until then, no public surface exposes anonymous
     * mode at all, so the list is purely a parking-invariant marker.
     *
     * @internal
     *
     * @var list<string>
     */
    public const ANONYMOUS_ALLOWLIST = [];

    /**
     * Construct an ergonomic-layer-resolved low-level {@see GislClient}.
     *
     * Resolves the API key + base URL via the credential chain
     * (see {@see Credentials}), then constructs a {@see GislClient}.
     * Throws {@see GislMissingCredentialsError} synchronously before any
     * HTTP I/O when no key is found AND `useSessionCookie` is not set.
     *
     * Cookie-mode (`useSessionCookie: true`) explicitly bypasses the
     * missing-credentials check — browser SPAs that drive auth via
     * `$client->login(...)` legitimately have no apiKey at construction
     * time. Passing BOTH `useSessionCookie: true` AND an explicit
     * `apiKey:` is a legitimate mixed case: the cookie-authenticated
     * SPA may also send a server-issued API key. (TS r2 medium
     * `913e4d8073f5` — without the explicit-key escape, the cookie path
     * could silently pick up an ambient `GISL_API_KEY` or trip a
     * malformed local profile.)
     *
     * @param array<string, string>     $headers          Extra headers merged into every request.
     * @param int|null                  $timeoutMs        Per-request timeout (ms). See {@see GislClientConfig} for advisory semantics.
     * @param ClientInterface|null      $httpClient       Inject a PSR-18 client; defaults to discovery.
     * @param RequestFactoryInterface|null $requestFactory PSR-17 request factory; defaults to discovery.
     * @param StreamFactoryInterface|null  $streamFactory  PSR-17 stream factory; defaults to discovery.
     * @param PresetDefaults|null           $presetDefaults Client-scope layered compress presets (P6).
     *                                                      When set, `compress()` calls resolve options
     *                                                      through the preset chain before the wire.
     * @param string|null                   $locale         BCP-47 language tag (e.g. `fr-FR`) sent as
     *                                                      `Accept-Language` on every request. See
     *                                                      {@see GislClientConfig::$locale}.
     * @param string|null                   $streamBaseUrl  Host for the SSE event stream (VUozk5Bc).
     *                                                      Moves the stream and NOTHING else — uploads,
     *                                                      workflow-create and downloads keep using
     *                                                      `$baseUrl`. Omit to resolve it from
     *                                                      `$environment` against the contract-declared
     *                                                      stream hosts; it is NEVER derived from
     *                                                      `$baseUrl`. See
     *                                                      {@see Credentials::ENVIRONMENT_STREAM_ENDPOINTS}.
     */
    public static function create(
        ?string $apiKey = null,
        ?Environment $environment = null,
        ?string $baseUrl = null,
        ?string $profile = null,
        ?string $profilePath = null,
        bool $useSessionCookie = false,
        array $headers = [],
        ?int $timeoutMs = null,
        ?int $multipartThresholdBytes = null,
        ?int $multipartConcurrency = null,
        ?int $multipartMaxAttempts = null,
        ?int $multipartRetryBaseMs = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?PresetDefaults $presetDefaults = null,
        ?string $locale = null,
        ?string $streamBaseUrl = null,
    ): GislErgonomicClient {
        return self::createInternal(
            apiKey: $apiKey,
            environment: $environment,
            baseUrl: $baseUrl,
            profile: $profile,
            profilePath: $profilePath,
            useSessionCookie: $useSessionCookie,
            headers: $headers,
            timeoutMs: $timeoutMs,
            multipartThresholdBytes: $multipartThresholdBytes,
            multipartConcurrency: $multipartConcurrency,
            multipartMaxAttempts: $multipartMaxAttempts,
            multipartRetryBaseMs: $multipartRetryBaseMs,
            httpClient: $httpClient,
            requestFactory: $requestFactory,
            streamFactory: $streamFactory,
            allowAnonymous: false,
            presetDefaults: $presetDefaults,
            locale: $locale,
            streamBaseUrl: $streamBaseUrl,
        );
    }

    /**
     * Construct a client that ENTIRELY bypasses the credential chain — no
     * env / profile key can leak into the request (TS r1 high
     * `e9e1c1182d56`). The returned client carries no Authorization
     * header.
     *
     * **Parking-gate active.** While {@see ANONYMOUS_ALLOWLIST} is empty,
     * every call raises {@see GislFeatureRequiresAuthError} BEFORE
     * constructing a client. This is the load-bearing parity-with-TS
     * guarantee: today there is no callable anonymous surface anywhere
     * in the PHP SDK — neither a public `Gisl::anonymous()` nor a
     * `@internal` factory that returns an ungated client. When the
     * free-tier launch lands a non-empty allowlist (per
     * `docs/plans/sdk-cross-language-foundation.md` §4.10 + plan §12),
     * a follow-up will widen the public surface AND add the
     * per-operation gate alongside the operation builder (P2). The
     * resolver branch in {@see createInternal()} is already plumbed
     * (the `allowAnonymous` parameter); only this method's parking
     * gate stands between today and that future state.
     *
     * @internal Not part of the public API. Calling this method today
     *           always throws — it is a placeholder for the P2-era
     *           anonymous factory.
     */
    public static function internalAnonymous(): never
    {
        throw new GislFeatureRequiresAuthError(
            operation: '__anonymous_factory__',
            message: 'Anonymous client construction is parked while '
                . 'Gisl::ANONYMOUS_ALLOWLIST is empty. No operations are '
                . 'approved for anonymous use yet. Pass apiKey: to '
                . 'Gisl::create() for authenticated access. The '
                . 'underlying anonymous bypass is plumbed internally '
                . '(createInternal allowAnonymous branch) — it ships '
                . 'alongside the P2 operation builder + a non-empty '
                . 'allowlist.',
        );
    }

    /**
     * Inner factory shared by {@see create()} and
     * {@see internalAnonymous()} — extracted so the anonymous branch can
     * ENTIRELY skip the credential chain rather than just suppressing
     * its throw. Mirrors `_createInternal` in
     * `packages/typescript/src/gisl.ts:179-246`.
     *
     * @param array<string, string> $headers
     */
    private static function createInternal(
        ?string $apiKey,
        ?Environment $environment,
        ?string $baseUrl,
        ?string $profile,
        ?string $profilePath,
        bool $useSessionCookie,
        array $headers,
        ?int $timeoutMs,
        ?int $multipartThresholdBytes,
        ?int $multipartConcurrency,
        ?int $multipartMaxAttempts,
        ?int $multipartRetryBaseMs,
        ?ClientInterface $httpClient,
        ?RequestFactoryInterface $requestFactory,
        ?StreamFactoryInterface $streamFactory,
        bool $allowAnonymous,
        ?PresetDefaults $presetDefaults = null,
        ?string $locale = null,
        ?string $streamBaseUrl = null,
    ): GislErgonomicClient {
        $resolvedBaseUrl = Credentials::resolveEndpoint(
            baseUrl: $baseUrl,
            environment: $environment,
        );
        // Resolved SEPARATELY and never from $resolvedBaseUrl. `null` here
        // means "nothing declares a stream host for this configuration" — a
        // legitimate state that streamEvents() reports and run() handles by
        // polling.
        $resolvedStreamBaseUrl = Credentials::resolveStreamEndpoint(
            streamBaseUrl: $streamBaseUrl,
            environment: $environment,
        );

        if ($allowAnonymous) {
            // Anonymous mode ENTIRELY bypasses the credential chain. Any
            // env / profile key that happens to exist on the host MUST
            // NOT leak into the request (TS r1 high e9e1c1182d56).
            $config = new GislClientConfig(
                baseUrl: $resolvedBaseUrl,
                apiKey: null,
                headers: $headers,
                timeout: $timeoutMs,
                useSessionCookie: false,
                multipartThresholdBytes: $multipartThresholdBytes,
                multipartConcurrency: $multipartConcurrency,
                multipartMaxAttempts: $multipartMaxAttempts,
                multipartRetryBaseMs: $multipartRetryBaseMs,
                locale: $locale,
                streamBaseUrl: $resolvedStreamBaseUrl,
            );
            return new GislErgonomicClient(
                $config,
                $httpClient,
                $requestFactory,
                $streamFactory,
                $presetDefaults,
                partUploader: self::partUploaderFor($config),
            );
        }

        // Cookie-mode WITHOUT an explicit apiKey skips env / profile
        // resolution entirely. The mixed case (cookie + explicit key)
        // still respects the explicit key — server-issued tokens are a
        // legitimate companion to cookie auth. TS r2 medium
        // 913e4d8073f5.
        $resolvedKey = null;
        $cookieOnly = $useSessionCookie && ($apiKey === null || $apiKey === '');
        if (!$cookieOnly) {
            $resolvedKey = Credentials::resolveApiKey(
                apiKey: $apiKey,
                profile: $profile,
                profilePath: $profilePath,
            );
        }

        if ($resolvedKey === null && !$useSessionCookie) {
            throw new GislMissingCredentialsError(
                'No API key found via explicit arg, GISL_API_KEY env, or '
                . '~/.gisl/credentials profile. Pass apiKey: explicitly, '
                . 'set GISL_API_KEY, populate ~/.gisl/credentials, or pass '
                . 'useSessionCookie: true for browser session-cookie '
                . 'authentication.',
            );
        }

        $config = new GislClientConfig(
            baseUrl: $resolvedBaseUrl,
            apiKey: $resolvedKey,
            headers: $headers,
            timeout: $timeoutMs,
            useSessionCookie: $useSessionCookie,
            multipartThresholdBytes: $multipartThresholdBytes,
            multipartConcurrency: $multipartConcurrency,
            multipartMaxAttempts: $multipartMaxAttempts,
            multipartRetryBaseMs: $multipartRetryBaseMs,
            locale: $locale,
            streamBaseUrl: $resolvedStreamBaseUrl,
        );
        return new GislErgonomicClient(
            $config,
            $httpClient,
            $requestFactory,
            $streamFactory,
            $presetDefaults,
            partUploader: self::partUploaderFor($config),
        );
    }

    /**
     * Production part-uploader for multipart uploads (z9bDW2iH): a
     * {@see CurlMultiPartUploader} for bounded-concurrent chunk PUTs when
     * ext-curl is available and `multipartConcurrency > 1`; otherwise null so
     * {@see GislClient} uses its sequential PSR-18 loop. Wired here (the
     * factory) rather than as a `GislClient` ctor default so that direct
     * construction — the `StubPsr18Client` parity/unit suites — stays on the
     * sequential, capturable path.
     */
    private static function partUploaderFor(GislClientConfig $config): ?MultipartPartUploader
    {
        if ($config->multipartConcurrency > 1 && CurlMultiPartUploader::isSupported()) {
            return new CurlMultiPartUploader(
                $config->multipartMaxAttempts,
                $config->multipartRetryBaseMs,
            );
        }
        return null;
    }
}
