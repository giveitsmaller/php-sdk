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
 *    a `Proxy`) is the {@see GislAnonymousClient} subclass that
 *    {@see anonymous()} returns: every method outside
 *    {@see ANONYMOUS_ALLOWLIST} is overridden to throw before any I/O.
 */
final class Gisl
{
    /**
     * Low-level {@see GislClient} methods a {@see anonymous()} client may call.
     * Every other method throws {@see GislFeatureRequiresAuthError} before any
     * I/O ({@see GislAnonymousClient} overrides each one).
     *
     * ⚠️ THIS LIST IS DERIVED, NOT CHOSEN (owner decision 610(4): the guest
     * surface is exactly what the API accepts). Each entry is here because
     * every endpoint it can reach on an anonymous client is `auth: optional`
     * (or `anonymous`) in the vendored `availability.json` AND open to guests
     * in the API:
     *  - `uploadFile` -> `POST /api/uploads` ONLY. A guest upload is
     *    single-shot: a file over the 10,000,000-byte single-shot cap, or a
     *    `resumeUploadId`, is refused locally before any request.
     *  - `getMetadata` -> `GET /api/uploads/{id}/metadata`
     *  - `createWorkflow`, `createWorkflowAwaitingProbe` -> `POST /api/workflows`
     *    (the latter's probe wait meets the gate: `waitForProbe` is not listed)
     *  - `getWorkflowStatus`, `waitForWorkflow` -> `GET /api/workflows/{id}/status`
     *  - `getWorkflowDownloads` -> `GET /api/workflows/{id}/downloads`
     *  - `streamEvents` -> `GET /api/workflows/{id}/events`
     *  - `getSchema` -> `GET /api/operations/schema`
     *  - `submitContact` -> `POST /api/contact`
     *  - `maybeWaitForVideoProbe` -> nothing: a no-op on an anonymous client,
     *    because the probe endpoint is `required` and the wait is best-effort
     *
     * ⚠️ WHERE THE CONTRACT AND THE API DISAGREE, THE API WINS (hub directive).
     * Measured 2026-09-26 in compression_api `config/packages/security.yaml`
     * (origin/main 566d3350): multipart initiate and operation retry are
     * `IS_AUTHENTICATED_FULLY`, although `availability.json` marks both
     * `optional`. So multipart is not on the guest surface (making 10,000,000
     * bytes, the single-shot cap, the effective guest file limit) and
     * `retryOperation` is excluded — both named, reasoned exclusions in the
     * conformance test, to be removed when the contract is corrected.
     *
     * `AnonymousAllowlistConformanceTest` fails in both directions: an entry
     * reaching a `required` endpoint, or a non-`required` endpoint no entry
     * reaches and no exclusion names. Mirrors TS `ANONYMOUS_ALLOWLIST`.
     *
     * WHAT a guest may upload and run is enforced by the API, not here — see
     * {@see anonymous()}.
     *
     * @internal
     */
    public const ANONYMOUS_ALLOWLIST = [
        'uploadFile',
        'getMetadata',
        'createWorkflow',
        'createWorkflowAwaitingProbe',
        'getWorkflowStatus',
        'waitForWorkflow',
        'getWorkflowDownloads',
        'streamEvents',
        'getSchema',
        'submitContact',
        'maybeWaitForVideoProbe',
    ];

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
     * Construct an ergonomic client with NO credential — the guest front door
     * (`OuegCUtq`):
     *
     *     $client = Gisl::anonymous(environment: Environment::Staging);
     *     $result = $client->file('photo.jpg')->compress()->run();
     *
     * Never reads an API key from anywhere (explicit, `GISL_API_KEY`, or the
     * `~/.gisl/credentials` profile) and sends no session cookie, so no
     * request it makes carries a credential of any kind.
     *
     * Only the methods in {@see ANONYMOUS_ALLOWLIST} work — upload, workflow
     * create, status, wait, downloads, events, metadata, schema, contact.
     * Everything else (credits, limits, cancel, resume, retry, list, probe,
     * profile, login/logout...) throws
     * {@see GislFeatureRequiresAuthError} before any I/O. The file-first and
     * single-op builders (`file()`, `files()`, `compress()`, `run()`,
     * `submit()`) work through that gate.
     *
     * The workflow capability token (`cap`) an anonymous create returns is
     * remembered per workflow and sent as `X-Workflow-Capability` on that
     * workflow's status / downloads / events reads, so `run()` and
     * `submit()->wait()` need nothing from you. It lives only in this client:
     * a workflow re-attached from another process has no `cap`, so read it
     * with the low-level `getWorkflowStatus($id, $capability)`.
     *
     * **What a guest may do is the API's rule, not the SDK's.** The SDK does
     * not pre-check the media, operation or quota rules, so they cannot drift
     * from the server's; it checks only the file size, which is a transport
     * fact (above the single-shot cap the only route is multipart). As the API
     * enforces it today (owner decision 610(4); not yet declared
     * machine-readably in the contract, so it is stated here rather than
     * pinned):
     *  - uploads: images only, at most 10,000,000 bytes per file,
     *    single-shot. The API's guest cap is 10 MiB, but multipart needs an
     *    account, so the single-shot cap is the one that binds. A larger file
     *    is refused HERE, before any request, with
     *    {@see GislFeatureRequiresAuthError}; a non-image is refused by the
     *    API as a {@see Errors\GislTierRestrictedError} (restriction kind
     *    `mime_type`).
     *  - operations: `compress`, `convert` and `thumbnail`. Anything else is a
     *    403 at workflow create: a {@see Errors\GislApiError} with `errorCode`
     *    `ANONYMOUS_OPERATION_NOT_ALLOWED`.
     *  - 30 workflow creates per IP per 24 hours: then a
     *    {@see Errors\GislApiError} with `errorCode` `ANONYMOUS_QUOTA_EXHAUSTED`
     *    (plus the usual per-minute 429s).
     *
     * {@see create()} is unchanged: without a key it still throws
     * {@see GislMissingCredentialsError} and never falls back to this mode.
     *
     * @param array<string, string> $headers Extra headers merged into every request.
     */
    public static function anonymous(
        ?Environment $environment = null,
        ?string $baseUrl = null,
        array $headers = [],
        ?int $timeoutMs = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?PresetDefaults $presetDefaults = null,
        ?string $locale = null,
        ?string $streamBaseUrl = null,
    ): GislAnonymousClient {
        $client = self::createInternal(
            apiKey: null,
            environment: $environment,
            baseUrl: $baseUrl,
            profile: null,
            profilePath: null,
            useSessionCookie: false,
            // No caller-supplied header may carry a credential (codex on the
            // anonymous PR): Authorization and Cookie are dropped, any case.
            headers: \array_filter(
                $headers,
                static fn (string $name): bool => !\in_array(\strtolower($name), ['authorization', 'cookie'], true),
                \ARRAY_FILTER_USE_KEY,
            ),
            timeoutMs: $timeoutMs,
            // A guest cannot upload multipart, so there are no multipart knobs;
            // createInternal pins the threshold to the single-shot cap.
            multipartThresholdBytes: null,
            multipartConcurrency: null,
            multipartMaxAttempts: null,
            multipartRetryBaseMs: null,
            httpClient: $httpClient,
            requestFactory: $requestFactory,
            streamFactory: $streamFactory,
            allowAnonymous: true,
            presetDefaults: $presetDefaults,
            locale: $locale,
            streamBaseUrl: $streamBaseUrl,
        );
        if (!$client instanceof GislAnonymousClient) {
            // Unreachable: createInternal's anonymous branch builds exactly
            // this class. Checked rather than asserted so a refactor that
            // breaks it cannot hand a guest an ungated client.
            throw new \LogicException('Gisl::anonymous() must build a GislAnonymousClient.');
        }
        return $client;
    }

    /**
     * Inner factory shared by {@see create()} and
     * {@see anonymous()} — extracted so the anonymous branch can
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
            // Needed so the stream resolver can tell "unconfigured" (API
            // defaults to production, so the stream defaults with it) from
            // "pointed at a host we were told about" (refuse rather than guess).
            baseUrl: $baseUrl,
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
                // Pinned: every upload the gate lets through (<= the
                // single-shot cap) must route single-shot.
                multipartThresholdBytes: GislAnonymousClient::MAX_UPLOAD_BYTES,
                multipartConcurrency: $multipartConcurrency,
                multipartMaxAttempts: $multipartMaxAttempts,
                multipartRetryBaseMs: $multipartRetryBaseMs,
                locale: $locale,
                streamBaseUrl: $resolvedStreamBaseUrl,
            );
            return new GislAnonymousClient(
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
