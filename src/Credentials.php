<?php

declare(strict_types=1);

namespace Gisl\Sdk;

use Gisl\Sdk\Errors\GislConfigError;

/**
 * Credential + endpoint resolution for the ergonomic-layer
 * {@see Gisl::create()} factory. Implements an AWS-style short-circuiting
 * chain:
 *
 *   API key:    explicit arg → `GISL_API_KEY` env → `~/.gisl/credentials` profile
 *   Endpoint:   explicit arg → `GISL_BASE_URL` / `GISL_ENVIRONMENT` env → prod default
 *
 * Sources are tried in order; the first hit wins. Malformed profile files
 * surface as {@see GislConfigError} naming the offending path / profile /
 * line number — credential VALUES MUST NOT appear in error messages.
 *
 * Mirrors `packages/typescript/src/credentials.ts`.
 */
final class Credentials
{
    public const GISL_API_KEY_ENV = 'GISL_API_KEY';
    public const GISL_BASE_URL_ENV = 'GISL_BASE_URL';
    public const GISL_ENVIRONMENT_ENV = 'GISL_ENVIRONMENT';
    public const GISL_STREAM_BASE_URL_ENV = 'GISL_STREAM_BASE_URL';

    /**
     * Named environments → base URLs. Kept colocated with the resolver so
     * the mapping table doesn't leak into {@see Gisl}.
     *
     * @var array<string, string>
     */
    public const ENVIRONMENT_ENDPOINTS = [
        'prod' => 'https://api.giveitsmaller.com',
        'staging' => 'https://api.staging.giveitsmaller.com',
    ];

    /**
     * Named environments → **SSE stream host**. A SECOND host, deliberately
     * separate from {@see self::ENVIRONMENT_ENDPOINTS}: the API host fronts an
     * integration with no response-streaming mode, so the event stream lives
     * on its own public entry point.
     *
     * ⚠️ **DECLARED, NEVER DERIVED.** This table exists because the
     * alternative — transforming `api.*` into `stream.*` by string surgery —
     * is a *convention*, and a convention is exactly what put production on
     * the gateway path: the frontend's prod build had no stream host set,
     * silently fell back to the API host, and nobody could see it. A host is a
     * fact somebody states, not a pattern somebody guesses.
     *
     * PINNED to the generated `availability.json`
     * `endpoints['GET /api/workflows/{id}/events'].servers` by
     * {@see \Gisl\Sdk\Tests\Unit\StreamHostConformanceTest}, which fails
     * **closed**: if the contract declares a host this table does not carry
     * (or vice versa), the build breaks. Hand-maintained rather than read at
     * runtime, matching the TS side and the existing table+conformance shape
     * used by the preset planned gate, the watermark gate and the image output
     * routes.
     *
     * ⚠️ **THERE IS NO `prod` ENTRY, AND ITS ABSENCE IS THE CONTRACT'S, NOT AN
     * OVERSIGHT HERE.** The contract's `servers` block for the stream
     * operation carries localhost and staging only; contracts deliberately did
     * not invent a production URL. Until it is declared, a production
     * configuration has **no stream host** and
     * {@see self::resolveStreamEndpoint()} returns `null` — see
     * {@see GislClient::streamEvents()}, which fails closed rather than quietly
     * reusing the API base URL. Add `prod` here in the same change that vendors
     * the contract entry, never ahead of it.
     *
     * `localhost` is intentionally absent too: the contract declares it as a
     * development server, but there is no `localhost` case on
     * {@see Environment} to key it off. Local callers pass an explicit stream
     * base URL or set `GISL_STREAM_BASE_URL`.
     *
     * Kept IDENTICAL to `ENVIRONMENT_STREAM_ENDPOINTS` in
     * `packages/typescript/src/credentials.ts`. If one language declares a host
     * the other does not, the two SDKs stream to different places on the same
     * configuration and nobody's grep would find it.
     *
     * @var array<string, string>
     */
    public const ENVIRONMENT_STREAM_ENDPOINTS = [
        'staging' => 'https://stream.staging.giveitsmaller.com',
    ];

    public const DEFAULT_ENDPOINT = 'https://api.giveitsmaller.com';

    public const DEFAULT_PROFILE = 'default';

    /**
     * Resolve the API key via the credential chain. Returns the resolved
     * key, or `null` if no source produced one. The ergonomic-layer
     * factory is responsible for deciding whether `null` is an error
     * (default: yes, throw {@see \Gisl\Sdk\Errors\GislMissingCredentialsError})
     * or acceptable (anonymous / cookie-mode).
     *
     * Mirrors `resolveApiKey` in `packages/typescript/src/credentials.ts:75-113`.
     */
    public static function resolveApiKey(
        ?string $apiKey = null,
        ?string $profile = null,
        ?string $profilePath = null,
    ): ?string {
        // 1. Explicit wins (non-empty string).
        if ($apiKey !== null && $apiKey !== '') {
            return $apiKey;
        }

        // 2. Environment variable.
        $envKey = self::readEnv(self::GISL_API_KEY_ENV);
        if ($envKey !== null) {
            return $envKey;
        }

        // 3. Shared-config profile (`~/.gisl/credentials`).
        $path = $profilePath ?? self::defaultProfilePath();
        if ($path === null) {
            return null;
        }

        $profileName = $profile ?? self::DEFAULT_PROFILE;
        $entries = self::readProfile($path, $profileName);
        if ($entries === null) {
            return null;
        }

        $profileKey = $entries['api_key'] ?? null;
        if (is_string($profileKey) && $profileKey !== '') {
            return $profileKey;
        }

        return null;
    }

    /**
     * Resolve the base URL. Explicit `baseUrl` wins; otherwise an explicit
     * `environment` enum; otherwise the `GISL_BASE_URL` /
     * `GISL_ENVIRONMENT` env vars; otherwise the prod default. Never
     * throws — the chain always resolves to a usable URL.
     *
     * The "unknown explicit environment" fail-closed case from TS
     * (codex r2 medium 23a17c1dbf75) is structurally enforced here by
     * the {@see Environment} enum's type system: callers cannot pass an
     * unknown explicit value. The env-var path still accepts arbitrary
     * strings and silently falls through to the default on unknown
     * names — matching the TS env-var lenience.
     */
    public static function resolveEndpoint(
        ?string $baseUrl = null,
        ?Environment $environment = null,
    ): string {
        if ($baseUrl !== null && $baseUrl !== '') {
            return $baseUrl;
        }

        if ($environment !== null) {
            return self::ENVIRONMENT_ENDPOINTS[$environment->value];
        }

        $envBaseUrl = self::readEnv(self::GISL_BASE_URL_ENV);
        if ($envBaseUrl !== null) {
            return $envBaseUrl;
        }

        $envEnvironment = self::readEnv(self::GISL_ENVIRONMENT_ENV);
        if ($envEnvironment !== null && isset(self::ENVIRONMENT_ENDPOINTS[$envEnvironment])) {
            return self::ENVIRONMENT_ENDPOINTS[$envEnvironment];
        }

        return self::DEFAULT_ENDPOINT;
    }

    /**
     * Resolve the **SSE stream host**, or `null` when no host is declared for
     * this configuration. Explicit `$streamBaseUrl` wins; otherwise an explicit
     * `$environment`; otherwise `GISL_STREAM_BASE_URL`; otherwise the
     * `GISL_ENVIRONMENT` env var.
     *
     * ⚠️ **RETURNS `null` RATHER THAN FALLING BACK TO THE API BASE URL, AND
     * THAT IS THE WHOLE POINT OF THIS METHOD.** Deriving the stream host from
     * the API host would reproduce, inside a published SDK, the exact failure
     * this resolver exists to prevent: prod had no stream host configured, fell
     * back to the API host by convention, and streamed into a gateway that
     * cannot stream. A silent fallback is not a lenient control — it is the
     * absence of one wearing the control's name. Callers decide what `null`
     * means; see {@see GislClient::streamEvents()}, which fails closed and
     * names the missing declaration.
     *
     * Unlike {@see self::resolveEndpoint()} there is no default: prod has no
     * declared stream host yet, so a default could only be a guess.
     *
     * Mirrors `resolveStreamEndpoint` in
     * `packages/typescript/src/credentials.ts`.
     */
    public static function resolveStreamEndpoint(
        ?string $streamBaseUrl = null,
        ?Environment $environment = null,
    ): ?string {
        // TRIM BEFORE THE PRESENCE CHECK. A whitespace-only value is unset
        // (the config normaliser treats it that way too), and if it were
        // allowed to count as "supplied" here it would SUPPRESS the
        // environment's declared host and then normalise to nothing —
        // silently disabling a stream that was perfectly well declared.
        // codex a7f5ec9f0d32.
        $explicit = $streamBaseUrl === null ? '' : trim($streamBaseUrl);
        if ($explicit !== '') {
            return $explicit;
        }

        if ($environment !== null) {
            // A KNOWN environment with no declared stream host resolves to
            // `null`, not to an error and not to the API base URL. That is
            // today's `prod`: the config is valid, the declaration is simply
            // missing upstream.
            return self::ENVIRONMENT_STREAM_ENDPOINTS[$environment->value] ?? null;
        }

        $envStreamBaseUrl = self::readEnv(self::GISL_STREAM_BASE_URL_ENV);
        if ($envStreamBaseUrl !== null) {
            return $envStreamBaseUrl;
        }

        $envEnvironment = self::readEnv(self::GISL_ENVIRONMENT_ENV);
        if ($envEnvironment !== null && isset(self::ENVIRONMENT_STREAM_ENDPOINTS[$envEnvironment])) {
            return self::ENVIRONMENT_STREAM_ENDPOINTS[$envEnvironment];
        }

        return null;
    }

    /**
     * The environments that currently declare a stream host. Used in the
     * fail-closed error message so the caller is told what IS available rather
     * than only what is missing.
     *
     * @return list<string>
     */
    public static function declaredStreamEnvironments(): array
    {
        return array_keys(self::ENVIRONMENT_STREAM_ENDPOINTS);
    }

    /**
     * Read a process-environment variable, treating empty strings as
     * "not set". Uses `getenv()` exclusively — `$_ENV` is only populated
     * when `variables_order` includes `E`, which is NOT the default
     * across all SAPIs (notably some FPM pools), so falling back to it
     * would create resolver-behaviour divergence between hosting modes.
     * Tests use `putenv()` which writes through to `getenv()` reliably.
     */
    private static function readEnv(string $name): ?string
    {
        $value = getenv($name);
        if (is_string($value) && $value !== '') {
            return $value;
        }
        return null;
    }

    private static function defaultProfilePath(): ?string
    {
        $home = self::readEnv('HOME') ?? self::readEnv('USERPROFILE');
        if ($home === null) {
            return null;
        }
        // Forward slash join — works on every platform PHP supports.
        return rtrim($home, '/\\') . '/.gisl/credentials';
    }

    /**
     * Read a single profile section from an INI-format credentials file.
     *
     * Returns `null` when the file does not exist (no credentials from
     * this source — not an error). Throws {@see GislConfigError} when the
     * file exists but is malformed or the requested profile is absent.
     *
     * Implementation uses PHP's builtin `parse_ini_string` with
     * `INI_SCANNER_RAW` — research (php.watch) confirmed RAW mode
     * suppresses `${ENV_VAR}` interpolation, constant substitution, and
     * the "Norway problem" yes/no/true/false bool coercion that would
     * otherwise corrupt credential values. RAW also disables escape
     * sequences, which is acceptable for our credentials (alphanumeric
     * API keys, no embedded special chars expected).
     *
     * @return array<string, string>|null
     */
    private static function readProfile(string $path, string $profileName): ?array
    {
        // Pre-check existence so we can distinguish "no file" (return
        // null) from "file unreadable" (throw). is_file follows symlinks.
        if (!is_file($path)) {
            return null;
        }

        // Suppress E_WARNING from a permission-denied read — we surface
        // it as a typed GislConfigError instead. The error suppression
        // is local to this single call.
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new GislConfigError(
                "Failed to read shared credentials file at {$path} "
                . '(permission denied or filesystem error)',
            );
        }

        // INI_SCANNER_RAW + process_sections=true. RAW disables all
        // interpolation; process_sections gives us a [profile]-keyed
        // outer array. parse_ini_string emits an E_WARNING (e.g.
        // "syntax error, unexpected $end of file, expecting ']' in
        // Unknown on line 1") on malformed input — we install a
        // scoped error handler to capture the line-number fragment
        // for diagnostics, then re-raise as a typed GislConfigError.
        // Credential VALUES never enter the message; PHP's warning
        // text describes the syntax token, never the file body.
        $warningLine = null;
        set_error_handler(static function (
            int $_errno,
            string $errstr,
        ) use (&$warningLine): bool {
            if (preg_match('/on line (\d+)/', $errstr, $match) === 1) {
                $warningLine = (int) $match[1];
            }
            return true;
        });
        try {
            $parsed = parse_ini_string($raw, true, INI_SCANNER_RAW);
        } finally {
            restore_error_handler();
        }
        if ($parsed === false) {
            $where = $warningLine !== null
                ? "at line {$warningLine} of {$path}"
                : "at {$path}";
            throw new GislConfigError(
                "Malformed INI in shared credentials file {$where} "
                . "while looking up profile '{$profileName}'",
            );
        }

        if (!isset($parsed[$profileName])) {
            throw new GislConfigError(
                "Profile '{$profileName}' not found in shared credentials file at {$path}",
            );
        }

        $section = $parsed[$profileName];
        if (!is_array($section)) {
            // PHP coerces top-level scalar assignments at the file
            // root (outside any [section]) into a string keyed by
            // option name. If the caller's profile name happened to
            // collide with a root-level key, $parsed[$profileName] is a
            // scalar — we treat that as malformed.
            throw new GislConfigError(
                "Profile '{$profileName}' in shared credentials file at {$path} "
                . 'is not a valid section',
            );
        }

        /** @var array<string, string> $section */
        return $section;
    }
}
