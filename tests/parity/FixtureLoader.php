<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Parity;

use Symfony\Component\Yaml\Yaml;

/**
 * Load + validate every fixture under `tests/parity/fixtures/`.
 *
 * Mirrors `packages/typescript/tests/parity/fixtures.ts:loadFixtures`. The
 * runtime validation (method enum, name-vs-filename, body type whitelist,
 * webhook hex-length) catches the same fixture-author typos the TS runner
 * catches, so a fixture that loads cleanly here loads cleanly there.
 */
final class FixtureLoader
{
    /**
     * Known SDK methods. Mirrors the TS allowlist verbatim — divergence here
     * means a fixture references a method that exists in one runner but not
     * another, and that's a parity bug.
     *
     * Ergonomic-facade verbs `compress` / `thumbnail` / `convert` were
     * added in PHP P2 (7QXkzoIi) alongside the real ergonomic-dispatch
     * wiring in {@see Invoke}. PHP P3 (dxIeLVbP) added `merge` via the
     * new multi-input dispatch path. The TS allowlist at
     * `packages/typescript/tests/parity/fixtures.ts` carries the symmetric
     * additions + the matching dispatch shims in
     * `packages/typescript/tests/parity/invoke.ts`. `watermark` / `archive`
     * / `mapEach` / `bundle` are deliberately STILL OMITTED:
     *
     *   - `watermark`: v2 `OperationType` has no bare `watermark` value
     *     (split into `image_watermark` / `text_watermark`). Needs a
     *     preset-style mapping → tracked alongside the preset matrix.
     *   - `archive`: contract-modeled as MULTI-INPUT (`inputs[]`), not
     *     compatible with the single-input `OperationBuilder` → lands
     *     alongside P4's `.bundle()` archive sugar.
     *   - `mapEach` / `bundle` stay on the P0 seam (Bljva8nj) until P4 ships.
     *
     * @var list<string>
     */
    private const KNOWN_SDK_METHODS = [
        'uploadFile',
        'cancelWorkflow',
        'createExternalImport',
        'createWorkflow',
        'decodeAudioWatermark',
        'getWorkflowStatus',
        'resumeWorkflow',
        'waitForWorkflow',
        'getWorkflowDownloads',
        'streamEvents',
        'getCreditsBalance',
        'getCreditsUsage',
        'getAccountLimits',
        'getMetadata',
        'getSchema',
        'login',
        'logout',
        'preflightClips',
        'probeUpload',
        'retryOperation',
        'submitContact',
        // SDK-3 (Wb6ebOMM) resume-support endpoints.
        'getUploadStatus',
        'presignParts',
        'keepaliveUpload',
        // Webhook mode invokes verifyWebhook directly; not a GislClient method.
        'verifyWebhook',
        // Ergonomic-facade verbs (PHP P2 / 7QXkzoIi). See class docblock
        // for why `watermark` + `archive` are NOT included here yet.
        'compress',
        'thumbnail',
        'convert',
        // Multi-input ergonomic verbs (PHP P3 / dxIeLVbP).
        'merge',
        // File-first builder-chain LOWERING marker (FF2a / MfV0PDok). Not a
        // GislClient method — `mode=lowering` dispatches off the `lowering`
        // block, not the method; `file` is the entry point it exercises.
        'file',
        // File-first HOMOGENEOUS FAN-OUT marker (FF3a / u0hBt6fl). Not a
        // GislClient method — `mode=files` dispatches off the `files` block;
        // `files` is the ergonomic-client entry point it exercises.
        'files',
    ];

    private const ALLOWED_REQUEST_METHODS = [
        'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS',
    ];

    private const ALLOWED_REQUEST_BODY_TYPES = ['json', 'multipart', 'raw', 'empty'];
    private const ALLOWED_RESPONSE_BODY_TYPES = ['json', 'text', 'sse_stream', 'raw', 'empty'];
    private const ALLOWED_MODES = [
        Fixture::MODE_REQUEST_RESPONSE,
        Fixture::MODE_SSE,
        Fixture::MODE_WEBHOOK,
        Fixture::MODE_LOCAL_VALIDATION_ERROR,
        Fixture::MODE_LOWERING,
        // FF2b (tywwynmN) — file-first run-mode execution fixtures.
        Fixture::MODE_RUN,
        // FF3a (u0hBt6fl) — file-first homogeneous fan-out fixtures.
        Fixture::MODE_FILES,
    ];

    private const LOWERING_OPS = ['compress', 'convert', 'thumbnail', 'text_watermark', 'output', 'resize'];
    private const LOWERING_KEYS = ['file', 'resolvedFileId', 'operations', 'watermark'];
    private const LOWERING_FILE_KEYS = ['kind', 'path', 'uploadId', 'key'];
    private const LOWERING_OP_KEYS = ['op', 'optimize', 'format', 'width', 'height', 'text', 'options', 'fit'];
    // FF4a (Z7zTr789) — watermark sub-block keys + post-op grammar (no text_watermark).
    private const WATERMARK_KEYS = ['overlay', 'options', 'post'];
    private const WATERMARK_OVERLAY_KEYS = ['file', 'resolvedFileId', 'operations'];
    private const WATERMARK_POST_OPS = ['compress', 'convert', 'thumbnail'];
    // FF2b (tywwynmN) — run-mode block keys: lowering's file + operations
    // plus the run-only maxWait / pollIntervalMs / useSSE (Fo4R0v4j opt-out).
    private const RUN_KEYS = ['file', 'operations', 'maxWait', 'pollIntervalMs', 'useSSE'];
    // FF5b (u8M49LU2) — submit block keys: lowering's file + operations plus
    // the submit-only optional webhook.
    private const SUBMIT_KEYS = ['file', 'operations', 'webhook'];
    // FF3a (u0hBt6fl) — files block keys: an ordered files[] input list +
    // operations + (lowering variant) resolvedFileIds + (run variant) maxWait /
    // pollIntervalMs.
    // aQMm5khm — `merge` adds the merge-combine sub-block to the files block.
    // Fo4R0v4j — `useSSE` adds the run-variant transport opt-out.
    private const FILES_KEYS = ['files', 'resolvedFileIds', 'operations', 'merge', 'maxWait', 'pollIntervalMs', 'useSSE', 'webhook'];
    // aQMm5khm — keys allowed inside a `files.merge` sub-block.
    private const FILES_MERGE_KEYS = ['options'];
    // aQMm5khm — allowed `files.merge.options` keys (the camelCase MergeOptions
    // fields both runners read). Rejecting unknowns at load time stops a typo'd
    // or snake_case key from being silently dropped by wireMergeOptions, which
    // would leave the fixture passing with the wrong wire shape. Keep in sync
    // with the TS loader's FILES_MERGE_OPTION_KEYS + the MergeOptions VO.
    private const FILES_MERGE_OPTION_KEYS = [
        'transition',
        'crossfadeDuration',
        'gapDuration',
        'normalizeAudio',
        'reEncodeMode',
        'codec',
        'crf',
        'preset',
        'targetResolution',
        'targetSize',
        'transitionDuration',
        'fps',
        'durationPerImage',
        'delay',
        'loopCount',
        'output',
        'videoFormat',
        'outputType',
        'mediaKind',
        'allowUnusedAssets',
    ];

    private const ALLOWED_SCHEMA_VERSIONS = [
        Fixture::SCHEMA_VERSION_V1,
        Fixture::SCHEMA_VERSION_V2,
    ];

    /**
     * @return list<Fixture>
     */
    public static function loadAll(): array
    {
        $dir = FixturePaths::fixturesDir();
        $files = [];
        foreach (\scandir($dir) ?: [] as $entry) {
            if (\str_ends_with($entry, '.yaml') || \str_ends_with($entry, '.yml')) {
                $files[] = $entry;
            }
        }
        \sort($files);

        $out = [];
        foreach ($files as $file) {
            $full = $dir . '/' . $file;
            $raw = Yaml::parseFile($full);
            $out[] = self::validate($raw, $full);
        }
        return $out;
    }

    /**
     * @param mixed  $raw
     */
    private static function validate(mixed $raw, string $file): Fixture
    {
        $base = \basename($file);
        if (!\is_array($raw) || \array_is_list($raw)) {
            throw new \RuntimeException("[{$base}] root must be a mapping");
        }

        $name = self::requireString($raw, 'name', $base);
        if (\preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new \RuntimeException("[{$base}] name must be lowercase snake_case, got \"{$name}\"");
        }
        $expectedName = \preg_replace('/\.ya?ml$/', '', $base) ?? $base;
        if ($name !== $expectedName) {
            throw new \RuntimeException("[{$base}] name \"{$name}\" must match filename \"{$expectedName}\"");
        }

        $mode = isset($raw['mode']) ? self::requireString($raw, 'mode', $base) : Fixture::MODE_REQUEST_RESPONSE;
        if (!\in_array($mode, self::ALLOWED_MODES, true)) {
            throw new \RuntimeException("[{$base}] invalid mode \"{$mode}\"");
        }

        if (!isset($raw['sdk']) || !\is_array($raw['sdk'])) {
            throw new \RuntimeException("[{$base}] sdk must be a mapping");
        }
        /** @var array<string, mixed> $sdkRaw */
        $sdkRaw = $raw['sdk'];
        $method = self::requireString($sdkRaw, 'method', $base . ' sdk');
        if (!\in_array($method, self::KNOWN_SDK_METHODS, true)) {
            throw new \RuntimeException(
                "[{$base}] unknown sdk.method \"{$method}\". Known: " . \implode(', ', self::KNOWN_SDK_METHODS),
            );
        }
        /** @var list<mixed> $args */
        $args = [];
        if (isset($sdkRaw['args'])) {
            if (!\is_array($sdkRaw['args']) || !\array_is_list($sdkRaw['args'])) {
                throw new \RuntimeException("[{$base}] sdk.args must be a sequence");
            }
            $args = $sdkRaw['args'];
        }

        /** @var list<array<string, mixed>> $requests */
        $requests = [];
        if (isset($raw['requests'])) {
            if (!\is_array($raw['requests']) || !\array_is_list($raw['requests'])) {
                throw new \RuntimeException("[{$base}] requests must be a sequence");
            }
            foreach ($raw['requests'] as $i => $req) {
                if (!\is_array($req) || \array_is_list($req)) {
                    throw new \RuntimeException("[{$base}] requests[{$i}] must be a mapping");
                }
                self::validateRequest($req, "{$base} requests[{$i}]");
                /** @var array<string, mixed> $req */
                $requests[] = $req;
            }
        }

        /** @var list<array<string, mixed>> $responses */
        $responses = [];
        if (isset($raw['responses'])) {
            if (!\is_array($raw['responses']) || !\array_is_list($raw['responses'])) {
                throw new \RuntimeException("[{$base}] responses must be a sequence");
            }
            foreach ($raw['responses'] as $i => $res) {
                if (!\is_array($res) || \array_is_list($res)) {
                    throw new \RuntimeException("[{$base}] responses[{$i}] must be a mapping");
                }
                self::validateResponse($res, "{$base} responses[{$i}]");
                /** @var array<string, mixed> $res */
                $responses[] = $res;
            }
        }

        if ($mode === Fixture::MODE_REQUEST_RESPONSE || $mode === Fixture::MODE_SSE) {
            if (\count($requests) === 0) {
                throw new \RuntimeException("[{$base}] mode={$mode} requires at least one request");
            }
            if (\count($requests) !== \count($responses)) {
                throw new \RuntimeException(
                    "[{$base}] requests.length (" . \count($requests) . ") must equal responses.length (" . \count($responses) . ')',
                );
            }
        }
        if ($mode === Fixture::MODE_LOCAL_VALIDATION_ERROR) {
            if (\count($requests) !== 0 || \count($responses) !== 0) {
                throw new \RuntimeException(
                    "[{$base}] mode=local_validation_error must declare zero requests + zero responses",
                );
            }
            if (!\array_key_exists('localValidationError', $raw)) {
                throw new \RuntimeException(
                    "[{$base}] mode=local_validation_error requires a localValidationError block",
                );
            }
        }
        if ($mode === Fixture::MODE_LOWERING) {
            if (\count($requests) !== 0 || \count($responses) !== 0) {
                throw new \RuntimeException(
                    "[{$base}] mode=lowering must declare zero requests + zero responses (lowering is network-free)",
                );
            }
            if ($method !== 'file') {
                throw new \RuntimeException(
                    "[{$base}] mode=lowering requires sdk.method=\"file\" (got \"{$method}\")",
                );
            }
            if (!\array_key_exists('lowering', $raw) || !\array_key_exists('expected_payload', $raw)) {
                throw new \RuntimeException(
                    "[{$base}] mode=lowering requires both a lowering block and an expected_payload block",
                );
            }
        }
        if ($mode === Fixture::MODE_RUN) {
            // FF2b (tywwynmN) — run-mode declares the mocked upload/create/
            // terminal/downloads `responses` but NO `requests` assertions (wire
            // parity is covered by mode=lowering + the low-level method
            // fixtures; run-mode pins the hydrated RunResult).
            if (\count($requests) !== 0) {
                throw new \RuntimeException(
                    "[{$base}] mode=run must declare zero `requests` (it asserts the hydrated RunResult, not wire requests)",
                );
            }
            if ($method !== 'file') {
                throw new \RuntimeException(
                    "[{$base}] mode=run requires sdk.method=\"file\" (got \"{$method}\")",
                );
            }
            if (!\array_key_exists('run', $raw) || !\array_key_exists('expected_run_result', $raw)) {
                throw new \RuntimeException(
                    "[{$base}] mode=run requires both a run block and an expected_run_result block",
                );
            }
        }
        if ($mode === Fixture::MODE_FILES) {
            // FF3a (u0hBt6fl) — homogeneous fan-out. The lowering/run variants
            // declare the mocked responses but NO `requests`; the uUnCtVAr
            // SUBMIT variant (files.webhook) DOES declare requests (the create
            // callback_url assertion). Asserts EXACTLY ONE of expected_payload
            // (lowering — zero responses) / expected_run_result (run) /
            // expected_return+requests (submit), discriminated below.
            if ($method !== 'files') {
                throw new \RuntimeException(
                    "[{$base}] mode=files requires sdk.method=\"files\" (got \"{$method}\")",
                );
            }
            if (!\array_key_exists('files', $raw)) {
                throw new \RuntimeException("[{$base}] mode=files requires a files block");
            }
            $filesBlock = \is_array($raw['files']) ? $raw['files'] : [];
            // aQMm5khm — the merge-combine path is LOWERING-ONLY (run/submit
            // merge is out of scope). Reject files.merge on any non-lowering
            // variant (run = expected_run_result, submit = files.webhook) so a
            // misplaced merge block can't silently no-op — runFiles/submitFiles
            // build a plain FilesRecipe and would ignore the merge block.
            if (\array_key_exists('merge', $filesBlock) && !\array_key_exists('expected_payload', $raw)) {
                throw new \RuntimeException(
                    "[{$base}] files.merge is only supported in the lowering variant (expected_payload); a run/submit mode=files fixture cannot carry a merge block",
                );
            }
            $isFilesSubmit = \array_key_exists('webhook', $filesBlock);
            if (!$isFilesSubmit && \count($requests) !== 0) {
                throw new \RuntimeException(
                    "[{$base}] mode=files (lowering/run variant) must declare zero `requests` (it asserts the lowered payload or the partitioned RunResult, not wire requests)",
                );
            }
            if ($isFilesSubmit) {
                // uUnCtVAr (FF3a-submit) — SUBMIT variant: drives
                // FilesRecipe->submit() through the standard request_response
                // flow, so it DOES declare requests (the create callback_url
                // assertion) + expected_return, and must NOT carry the
                // lowering/run assertion keys. The zero-requests guard above is
                // therefore skipped for this variant — re-check it here.
                if (!\is_string($filesBlock['webhook']) || $filesBlock['webhook'] === '') {
                    throw new \RuntimeException(
                        "[{$base}] mode=files files.webhook must be a non-empty string (the submit-variant callback_url)",
                    );
                }
                if (\array_key_exists('expected_payload', $raw) || \array_key_exists('expected_run_result', $raw)) {
                    throw new \RuntimeException(
                        "[{$base}] mode=files submit variant (files.webhook) must NOT declare expected_payload/expected_run_result — assert the create request + expected_return instead",
                    );
                }
                if (\count($requests) === 0) {
                    throw new \RuntimeException(
                        "[{$base}] mode=files submit variant requires at least one request (the create callback_url assertion)",
                    );
                }
                if (!\array_key_exists('expected_return', $raw)) {
                    throw new \RuntimeException(
                        "[{$base}] mode=files submit variant requires expected_return (the returned Handle assertion)",
                    );
                }
            } else {
                $hasPayload = \array_key_exists('expected_payload', $raw);
                $hasRunResult = \array_key_exists('expected_run_result', $raw);
                if ($hasPayload === $hasRunResult) {
                    throw new \RuntimeException(
                        "[{$base}] mode=files requires EXACTLY ONE of expected_payload (lowering variant) or expected_run_result (run variant)",
                    );
                }
                if ($hasPayload && \count($responses) !== 0) {
                    throw new \RuntimeException(
                        "[{$base}] mode=files lowering variant (expected_payload) must declare zero responses (lowering is network-free)",
                    );
                }
            }
        }
        // FF5b (u8M49LU2) — a `submit` block routes a file-first chain through
        // the STANDARD request_response flow (so compareRequests can assert the
        // create callback_url). Gated to request_response mode + method:file.
        // The length-pair check above already enforces matching requests +
        // responses. A bare method:file in request_response WITHOUT a submit
        // block has no dispatch, so reject it.
        if (\array_key_exists('submit', $raw)) {
            if ($mode !== Fixture::MODE_REQUEST_RESPONSE) {
                throw new \RuntimeException(
                    "[{$base}] a submit block requires the default request_response mode (got mode=\"{$mode}\"); "
                    . 'mode=run forbids a requests block and cannot assert the create callback_url',
                );
            }
            if ($method !== 'file') {
                throw new \RuntimeException(
                    "[{$base}] a submit block requires sdk.method=\"file\" (got \"{$method}\")",
                );
            }
        } elseif ($method === 'file' && $mode === Fixture::MODE_REQUEST_RESPONSE) {
            throw new \RuntimeException(
                "[{$base}] sdk.method=\"file\" in request_response mode requires a submit block (FF5b). "
                . 'Use mode=lowering / mode=run for the other file-first dispatches.',
            );
        }

        $webhook = null;
        if ($mode === Fixture::MODE_WEBHOOK) {
            if ($method !== 'verifyWebhook') {
                throw new \RuntimeException(
                    "[{$base}] mode=webhook requires sdk.method=\"verifyWebhook\" (got \"{$method}\")",
                );
            }
            if (!isset($raw['webhook']) || !\is_array($raw['webhook']) || \array_is_list($raw['webhook'])) {
                throw new \RuntimeException("[{$base}] webhook must be a mapping");
            }
            /** @var array<string, mixed> $w */
            $w = $raw['webhook'];
            if (\count($requests) > 0 || \count($responses) > 0) {
                throw new \RuntimeException(
                    "[{$base}] mode=webhook must not declare requests/responses",
                );
            }
            $hex = self::requireString($w, 'expected_signature_hex', $base . ' webhook');
            if (\preg_match('/^[0-9a-f]{64}$/', $hex) !== 1) {
                throw new \RuntimeException(
                    "[{$base}] webhook.expected_signature_hex must be 64 lowercase hex chars",
                );
            }
            $webhook = [
                'secret' => self::requireString($w, 'secret', $base . ' webhook'),
                'body' => self::requireString($w, 'body', $base . ' webhook'),
                'header_name' => isset($w['header_name']) ? (string) $w['header_name'] : 'x-gis-signature',
                'header_format' => isset($w['header_format']) ? (string) $w['header_format'] : 'sha256={hex}',
                'expected_signature_hex' => $hex,
                'algorithm' => isset($w['algorithm']) ? (string) $w['algorithm'] : 'hmac-sha256',
            ];
        }

        $description = isset($raw['description']) ? (string) $raw['description'] : $name;
        $hasExpectedReturn = \array_key_exists('expected_return', $raw);
        $expectedReturn = $hasExpectedReturn ? $raw['expected_return'] : null;
        $expectsError = ($raw['expects_error'] ?? false) === true;

        // Vgg8yITh — cross-SDK error-message parity. Must be a string, and is
        // only meaningful with `expects_error: true`; reject otherwise so an
        // author typo fails loud at load instead of silently skipping the
        // assertion (the PHP loader is otherwise tolerant of unknown keys, so
        // an un-parsed field would be the silent false-green to avoid).
        $expectedErrorMessage = null;
        if (\array_key_exists('expected_error_message', $raw)) {
            if (!\is_string($raw['expected_error_message'])) {
                throw new \RuntimeException("[{$base}] expected_error_message must be a string");
            }
            if (!$expectsError) {
                throw new \RuntimeException("[{$base}] expected_error_message requires expects_error: true");
            }
            $expectedErrorMessage = $raw['expected_error_message'];
        }

        // F4-A — schema-version discrimination + v2 assertion blocks.
        // PHP loader is naturally tolerant of unknown top-level keys; the
        // schema-version branch here is enforcement, not gatekeeping. v2
        // blocks on a v1 fixture are an authoring mistake.
        $schemaVersion = Fixture::SCHEMA_VERSION_V1;
        if (\array_key_exists('fixtureSchemaVersion', $raw)) {
            $rawVersion = $raw['fixtureSchemaVersion'];
            if (!\is_string($rawVersion) || !\in_array($rawVersion, self::ALLOWED_SCHEMA_VERSIONS, true)) {
                throw new \RuntimeException(
                    "[{$base}] fixtureSchemaVersion must be '1.0.0' or '2.0.0'",
                );
            }
            $schemaVersion = $rawVersion;
        }
        $hasV2Block =
            \array_key_exists('resolvedOptions', $raw)
            || \array_key_exists('omittedFromWire', $raw)
            || \array_key_exists('localValidationError', $raw)
            || \array_key_exists('lowering', $raw)
            || \array_key_exists('expected_payload', $raw)
            // FF2b (tywwynmN) — run-mode blocks are v2-only.
            || \array_key_exists('run', $raw)
            || \array_key_exists('expected_run_result', $raw)
            // FF5b (u8M49LU2) — submit blocks are v2-only.
            || \array_key_exists('submit', $raw)
            // FF3a (u0hBt6fl) — files blocks are v2-only.
            || \array_key_exists('files', $raw);
        if ($schemaVersion === Fixture::SCHEMA_VERSION_V1 && $hasV2Block) {
            throw new \RuntimeException(
                "[{$base}] v2 assertion blocks (resolvedOptions / omittedFromWire / localValidationError / lowering / expected_payload / run / submit / files) require fixtureSchemaVersion: '2.0.0'",
            );
        }
        if ($mode === Fixture::MODE_LOCAL_VALIDATION_ERROR && $schemaVersion !== Fixture::SCHEMA_VERSION_V2) {
            throw new \RuntimeException(
                "[{$base}] mode='local_validation_error' requires fixtureSchemaVersion: '2.0.0'",
            );
        }
        if ($mode === Fixture::MODE_LOWERING && $schemaVersion !== Fixture::SCHEMA_VERSION_V2) {
            throw new \RuntimeException(
                "[{$base}] mode='lowering' requires fixtureSchemaVersion: '2.0.0'",
            );
        }
        if ($mode === Fixture::MODE_RUN && $schemaVersion !== Fixture::SCHEMA_VERSION_V2) {
            throw new \RuntimeException(
                "[{$base}] mode='run' requires fixtureSchemaVersion: '2.0.0'",
            );
        }
        if ($mode === Fixture::MODE_FILES && $schemaVersion !== Fixture::SCHEMA_VERSION_V2) {
            throw new \RuntimeException(
                "[{$base}] mode='files' requires fixtureSchemaVersion: '2.0.0'",
            );
        }

        // Use array_key_exists (NOT isset) so an explicit `resolvedOptions:
        // null` is rejected rather than silently treated as absent — isset()
        // returns false on null, which would pass the v2-block gate above
        // (which uses array_key_exists) yet skip the assertion. The TS loader
        // enters validation on `!== undefined` and throws on null, so PHP must
        // too or the two runners diverge on the same fixture.
        $resolvedOptions = null;
        if (\array_key_exists('resolvedOptions', $raw)) {
            if (!\is_array($raw['resolvedOptions']) || \array_is_list($raw['resolvedOptions'])) {
                throw new \RuntimeException("[{$base}] resolvedOptions must be a mapping");
            }
            /** @var array<string, mixed> $resolvedOptions */
            $resolvedOptions = $raw['resolvedOptions'];
        }

        $omittedFromWire = null;
        if (\array_key_exists('omittedFromWire', $raw)) {
            if (!\is_array($raw['omittedFromWire']) || !\array_is_list($raw['omittedFromWire'])) {
                throw new \RuntimeException(
                    "[{$base}] omittedFromWire must be a sequence of strings",
                );
            }
            foreach ($raw['omittedFromWire'] as $idx => $field) {
                if (!\is_string($field) || $field === '') {
                    throw new \RuntimeException(
                        "[{$base}] omittedFromWire[{$idx}] must be a non-empty string",
                    );
                }
            }
            /** @var list<string> $omittedFromWire */
            $omittedFromWire = $raw['omittedFromWire'];
        }

        $localValidationError = null;
        if (\array_key_exists('localValidationError', $raw)) {
            if (!\is_array($raw['localValidationError']) || \array_is_list($raw['localValidationError'])) {
                throw new \RuntimeException(
                    "[{$base}] localValidationError must be a mapping",
                );
            }
            /** @var array<string, mixed> $localValidationError */
            $localValidationError = $raw['localValidationError'];
            $category = $localValidationError['category'] ?? null;
            if ($category !== 'validation' && $category !== 'config') {
                throw new \RuntimeException(
                    "[{$base}] localValidationError.category must be 'validation' or 'config'",
                );
            }
            if (!isset($localValidationError['code']) || !\is_string($localValidationError['code']) || $localValidationError['code'] === '') {
                throw new \RuntimeException(
                    "[{$base}] localValidationError.code must be a non-empty string",
                );
            }
        }

        $lowering = null;
        if (\array_key_exists('lowering', $raw)) {
            $lowering = self::validateLowering($raw['lowering'], $base);
        }
        // expected_payload is the lowering ASSERTION target for BOTH the FF2a
        // `lowering` mode and the FF3a `files` lowering variant (which carries
        // no `lowering` block), so set it from the top-level key independently
        // of the block above.
        $expectedPayload = $raw['expected_payload'] ?? null;

        // FF2b (tywwynmN) — run-mode block. Reuses the lowering file + op-param
        // validators (the chain grammar is identical), plus run-only maxWait /
        // pollIntervalMs keys.
        $run = null;
        if (\array_key_exists('run', $raw)) {
            $run = self::validateRun($raw['run'], $base);
        }
        $hasExpectedRunResult = \array_key_exists('expected_run_result', $raw);
        $expectedRunResult = $hasExpectedRunResult ? $raw['expected_run_result'] : null;

        // FF5b (u8M49LU2) — submit block. Reuses the lowering file + op-param
        // validators (the chain grammar is identical), plus the submit-only
        // optional webhook key.
        $submit = null;
        if (\array_key_exists('submit', $raw)) {
            $submit = self::validateSubmit($raw['submit'], $base);
        }

        // FF3a (u0hBt6fl) — files block. Reuses the lowering file + op-param
        // validators PER ENTRY (the per-input + chain grammar is identical),
        // plus the fan-out-only resolvedFileIds + run-only maxWait/pollIntervalMs.
        $files = null;
        if (\array_key_exists('files', $raw)) {
            $files = self::validateFiles($raw['files'], $base);
        }

        return new Fixture(
            name: $name,
            description: $description,
            mode: $mode,
            sdkMethod: $method,
            args: $args,
            requests: $requests,
            responses: $responses,
            expectedReturn: $expectedReturn,
            hasExpectedReturn: $hasExpectedReturn,
            webhook: $webhook,
            expectsError: $expectsError,
            expectedErrorMessage: $expectedErrorMessage,
            absolutePath: $file,
            schemaVersion: $schemaVersion,
            resolvedOptions: $resolvedOptions,
            omittedFromWire: $omittedFromWire,
            localValidationError: $localValidationError,
            lowering: $lowering,
            expectedPayload: $expectedPayload,
            run: $run,
            expectedRunResult: $expectedRunResult,
            hasExpectedRunResult: $hasExpectedRunResult,
            submit: $submit,
            files: $files,
        );
    }

    /**
     * Validate the FF3a `files` block: `{files: [{kind, path?/uploadId?, key?},
     * ...], operations: [{op, ...params}], resolvedFileIds?: [string],
     * maxWait?, pollIntervalMs?}`. Reuses the lowering file + op-param
     * validators PER ENTRY (the per-input + chain grammar is identical).
     * Mirrors the TS `validateFiles`.
     *
     * @return array<string, mixed>
     */
    private static function validateFiles(mixed $raw, string $base): array
    {
        if (!\is_array($raw) || \array_is_list($raw)) {
            throw new \RuntimeException("[{$base}] files must be a mapping");
        }
        self::rejectUnknownLoweringKeys($raw, self::FILES_KEYS, "{$base} files");

        $inputs = $raw['files'] ?? null;
        if (!\is_array($inputs) || !\array_is_list($inputs) || \count($inputs) === 0) {
            throw new \RuntimeException("[{$base}] files.files must be a non-empty sequence of file inputs");
        }
        foreach ($inputs as $i => $file) {
            if (!\is_array($file) || \array_is_list($file)) {
                throw new \RuntimeException("[{$base}] files.files[{$i}] must be a mapping");
            }
            self::rejectUnknownLoweringKeys($file, self::LOWERING_FILE_KEYS, "{$base} files.files[{$i}]");
            $kind = $file['kind'] ?? null;
            if ($kind !== 'path' && $kind !== 'upload_id') {
                throw new \RuntimeException("[{$base}] files.files[{$i}].kind must be 'path' or 'upload_id'");
            }
            if ($kind === 'path' && (!isset($file['path']) || !\is_string($file['path']) || $file['path'] === '')) {
                throw new \RuntimeException("[{$base}] files.files[{$i}].path must be a non-empty string when kind=path");
            }
            if ($kind === 'upload_id' && (!isset($file['uploadId']) || !\is_string($file['uploadId']) || $file['uploadId'] === '')) {
                throw new \RuntimeException("[{$base}] files.files[{$i}].uploadId must be a non-empty string when kind=upload_id");
            }
        }

        if (\array_key_exists('resolvedFileIds', $raw)) {
            $ids = $raw['resolvedFileIds'];
            if (!\is_array($ids) || !\array_is_list($ids)) {
                throw new \RuntimeException("[{$base}] files.resolvedFileIds must be a sequence of strings");
            }
            foreach ($ids as $idx => $id) {
                if (!\is_string($id) || $id === '') {
                    throw new \RuntimeException("[{$base}] files.resolvedFileIds[{$idx}] must be a non-empty string");
                }
            }
            if (\count($ids) !== \count($inputs)) {
                throw new \RuntimeException(
                    "[{$base}] files.resolvedFileIds length (" . \count($ids) . ') must equal files.files length ('
                    . \count($inputs) . ') — one resolved id per input',
                );
            }
        }

        // aQMm5khm — a `merge` sub-block switches the lowering variant to the
        // merge-combine path; `operations` then carry the POST-COMBINE ops and
        // MAY be empty (a bare merge). Without it, `operations` are the shared
        // fan-out chain and stay non-empty (FF3a). Mirrors the TS loader.
        $hasMerge = \array_key_exists('merge', $raw);
        if ($hasMerge) {
            $mergeBlock = $raw['merge'];
            if (!\is_array($mergeBlock) || \array_is_list($mergeBlock)) {
                throw new \RuntimeException("[{$base}] files.merge must be a mapping");
            }
            self::rejectUnknownLoweringKeys($mergeBlock, self::FILES_MERGE_KEYS, "{$base} files.merge");
            if (\array_key_exists('options', $mergeBlock)) {
                if (!\is_array($mergeBlock['options']) || \array_is_list($mergeBlock['options'])) {
                    throw new \RuntimeException("[{$base}] files.merge.options must be a mapping");
                }
                self::rejectUnknownLoweringKeys(
                    $mergeBlock['options'],
                    self::FILES_MERGE_OPTION_KEYS,
                    "{$base} files.merge.options",
                );
            }
            // NOTE: the merge-combine path is LOWERING-ONLY (run/submit merge is
            // out of scope). The "merge requires the expected_payload lowering
            // variant" guard lives in the mode=files discriminator (validate()),
            // where expected_payload / expected_run_result / webhook are all
            // visible — here in validateFiles only the files block is in scope.
        }

        $ops = $raw['operations'] ?? null;
        if (!\is_array($ops) || !\array_is_list($ops) || (!$hasMerge && \count($ops) === 0)) {
            throw new \RuntimeException(
                "[{$base}] files.operations must be " . ($hasMerge ? 'a sequence (may be empty for a bare merge)' : 'a non-empty sequence'),
            );
        }
        foreach ($ops as $i => $op) {
            if (!\is_array($op) || \array_is_list($op)) {
                throw new \RuntimeException("[{$base}] files.operations[{$i}] must be a mapping");
            }
            self::rejectUnknownLoweringKeys($op, self::LOWERING_OP_KEYS, "{$base} files.operations[{$i}]");
            $opName = $op['op'] ?? null;
            if (!\is_string($opName) || !\in_array($opName, self::LOWERING_OPS, true)) {
                throw new \RuntimeException(
                    "[{$base}] files.operations[{$i}].op must be one of " . \implode('|', self::LOWERING_OPS),
                );
            }
            // text_watermark has no MergedRecipe equivalent (the merged output
            // exposes compress/convert/thumbnail only) — reject it as a
            // post-combine op at load time. Mirrors the TS loader.
            if ($hasMerge && $opName === 'text_watermark') {
                throw new \RuntimeException(
                    "[{$base}] files.operations[{$i}].op 'text_watermark' is not a valid post-merge op (MergedRecipe exposes compress/convert/thumbnail only)",
                );
            }
            self::validateLoweringOpParams($opName, $op, "{$base} files.operations[{$i}]");
        }
        if (\array_key_exists('maxWait', $raw) && !\is_string($raw['maxWait']) && !\is_int($raw['maxWait'])) {
            throw new \RuntimeException("[{$base}] files.maxWait must be a string or int when present");
        }
        if (\array_key_exists('pollIntervalMs', $raw) && !\is_int($raw['pollIntervalMs'])) {
            throw new \RuntimeException("[{$base}] files.pollIntervalMs must be an int when present");
        }
        if (\array_key_exists('useSSE', $raw) && !\is_bool($raw['useSSE'])) {
            throw new \RuntimeException("[{$base}] files.useSSE must be a bool when present");
        }
        if (\array_key_exists('webhook', $raw) && !\is_string($raw['webhook'])) {
            throw new \RuntimeException("[{$base}] files.webhook must be a string when present (submit variant)");
        }
        /** @var array<string, mixed> $raw */
        return $raw;
    }

    /**
     * Validate the FF2b `run` block: `{file: {kind, path?/uploadId?, key?},
     * operations: [{op, ...params}], maxWait?, pollIntervalMs?}`. Reuses the
     * lowering `file` + op-param validators (the chain grammar is identical);
     * run adds the run-only `maxWait` / `pollIntervalMs` keys.
     *
     * @return array<string, mixed>
     */
    private static function validateRun(mixed $raw, string $base): array
    {
        if (!\is_array($raw) || \array_is_list($raw)) {
            throw new \RuntimeException("[{$base}] run must be a mapping");
        }
        self::rejectUnknownLoweringKeys($raw, self::RUN_KEYS, "{$base} run");
        $file = $raw['file'] ?? null;
        if (!\is_array($file) || \array_is_list($file)) {
            throw new \RuntimeException("[{$base}] run.file must be a mapping");
        }
        self::rejectUnknownLoweringKeys($file, self::LOWERING_FILE_KEYS, "{$base} run.file");
        $kind = $file['kind'] ?? null;
        if ($kind !== 'path' && $kind !== 'upload_id') {
            throw new \RuntimeException("[{$base}] run.file.kind must be 'path' or 'upload_id'");
        }
        if ($kind === 'path' && (!isset($file['path']) || !\is_string($file['path']) || $file['path'] === '')) {
            throw new \RuntimeException("[{$base}] run.file.path must be a non-empty string when kind=path");
        }
        if ($kind === 'upload_id' && (!isset($file['uploadId']) || !\is_string($file['uploadId']) || $file['uploadId'] === '')) {
            throw new \RuntimeException("[{$base}] run.file.uploadId must be a non-empty string when kind=upload_id");
        }
        $ops = $raw['operations'] ?? null;
        if (!\is_array($ops) || !\array_is_list($ops) || \count($ops) === 0) {
            throw new \RuntimeException("[{$base}] run.operations must be a non-empty sequence");
        }
        foreach ($ops as $i => $op) {
            if (!\is_array($op) || \array_is_list($op)) {
                throw new \RuntimeException("[{$base}] run.operations[{$i}] must be a mapping");
            }
            self::rejectUnknownLoweringKeys($op, self::LOWERING_OP_KEYS, "{$base} run.operations[{$i}]");
            $opName = $op['op'] ?? null;
            if (!\is_string($opName) || !\in_array($opName, self::LOWERING_OPS, true)) {
                throw new \RuntimeException(
                    "[{$base}] run.operations[{$i}].op must be one of " . \implode('|', self::LOWERING_OPS),
                );
            }
            self::validateLoweringOpParams($opName, $op, "{$base} run.operations[{$i}]");
        }
        if (\array_key_exists('maxWait', $raw) && !\is_string($raw['maxWait']) && !\is_int($raw['maxWait'])) {
            throw new \RuntimeException("[{$base}] run.maxWait must be a string or int when present");
        }
        if (\array_key_exists('pollIntervalMs', $raw) && !\is_int($raw['pollIntervalMs'])) {
            throw new \RuntimeException("[{$base}] run.pollIntervalMs must be an int when present");
        }
        if (\array_key_exists('useSSE', $raw) && !\is_bool($raw['useSSE'])) {
            throw new \RuntimeException("[{$base}] run.useSSE must be a bool when present");
        }
        /** @var array<string, mixed> $raw */
        return $raw;
    }

    /**
     * Validate the FF5b `submit` block: `{file: {kind, path?/uploadId?, key?},
     * operations: [{op, ...params}], webhook?}`. Reuses the lowering `file` +
     * op-param validators (the chain grammar is identical); submit adds the
     * submit-only optional `webhook` key. Mirrors the TS `validateSubmit`.
     *
     * @return array<string, mixed>
     */
    private static function validateSubmit(mixed $raw, string $base): array
    {
        if (!\is_array($raw) || \array_is_list($raw)) {
            throw new \RuntimeException("[{$base}] submit must be a mapping");
        }
        self::rejectUnknownLoweringKeys($raw, self::SUBMIT_KEYS, "{$base} submit");
        $file = $raw['file'] ?? null;
        if (!\is_array($file) || \array_is_list($file)) {
            throw new \RuntimeException("[{$base}] submit.file must be a mapping");
        }
        self::rejectUnknownLoweringKeys($file, self::LOWERING_FILE_KEYS, "{$base} submit.file");
        $kind = $file['kind'] ?? null;
        if ($kind !== 'path' && $kind !== 'upload_id') {
            throw new \RuntimeException("[{$base}] submit.file.kind must be 'path' or 'upload_id'");
        }
        if ($kind === 'path' && (!isset($file['path']) || !\is_string($file['path']) || $file['path'] === '')) {
            throw new \RuntimeException("[{$base}] submit.file.path must be a non-empty string when kind=path");
        }
        if ($kind === 'upload_id' && (!isset($file['uploadId']) || !\is_string($file['uploadId']) || $file['uploadId'] === '')) {
            throw new \RuntimeException("[{$base}] submit.file.uploadId must be a non-empty string when kind=upload_id");
        }
        $ops = $raw['operations'] ?? null;
        if (!\is_array($ops) || !\array_is_list($ops) || \count($ops) === 0) {
            throw new \RuntimeException("[{$base}] submit.operations must be a non-empty sequence");
        }
        foreach ($ops as $i => $op) {
            if (!\is_array($op) || \array_is_list($op)) {
                throw new \RuntimeException("[{$base}] submit.operations[{$i}] must be a mapping");
            }
            self::rejectUnknownLoweringKeys($op, self::LOWERING_OP_KEYS, "{$base} submit.operations[{$i}]");
            $opName = $op['op'] ?? null;
            if (!\is_string($opName) || !\in_array($opName, self::LOWERING_OPS, true)) {
                throw new \RuntimeException(
                    "[{$base}] submit.operations[{$i}].op must be one of " . \implode('|', self::LOWERING_OPS),
                );
            }
            self::validateLoweringOpParams($opName, $op, "{$base} submit.operations[{$i}]");
        }
        if (\array_key_exists('webhook', $raw) && (!\is_string($raw['webhook']) || $raw['webhook'] === '')) {
            throw new \RuntimeException("[{$base}] submit.webhook must be a non-empty string when present");
        }
        /** @var array<string, mixed> $raw */
        return $raw;
    }

    /**
     * Validate the FF2a `lowering` block: `{file: {kind, path?/uploadId?,
     * key?}, resolvedFileId, operations: [{op, ...params}]}`. Mirrors the TS
     * loader's validation so both runners reject the same authoring typos.
     *
     * @return array<string, mixed>
     */
    private static function validateLowering(mixed $raw, string $base): array
    {
        if (!\is_array($raw) || \array_is_list($raw)) {
            throw new \RuntimeException("[{$base}] lowering must be a mapping");
        }
        self::rejectUnknownLoweringKeys($raw, self::LOWERING_KEYS, "{$base} lowering");
        $file = $raw['file'] ?? null;
        if (!\is_array($file) || \array_is_list($file)) {
            throw new \RuntimeException("[{$base}] lowering.file must be a mapping");
        }
        self::rejectUnknownLoweringKeys($file, self::LOWERING_FILE_KEYS, "{$base} lowering.file");
        $kind = $file['kind'] ?? null;
        if ($kind !== 'path' && $kind !== 'upload_id') {
            throw new \RuntimeException("[{$base}] lowering.file.kind must be 'path' or 'upload_id'");
        }
        if ($kind === 'path' && (!isset($file['path']) || !\is_string($file['path']) || $file['path'] === '')) {
            throw new \RuntimeException("[{$base}] lowering.file.path must be a non-empty string when kind=path");
        }
        if ($kind === 'upload_id' && (!isset($file['uploadId']) || !\is_string($file['uploadId']) || $file['uploadId'] === '')) {
            throw new \RuntimeException("[{$base}] lowering.file.uploadId must be a non-empty string when kind=upload_id");
        }
        $resolvedFileId = self::requireString($raw, 'resolvedFileId', $base . ' lowering');
        // For a pre-uploaded input the resolved source id IS the upload id —
        // enforce they match so a mismatched fixture can't silently lower
        // against `resolvedFileId` and mask the wrong intent.
        if ($kind === 'upload_id' && isset($file['uploadId']) && $file['uploadId'] !== $resolvedFileId) {
            throw new \RuntimeException(
                "[{$base}] lowering.resolvedFileId must equal lowering.file.uploadId for kind=upload_id",
            );
        }
        // FF4a — a `watermark` block makes `operations` the BASE preceding steps,
        // which MAY be empty; a plain chain lowering still requires non-empty ops.
        $hasWatermark = isset($raw['watermark']);
        $ops = $raw['operations'] ?? null;
        if (!\is_array($ops) || !\array_is_list($ops) || (\count($ops) === 0 && !$hasWatermark)) {
            throw new \RuntimeException("[{$base}] lowering.operations must be a non-empty sequence");
        }
        foreach ($ops as $i => $op) {
            if (!\is_array($op) || \array_is_list($op)) {
                throw new \RuntimeException("[{$base}] lowering.operations[{$i}] must be a mapping");
            }
            self::rejectUnknownLoweringKeys($op, self::LOWERING_OP_KEYS, "{$base} lowering.operations[{$i}]");
            $opName = $op['op'] ?? null;
            if (!\is_string($opName) || !\in_array($opName, self::LOWERING_OPS, true)) {
                throw new \RuntimeException(
                    "[{$base}] lowering.operations[{$i}].op must be one of " . \implode('|', self::LOWERING_OPS),
                );
            }
            self::validateLoweringOpParams($opName, $op, "{$base} lowering.operations[{$i}]");
        }
        if ($hasWatermark) {
            self::validateWatermark($raw['watermark'], "{$base} lowering.watermark");
        }
        /** @var array<string, mixed> $raw */
        return $raw;
    }

    /**
     * FF4a (Z7zTr789) — validate the `lowering.watermark` sub-block: the overlay
     * file-node (file + resolvedFileId + optional own ops), the wire options bag,
     * and the post-watermark ops (compress/convert/thumbnail ONLY). Mirrors the TS
     * `validateWatermark`.
     */
    private static function validateWatermark(mixed $raw, string $ctx): void
    {
        if (!\is_array($raw) || \array_is_list($raw)) {
            throw new \RuntimeException("[{$ctx}] must be a mapping");
        }
        self::rejectUnknownLoweringKeys($raw, self::WATERMARK_KEYS, $ctx);

        $overlay = $raw['overlay'] ?? null;
        if (!\is_array($overlay) || \array_is_list($overlay)) {
            throw new \RuntimeException("[{$ctx}] overlay must be a mapping");
        }
        self::rejectUnknownLoweringKeys($overlay, self::WATERMARK_OVERLAY_KEYS, "{$ctx} overlay");
        $file = $overlay['file'] ?? null;
        if (!\is_array($file) || \array_is_list($file)) {
            throw new \RuntimeException("[{$ctx}] overlay.file must be a mapping");
        }
        self::rejectUnknownLoweringKeys($file, self::LOWERING_FILE_KEYS, "{$ctx} overlay.file");
        $kind = $file['kind'] ?? null;
        if ($kind !== 'path' && $kind !== 'upload_id') {
            throw new \RuntimeException("[{$ctx}] overlay.file.kind must be 'path' or 'upload_id'");
        }
        if ($kind === 'path' && (!isset($file['path']) || !\is_string($file['path']) || $file['path'] === '')) {
            throw new \RuntimeException("[{$ctx}] overlay.file.path must be a non-empty string when kind=path");
        }
        if ($kind === 'upload_id' && (!isset($file['uploadId']) || !\is_string($file['uploadId']) || $file['uploadId'] === '')) {
            throw new \RuntimeException("[{$ctx}] overlay.file.uploadId must be a non-empty string when kind=upload_id");
        }
        $overlayResolved = self::requireString($overlay, 'resolvedFileId', $ctx . ' overlay');
        if ($kind === 'upload_id' && isset($file['uploadId']) && $file['uploadId'] !== $overlayResolved) {
            throw new \RuntimeException("[{$ctx}] overlay.resolvedFileId must equal overlay.file.uploadId for kind=upload_id");
        }

        self::validateWatermarkOps($overlay['operations'] ?? null, self::LOWERING_OPS, "{$ctx} overlay.operations");
        self::validateWatermarkOps($raw['post'] ?? null, self::WATERMARK_POST_OPS, "{$ctx} post");

        if (isset($raw['options']) && (!\is_array($raw['options']) || \array_is_list($raw['options']))) {
            throw new \RuntimeException("[{$ctx}] options must be a mapping");
        }
    }

    /**
     * Validate an optional watermark op array against an allowed-op set.
     *
     * @param list<string> $allowed
     */
    private static function validateWatermarkOps(mixed $ops, array $allowed, string $ctx): void
    {
        if ($ops === null) {
            return;
        }
        if (!\is_array($ops) || !\array_is_list($ops)) {
            throw new \RuntimeException("[{$ctx}] must be a sequence");
        }
        foreach ($ops as $i => $op) {
            if (!\is_array($op) || \array_is_list($op)) {
                throw new \RuntimeException("[{$ctx}][{$i}] must be a mapping");
            }
            self::rejectUnknownLoweringKeys($op, self::LOWERING_OP_KEYS, "{$ctx}[{$i}]");
            $opName = $op['op'] ?? null;
            if (!\is_string($opName) || !\in_array($opName, $allowed, true)) {
                throw new \RuntimeException("[{$ctx}][{$i}].op must be one of " . \implode('|', $allowed));
            }
            self::validateLoweringOpParams($opName, $op, "{$ctx}[{$i}]");
        }
    }

    /**
     * Reject unknown keys in a lowering sub-map so a fixture typo fails fast in
     * PHP too (the TS loader already rejects via `rejectUnknownKeys`). Mirrors
     * the TS allowlists so both runners reject the same authoring mistakes.
     *
     * @param array<string, mixed> $raw
     * @param list<string>         $allowed
     */
    private static function rejectUnknownLoweringKeys(array $raw, array $allowed, string $ctx): void
    {
        $extras = \array_diff(\array_keys($raw), $allowed);
        if (\count($extras) > 0) {
            throw new \RuntimeException(
                "[{$ctx}] unknown key(s): " . \implode(', ', $extras) . '. Allowed: ' . \implode(', ', $allowed),
            );
        }
    }

    /**
     * Validate per-op required params + scalar types so a malformed fixture
     * fails at load instead of casting (`(string) null` → `''`, `(int) null`
     * → `0`) and comparing a payload the author never intended.
     *
     * @param array<string, mixed> $op
     */
    private static function validateLoweringOpParams(string $opName, array $op, string $ctx): void
    {
        switch ($opName) {
            case 'convert':
                if (!isset($op['format']) || !\is_string($op['format']) || $op['format'] === '') {
                    throw new \RuntimeException("[{$ctx}] convert requires a non-empty string 'format'");
                }
                break;
            case 'text_watermark':
                if (!isset($op['text']) || !\is_string($op['text']) || $op['text'] === '') {
                    throw new \RuntimeException("[{$ctx}] text_watermark requires a non-empty string 'text'");
                }
                break;
            case 'thumbnail':
                foreach (['width', 'height'] as $dim) {
                    if (\array_key_exists($dim, $op) && (!\is_int($op[$dim]) || $op[$dim] < 1)) {
                        throw new \RuntimeException("[{$ctx}] thumbnail '{$dim}' must be a positive integer");
                    }
                }
                if (!\array_key_exists('width', $op) && !\array_key_exists('height', $op)) {
                    throw new \RuntimeException("[{$ctx}] thumbnail requires at least one of width/height");
                }
                break;
            case 'compress':
                if (\array_key_exists('optimize', $op) && (!\is_string($op['optimize']) || $op['optimize'] === '')) {
                    throw new \RuntimeException("[{$ctx}] compress 'optimize' must be a non-empty string when present");
                }
                break;
        }
    }

    /**
     * Load and validate one fixture by absolute path. F4-A — exposed so
     * the (future) F4-B conformance meta-tests can load named fixtures
     * without scanning the whole directory.
     *
     * @param string $absolutePath
     */
    public static function loadByPath(string $absolutePath): Fixture
    {
        $raw = Yaml::parseFile($absolutePath);
        return self::validate($raw, $absolutePath);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function requireString(array $raw, string $key, string $ctx): string
    {
        if (!isset($raw[$key]) || !\is_string($raw[$key]) || $raw[$key] === '') {
            throw new \RuntimeException("[{$ctx}] {$key} must be a non-empty string");
        }
        return $raw[$key];
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function validateRequest(array $raw, string $ctx): void
    {
        $method = self::requireString($raw, 'method', $ctx);
        if (!\in_array($method, self::ALLOWED_REQUEST_METHODS, true)) {
            throw new \RuntimeException(
                "[{$ctx}] method \"{$method}\" is not one of " . \implode('|', self::ALLOWED_REQUEST_METHODS),
            );
        }
        self::requireString($raw, 'path', $ctx);
        if (isset($raw['headers'])) {
            if (!\is_array($raw['headers']) || \array_is_list($raw['headers'])) {
                throw new \RuntimeException("[{$ctx}] headers must be a mapping");
            }
            foreach (\array_keys($raw['headers']) as $headerName) {
                if (!\is_string($headerName)) {
                    throw new \RuntimeException("[{$ctx}] header keys must be strings");
                }
                if ($headerName !== \strtolower($headerName)) {
                    throw new \RuntimeException("[{$ctx}] headers must use lowercase keys, got \"{$headerName}\"");
                }
            }
        }
        if (isset($raw['body'])) {
            if (!\is_array($raw['body']) || \array_is_list($raw['body'])) {
                throw new \RuntimeException("[{$ctx}] body must be a mapping");
            }
            $type = isset($raw['body']['type']) ? (string) $raw['body']['type'] : '';
            if (!\in_array($type, self::ALLOWED_REQUEST_BODY_TYPES, true)) {
                throw new \RuntimeException(
                    "[{$ctx}] body.type must be one of " . \implode('|', self::ALLOWED_REQUEST_BODY_TYPES),
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function validateResponse(array $raw, string $ctx): void
    {
        if (!isset($raw['status']) || !\is_int($raw['status']) || $raw['status'] < 100 || $raw['status'] > 599) {
            throw new \RuntimeException("[{$ctx}] status must be a number 100-599");
        }
        if (isset($raw['body'])) {
            if (!\is_array($raw['body']) || \array_is_list($raw['body'])) {
                throw new \RuntimeException("[{$ctx}] body must be a mapping");
            }
            $type = isset($raw['body']['type']) ? (string) $raw['body']['type'] : '';
            if (!\in_array($type, self::ALLOWED_RESPONSE_BODY_TYPES, true)) {
                throw new \RuntimeException(
                    "[{$ctx}] body.type must be one of " . \implode('|', self::ALLOWED_RESPONSE_BODY_TYPES),
                );
            }
        }
    }
}
