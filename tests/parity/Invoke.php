<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Parity;

use Gisl\Generated\OpenApi\Model\AudioWatermarkDecodeRequest;
use Gisl\Generated\OpenApi\Model\ContactRequest;
use Gisl\Generated\OpenApi\Model\ExternalImportRequest;
use Gisl\Generated\OpenApi\Model\LoginUserRequest;
use Gisl\Generated\OpenApi\Model\MultipartInitiateRequestMetadataHint;
use Gisl\Sdk\CreditsUsageOptions;
use Gisl\Sdk\Ergonomic\OperationBuilder;
use Gisl\Sdk\Ergonomic\RunOptions;
use Gisl\Sdk\Ergonomic\SubmitOptions;
use Gisl\Sdk\Errors\NotYetImplementedDispatch;
use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\FilesRecipe;
use Gisl\Sdk\FileFirst\MergedRecipe;
use Gisl\Sdk\FileFirst\Recipe;
use Gisl\Sdk\FileFirst\WatermarkedRecipe;
use Gisl\Sdk\GetSchemaOptions;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use Gisl\Sdk\JobDefinitionPayload;
use Gisl\Sdk\OperationDef;
use Gisl\Sdk\UploadOptions;
use Gisl\Sdk\Webhook;
use Gisl\Sdk\WorkflowCreatePayload;
use GuzzleHttp\Psr7\HttpFactory;

/**
 * Maps a {@see Fixture} to the right {@see GislClient} (or {@see Webhook})
 * call.
 *
 * PHP reference implementation of the parity adapter contract
 * (F5 / MZWsS0qs). See `docs/sdks/parity-adapter-contract.md` for the
 * language-neutral interface every adapter implements.
 *
 * Mirrors `packages/typescript/tests/parity/invoke.ts`. The argument shape
 * for each method is fixed across runners: a fixture that authors
 * `args: [<email + password mapping>]` MUST resolve to the same call
 * (`login(LoginUserRequest)` here, `client.login({email, password})` in TS).
 *
 * Bytes args (`kind: bytes`) are materialised to a temporary file on disk
 * because PHP's {@see GislClient::uploadFile()} accepts a filesystem path,
 * not raw bytes. Temp files are tracked on the {@see InvokeResult} and
 * cleaned up by the caller after the test exits.
 */
final class Invoke
{
    /**
     * Ergonomic-facade verbs still parked on the P0 seam — Bljva8nj.
     *
     * Originally (P0) the full eight-verb list short-circuited here. PHP
     * P2 (7QXkzoIi) shipped real dispatch for `compress` / `thumbnail` /
     * `convert` only — the other five stay on the seam:
     *
     *   - `watermark`: v2 `OperationType` has no bare `watermark` value
     *     (the contract split it into `image_watermark` / `text_watermark`
     *     / planned `audio_watermark`). A real dispatch needs a preset-
     *     style mapping that picks the right sub-op for the input MIME +
     *     options. Codex caught this gap in P2 review.
     *   - `archive`: contract-modeled as MULTI-INPUT (`inputs[]`),
     *     incompatible with the single-input `OperationBuilder`. Lands
     *     alongside P4's `.bundle()` archive sugar.
     *   - `merge`: P3 (merge compose model).
     *   - `mapEach`: P4 (`.mapEach` fan-out).
     *   - `bundle`: P4 (`.bundle` archive sugar + chain cardinality).
     *
     * `gisl.create`, `.run`, `.submit` are deliberately NOT in this list —
     * the first is a factory ({@see \Gisl\Sdk\Gisl::create()}); the
     * latter two are chain terminals, not dispatch verbs.
     *
     * @var array<string, string>
     */
    private const ERGONOMIC_METHODS = [
        'watermark' => 'lands alongside preset-matrix (v2 has no bare watermark op)',
        'archive' => 'lands alongside wpHoJhuo (P4b — single-workflow .bundle archive sugar)',
        'bundle' => 'lands in wpHoJhuo (P4b — single-workflow .mapEach().bundle() per lowering.md:381-394)',
    ];

    /**
     * Ergonomic-facade verbs WIRED in PHP P2 (7QXkzoIi). The dispatch
     * shape: $args = [bytesValue, options?, terminal?] where `terminal`
     * is either `{run: {maxWait, useSSE?, pollIntervalMs?}}` or
     * `{submit: {webhook}}`. Default terminal when omitted is
     * `{submit: {webhook: 'https://example.com/webhook'}}` — picked so
     * a fixture exercising only the upload+create wire shape doesn't
     * also need to assert the full run-orchestration flow.
     *
     * @var list<string>
     */
    private const ERGONOMIC_DISPATCH_VERBS = [
        'compress',
        'thumbnail',
        'convert',
    ];

    /**
     * Multi-input ergonomic verbs WIRED in PHP P3 (dxIeLVbP). Dispatch
     * shape diverges from the single-input verbs above:
     *
     *   $args = [
     *     [<assetEntry>, <assetEntry>, ...],     // args[0] — declared assets
     *     <mergeOptions>|null,                   // args[1] — MergeOptions map (default {})
     *     [<seqEntry>, <seqEntry>, ...]|null,    // args[2] — sequence (null = declared order)
     *     <terminal>|null,                       // args[3] — {run|submit} (default submit-webhook)
     *   ]
     *
     * Each `<assetEntry>` is either:
     *   - a bytes value `{kind: bytes, ...}` — materialised to a temp
     *     file and wrapped as `Merge::asset($tempPath)`
     *   - `{kind: handle, fileId: <uuid>}` — wrapped as `Merge::handle($fileId)`
     *
     * Each `<seqEntry>` is `{asset: <int>, options?: <map>}` where `asset`
     * is the index into `args[0]`. Bare asset entry: omit `options`.
     * Clip entry: include `options`.
     *
     * @var list<string>
     */
    private const ERGONOMIC_MULTI_INPUT_VERBS = [
        'merge',
    ];

    /**
     * @param Fixture $fixture
     * @return InvokeResult
     */
    public static function run(Fixture $fixture, StubPsr18Client $stub): InvokeResult
    {
        if ($fixture->mode === Fixture::MODE_WEBHOOK) {
            return self::runWebhook($fixture);
        }

        $factory = new HttpFactory();
        $config = new GislClientConfig(
            baseUrl: 'https://api.test.example.com',
            apiKey: 'test-api-key',
            // Force deterministic part order for parity. The PHP SDK is
            // sequential in v0.x anyway but the config field is recorded.
            multipartConcurrency: 1,
        );
        // Construct the ergonomic subclass so ergonomic-verb dispatch
        // (PHP P2 / 7QXkzoIi) can reach `$client->compress(...)` etc.
        // GislErgonomicClient is-a GislClient — every low-level case in
        // {@see Invoke::dispatch()} continues to work unchanged.
        $client = new GislErgonomicClient(
            config: $config,
            httpClient: $stub,
            requestFactory: $factory,
            streamFactory: $factory,
        );

        $tempFiles = [];
        try {
            $result = self::dispatch($client, $fixture, $tempFiles);
            return new InvokeResult(
                returnValue: $result,
                sseEvents: null,
                thrown: null,
                tempFiles: $tempFiles,
            );
        } catch (\Throwable $thrown) {
            return new InvokeResult(
                returnValue: null,
                sseEvents: null,
                thrown: $thrown,
                tempFiles: $tempFiles,
            );
        }
    }

    /**
     * FF2a (`MfV0PDok`) — mode=lowering dispatch. Builds a file-first
     * {@see Recipe} from the fixture's `lowering` block, applies each op in
     * order, and lowers against `resolvedFileId` to the wire payload. Pure +
     * network-free — no client, no HTTP stub. The caller deep-compares the
     * returned array to `expected_payload`.
     *
     * @return array<string, mixed>
     */
    public static function lower(Fixture $fixture): array
    {
        $spec = $fixture->lowering;
        if ($spec === null) {
            throw new \RuntimeException("[{$fixture->name}] mode=lowering requires a lowering block");
        }
        /** @var array<string, mixed> $file */
        $file = $spec['file'];
        $key = isset($file['key']) && \is_string($file['key']) ? $file['key'] : null;
        $input = ($file['kind'] ?? null) === 'upload_id'
            ? FileInput::uploadId((string) $file['uploadId'])
            : FileInput::path((string) $file['path']);

        $recipe = new Recipe($input, $key);
        /** @var list<array<string, mixed>> $operations */
        $operations = $spec['operations'];
        foreach ($operations as $op) {
            $recipe = self::applyLoweringOp($recipe, $op);
        }

        // FF4a (Z7zTr789) — a `watermark` block lowers `file(base)->watermark(
        // overlay, opts)` -> WatermarkedRecipe. The base recipe above carries the
        // base's preceding steps; build the overlay file-node + its own steps,
        // then lower against [baseId, overlayId]. Mirrors TS `lowerFixture`.
        if (isset($spec['watermark']) && \is_array($spec['watermark'])) {
            $wm = $spec['watermark'];
            /** @var array<string, mixed> $ov */
            $ov = $wm['overlay'];
            /** @var array<string, mixed> $ovFile */
            $ovFile = $ov['file'];
            $ovKey = isset($ovFile['key']) && \is_string($ovFile['key']) ? $ovFile['key'] : null;
            $overlayInput = ($ovFile['kind'] ?? null) === 'upload_id'
                ? FileInput::uploadId((string) $ovFile['uploadId'])
                : FileInput::path((string) $ovFile['path']);
            $overlay = new Recipe($overlayInput, $ovKey);
            /** @var list<array<string, mixed>> $ovOps */
            $ovOps = $ov['operations'] ?? [];
            foreach ($ovOps as $op) {
                $overlay = self::applyLoweringOp($overlay, $op);
            }
            /** @var array<string, mixed> $options */
            $options = $wm['options'] ?? [];
            $wr = $recipe->watermark($overlay, $options);
            /** @var list<array<string, mixed>> $post */
            $post = $wm['post'] ?? [];
            foreach ($post as $op) {
                $wr = self::applyWatermarkedOp($wr, $op);
            }

            return $wr->toWorkflowPayload([
                (string) $spec['resolvedFileId'],
                (string) $ov['resolvedFileId'],
            ])->toWire();
        }

        return $recipe->toWorkflowPayload((string) $spec['resolvedFileId'])->toWire();
    }

    /**
     * FF2b (`tywwynmN`) — mode=run dispatch. Builds a file-first {@see Recipe}
     * from the fixture's `run` block via the ergonomic client (so `run()` has a
     * bound client), applies each op in order, and drives `->run()` against the
     * stubbed HTTP client. Returns the hydrated RunResult projected via
     * {@see \Gisl\Sdk\FileFirst\RunResult::toArray()} so the caller can
     * deep-compare to `expected_run_result`. Mirrors TS `runRecipeFixture`.
     *
     * HARNESS NOTE: depends on {@see StubPsr18Client} serving the canned
     * upload/create/terminal/downloads responses in call order — see the
     * SCHEMA.md "mode: run" HARNESS NOTE.
     *
     * @return array<string, mixed>
     */
    public static function runRecipe(Fixture $fixture, StubPsr18Client $stub): array
    {
        $spec = $fixture->run;
        if ($spec === null) {
            throw new \RuntimeException("[{$fixture->name}] mode=run requires a run block");
        }

        $factory = new HttpFactory();
        $config = new GislClientConfig(
            baseUrl: 'https://api.test.example.com',
            apiKey: 'test-api-key',
            multipartConcurrency: 1,
        );
        // Build through the ergonomic client so the Recipe carries a client
        // (run() requires one). The fixture's file path is canned — the stub
        // serves the upload response without reading real bytes.
        $client = new GislErgonomicClient(
            config: $config,
            httpClient: $stub,
            requestFactory: $factory,
            streamFactory: $factory,
        );

        /** @var array<string, mixed> $file */
        $file = $spec['file'];
        $key = isset($file['key']) && \is_string($file['key']) ? $file['key'] : null;
        $input = ($file['kind'] ?? null) === 'upload_id'
            ? FileInput::uploadId((string) $file['uploadId'])
            : FileInput::path((string) $file['path']);

        $recipe = $client->file($input, $key);
        /** @var list<array<string, mixed>> $operations */
        $operations = $spec['operations'];
        foreach ($operations as $op) {
            $recipe = self::applyLoweringOp($recipe, $op);
        }

        $maxWait = $spec['maxWait'] ?? null;
        $maxWaitArg = (\is_string($maxWait) || \is_int($maxWait)) ? $maxWait : null;
        $pollIntervalMs = isset($spec['pollIntervalMs']) ? (int) $spec['pollIntervalMs'] : null;

        $result = $recipe->run(maxWait: $maxWaitArg, pollIntervalMs: $pollIntervalMs);

        return $result->toArray();
    }

    /**
     * FF5b (`u8M49LU2`) — submit dispatch. Builds a file-first {@see Recipe}
     * from the fixture's `submit` block via the ergonomic client (so `submit()`
     * has a bound client), applies each op in order, and drives `->submit()`
     * against the stubbed HTTP client. Returns the resulting {@see Handle}
     * projected via {@see \Gisl\Sdk\Ergonomic\Handle::toArray()} so the caller
     * can deep-compare to `expected_return`; request parity (the create
     * `callback_url`) is asserted by the standard request comparator. Mirrors TS
     * `submitRecipeFixture`.
     *
     * @return array<string, mixed>
     */
    public static function submitRecipe(Fixture $fixture, StubPsr18Client $stub): array
    {
        $spec = $fixture->submit;
        if ($spec === null) {
            throw new \RuntimeException("[{$fixture->name}] a submit fixture requires a submit block");
        }

        $factory = new HttpFactory();
        $config = new GislClientConfig(
            baseUrl: 'https://api.test.example.com',
            apiKey: 'test-api-key',
            multipartConcurrency: 1,
        );
        $client = new GislErgonomicClient(
            config: $config,
            httpClient: $stub,
            requestFactory: $factory,
            streamFactory: $factory,
        );

        /** @var array<string, mixed> $file */
        $file = $spec['file'];
        $key = isset($file['key']) && \is_string($file['key']) ? $file['key'] : null;
        $input = ($file['kind'] ?? null) === 'upload_id'
            ? FileInput::uploadId((string) $file['uploadId'])
            : FileInput::path((string) $file['path']);

        $recipe = $client->file($input, $key);
        /** @var list<array<string, mixed>> $operations */
        $operations = $spec['operations'];
        foreach ($operations as $op) {
            $recipe = self::applyLoweringOp($recipe, $op);
        }

        $webhook = isset($spec['webhook']) && \is_string($spec['webhook']) ? $spec['webhook'] : null;
        $handle = $recipe->submit($webhook);

        return $handle->toArray();
    }

    /**
     * FF3a (`u0hBt6fl`) — mode=files dispatch, LOWERING variant. Builds a
     * {@see FilesRecipe} from the fixture's `files` block (ORDERED inputs),
     * applies each shared op in order, and lowers against `resolvedFileIds` to
     * the multi-job wire payload. Pure + network-free. The caller deep-compares
     * the returned array to `expected_payload`. Mirrors TS `lowerFilesFixture`.
     *
     * @return array<string, mixed>
     */
    public static function lowerFiles(Fixture $fixture): array
    {
        $spec = $fixture->files;
        if ($spec === null) {
            throw new \RuntimeException("[{$fixture->name}] mode=files requires a files block");
        }
        if (!\array_key_exists('resolvedFileIds', $spec)) {
            throw new \RuntimeException(
                "[{$fixture->name}] mode=files lowering variant requires files.resolvedFileIds",
            );
        }

        /** @var list<string> $resolvedFileIds */
        $resolvedFileIds = [];
        /** @var list<mixed> $rawIds */
        $rawIds = $spec['resolvedFileIds'];
        foreach ($rawIds as $id) {
            $resolvedFileIds[] = (string) $id;
        }

        /** @var list<array<string, mixed>> $operations */
        $operations = $spec['operations'];

        // aQMm5khm — merge-combine lowering. `files([...])->merge($opts)`
        // collapses the N inputs into ONE MergedRecipe; the fixture's
        // `operations` then lower as POST-COMBINE ops on the merged output. The
        // merge-level options reuse the operation-first MergeOptions shape (same
        // camelCase keys), so a fluent merge lowers identically to
        // `client->merge($assets, $options)`. Mirrors the TS runner.
        //
        // toWorkflowPayload() is called directly, BYPASSING the run/submit-time
        // merge preflight (2-10 inputs, image-needs-output_type) — BY DESIGN and
        // consistent with the FF2a single-file + FF3a fan-out lowering paths.
        // Lowering mode is a pure, network-free WIRE-SHAPE pin; merge preflight
        // is covered by the unit suite + (future) local_validation_error fixtures.
        if (\array_key_exists('merge', $spec)) {
            /** @var array<string, mixed> $mergeBlock */
            $mergeBlock = \is_array($spec['merge']) ? $spec['merge'] : [];
            $mergeOptions = self::buildMergeOptions($mergeBlock['options'] ?? null, $fixture->name);
            $merged = (new FilesRecipe(self::buildFileInputs($spec)))->merge($mergeOptions);
            foreach ($operations as $op) {
                $merged = self::applyMergedOp($merged, $op);
            }
            return $merged->toWorkflowPayload($resolvedFileIds)->toWire();
        }

        $recipe = new FilesRecipe(self::buildFileInputs($spec));
        foreach ($operations as $op) {
            $recipe = self::applyFilesOp($recipe, $op);
        }

        return $recipe->toWorkflowPayload($resolvedFileIds)->toWire();
    }

    /**
     * FF3a (`u0hBt6fl`) — mode=files dispatch, RUN variant. Builds a
     * {@see FilesRecipe} bound to an ergonomic client from the fixture's `files`
     * block, applies each shared op in order, and drives `->run()` against the
     * stubbed HTTP client. Returns the hydrated partitioned RunResult projected
     * via {@see \Gisl\Sdk\FileFirst\RunResult::toArray()} so the caller can
     * deep-compare to `expected_run_result`. Mirrors TS `runFilesFixture`.
     *
     * @return array<string, mixed>
     */
    public static function runFiles(Fixture $fixture, StubPsr18Client $stub): array
    {
        $spec = $fixture->files;
        if ($spec === null) {
            throw new \RuntimeException("[{$fixture->name}] mode=files requires a files block");
        }

        $factory = new HttpFactory();
        $config = new GislClientConfig(
            baseUrl: 'https://api.test.example.com',
            apiKey: 'test-api-key',
            multipartConcurrency: 1,
        );
        $client = new GislErgonomicClient(
            config: $config,
            httpClient: $stub,
            requestFactory: $factory,
            streamFactory: $factory,
        );

        $recipe = $client->files(self::buildFileInputs($spec));
        /** @var list<array<string, mixed>> $operations */
        $operations = $spec['operations'];
        foreach ($operations as $op) {
            $recipe = self::applyFilesOp($recipe, $op);
        }

        $maxWait = $spec['maxWait'] ?? null;
        $maxWaitArg = (\is_string($maxWait) || \is_int($maxWait)) ? $maxWait : null;
        $pollIntervalMs = isset($spec['pollIntervalMs']) ? (int) $spec['pollIntervalMs'] : null;

        $result = $recipe->run(maxWait: $maxWaitArg, pollIntervalMs: $pollIntervalMs);

        return $result->toArray();
    }

    /**
     * uUnCtVAr (FF3a-submit) — mode=files submit variant. Build a
     * {@see FilesRecipe} bound to an ergonomic client from the fixture's `files`
     * block, apply each shared op, and drive `->submit($webhook)` against the
     * canned create response. Returns the resulting Handle projected via
     * {@see \Gisl\Sdk\Ergonomic\Handle::toArray()} so the caller can deep-compare
     * to `expected_return`; the create `callback_url` request is asserted by
     * {@see Comparator::compareRequests}. Mirrors the TS `submitFilesFixture`.
     *
     * @return array<string, mixed>
     */
    public static function submitFiles(Fixture $fixture, StubPsr18Client $stub): array
    {
        $spec = $fixture->files;
        if ($spec === null) {
            throw new \RuntimeException("[{$fixture->name}] mode=files requires a files block");
        }

        $factory = new HttpFactory();
        $config = new GislClientConfig(
            baseUrl: 'https://api.test.example.com',
            apiKey: 'test-api-key',
            multipartConcurrency: 1,
        );
        $client = new GislErgonomicClient(
            config: $config,
            httpClient: $stub,
            requestFactory: $factory,
            streamFactory: $factory,
        );

        $recipe = $client->files(self::buildFileInputs($spec));
        /** @var list<array<string, mixed>> $operations */
        $operations = $spec['operations'];
        foreach ($operations as $op) {
            $recipe = self::applyFilesOp($recipe, $op);
        }

        $webhook = isset($spec['webhook']) && \is_string($spec['webhook']) ? $spec['webhook'] : null;
        $handle = $recipe->submit($webhook);

        return $handle->toArray();
    }

    /**
     * Build the ORDERED {@see FileInput} list from a `files` block's `files[]`.
     *
     * @param array<string, mixed> $spec
     * @return list<FileInput>
     */
    private static function buildFileInputs(array $spec): array
    {
        /** @var list<array<string, mixed>> $rawInputs */
        $rawInputs = $spec['files'];
        $inputs = [];
        foreach ($rawInputs as $file) {
            $inputs[] = ($file['kind'] ?? null) === 'upload_id'
                ? FileInput::uploadId((string) $file['uploadId'])
                : FileInput::path((string) $file['path']);
        }
        return $inputs;
    }

    /**
     * Project a parity thumbnail op-spec into the options-array shape
     * (`thumbnail(array{width: int, height: int})`). Dhje3Faq: thumbnail now
     * REQUIRES both dimensions, so a fixture supplying only one is a fixture
     * authoring bug — throw rather than silently omit a dimension (which would
     * trip the both-required gate inside the verb with a less obvious message).
     *
     * @param array<string, mixed> $op
     * @return array{width: int, height: int}
     */
    private static function thumbnailOptions(array $op): array
    {
        if (!isset($op['width']) || !isset($op['height'])) {
            throw new \RuntimeException('[parity] thumbnail fixture must supply both width and height');
        }
        return [
            'width' => (int) $op['width'],
            'height' => (int) $op['height'],
        ];
    }

    /**
     * @param array<string, mixed> $op
     */
    private static function applyFilesOp(FilesRecipe $recipe, array $op): FilesRecipe
    {
        return match ($op['op']) {
            'compress' => $recipe->compress(
                isset($op['optimize']) && \is_string($op['optimize']) ? $op['optimize'] : null,
            ),
            'convert' => $recipe->convert((string) $op['format']),
            'thumbnail' => $recipe->thumbnail(self::thumbnailOptions($op)),
            'text_watermark' => $recipe->textWatermark((string) $op['text']),
            default => throw new \RuntimeException("Unknown files op '" . \var_export($op['op'] ?? null, true) . "'"),
        };
    }

    /**
     * aQMm5khm — apply a post-combine op to a {@see MergedRecipe}. The merged
     * output exposes the single-file chain ops MINUS `text_watermark` (no merged
     * equivalent); the loader rejects `text_watermark` as a post-merge op, so
     * the default branch is defence-in-depth. Mirrors TS `applyMergedOp`.
     *
     * @param array<string, mixed> $op
     */
    private static function applyMergedOp(MergedRecipe $recipe, array $op): MergedRecipe
    {
        return match ($op['op']) {
            'compress' => $recipe->compress(
                isset($op['optimize']) && \is_string($op['optimize']) ? $op['optimize'] : null,
            ),
            'convert' => $recipe->convert((string) $op['format']),
            'thumbnail' => $recipe->thumbnail(self::thumbnailOptions($op)),
            default => throw new \RuntimeException(
                "Unsupported post-combine op '" . \var_export($op['op'] ?? null, true) . "'",
            ),
        };
    }

    /**
     * FF4a (Z7zTr789) — apply a post-watermark op to a {@see WatermarkedRecipe}.
     * compress/convert/thumbnail ONLY (no text_watermark). Mirrors TS
     * `applyWatermarkedOp`.
     *
     * @param array<string, mixed> $op
     */
    private static function applyWatermarkedOp(WatermarkedRecipe $recipe, array $op): WatermarkedRecipe
    {
        return match ($op['op']) {
            'compress' => $recipe->compress(
                isset($op['optimize']) && \is_string($op['optimize']) ? $op['optimize'] : null,
            ),
            'convert' => $recipe->convert((string) $op['format']),
            'thumbnail' => $recipe->thumbnail(self::thumbnailOptions($op)),
            default => throw new \RuntimeException(
                "Unsupported post-watermark op '" . \var_export($op['op'] ?? null, true) . "'",
            ),
        };
    }

    /**
     * @param array<string, mixed> $op
     */
    private static function applyLoweringOp(Recipe $recipe, array $op): Recipe
    {
        return match ($op['op']) {
            'compress' => $recipe->compress(
                isset($op['optimize']) && \is_string($op['optimize']) ? $op['optimize'] : null,
            ),
            'convert' => $recipe->convert((string) $op['format']),
            'thumbnail' => $recipe->thumbnail(self::thumbnailOptions($op)),
            'text_watermark' => $recipe->textWatermark((string) $op['text']),
            'output' => $recipe->output(
                isset($op['format']) && \is_string($op['format']) ? $op['format'] : null,
                isset($op['options']) && \is_array($op['options']) ? $op['options'] : [],
            ),
            'resize' => $recipe->resize(
                (int) $op['width'],
                isset($op['height']) && \is_int($op['height']) ? $op['height'] : null,
                isset($op['fit']) && \is_string($op['fit']) ? $op['fit'] : null,
            ),
            default => throw new \RuntimeException("Unknown lowering op '" . \var_export($op['op'] ?? null, true) . "'"),
        };
    }

    /**
     * @param list<string> $tempFiles Tracked temp files for cleanup.
     */
    private static function dispatch(
        GislClient $client,
        Fixture $fixture,
        array &$tempFiles,
    ): mixed {
        $args = $fixture->args;
        $method = $fixture->sdkMethod;

        // Ergonomic-dispatch — PHP P2 (7QXkzoIi) wires real dispatch for
        // compress/thumbnail/convert; PHP P3 (dxIeLVbP) adds merge via
        // ERGONOMIC_MULTI_INPUT_VERBS. The remaining verbs in
        // ERGONOMIC_METHODS (watermark/archive/mapEach/bundle) stay on
        // the P0 seam (Bljva8nj). Low-level method dispatch is untouched.
        if (\in_array($method, self::ERGONOMIC_DISPATCH_VERBS, true)) {
            if (!$client instanceof GislErgonomicClient) {
                // Should be unreachable — Invoke::run() always constructs
                // a GislErgonomicClient. Defensive guard so a future
                // refactor that swaps in a bare GislClient surfaces here
                // rather than at the magic-call site.
                throw new \LogicException(
                    "Ergonomic verb \"{$method}\" requires a GislErgonomicClient; got " . \get_class($client),
                );
            }
            return self::dispatchErgonomic($client, $method, $args, $fixture, $tempFiles);
        }

        if (\in_array($method, self::ERGONOMIC_MULTI_INPUT_VERBS, true)) {
            if (!$client instanceof GislErgonomicClient) {
                throw new \LogicException(
                    "Ergonomic multi-input verb \"{$method}\" requires a GislErgonomicClient; got " . \get_class($client),
                );
            }
            return self::dispatchErgonomicMultiInput($client, $method, $args, $fixture, $tempFiles);
        }

        // Ergonomic-dispatch seam (P0 / Bljva8nj). Short-circuits ahead of
        // the low-level switch with a structured LocalError per F5 §4 for
        // verbs still parked (watermark/archive/mapEach/bundle).
        if (\array_key_exists($method, self::ERGONOMIC_METHODS)) {
            throw new NotYetImplementedDispatch(
                method: $method,
                hint: self::ERGONOMIC_METHODS[$method],
                metadata: ['fixture' => $fixture->name],
            );
        }

        switch ($method) {
            case 'uploadFile':
                $first = $args[0] ?? null;
                if (!\is_array($first) || ($first['kind'] ?? null) !== 'bytes') {
                    throw new \RuntimeException("uploadFile fixture {$fixture->name}: first arg must be a bytes value");
                }
                /** @var array<string, mixed> $first */
                $bytes = BytesDecoder::decode($first, $fixture->absolutePath);
                $filename = isset($first['filename']) ? (string) $first['filename'] : 'fixture.bin';
                $tempPath = self::writeTemp($bytes, $filename);
                $tempFiles[] = $tempPath;

                // RWWBYklu: the upload content-type rides on the BYTES arg
                // (`$first['content-type']`), NOT args[1]. Forward it via
                // UploadOptions even when there is no 2nd arg so the multipart
                // `file` part carries the fixture's declared type (`text/plain`
                // for upload_small; `application/octet-stream` for
                // upload_boundary_single). When the bytes arg omits a
                // content-type the SDK falls back to application/octet-stream.
                $contentType = isset($first['content-type']) && \is_string($first['content-type'])
                    ? $first['content-type']
                    : null;

                $hint = null;
                $resumeUploadId = null;
                if (isset($args[1]) && \is_array($args[1])) {
                    /** @var array<string, mixed> $opts */
                    $opts = $args[1];
                    if (isset($opts['metadataHint']) && \is_array($opts['metadataHint'])) {
                        /** @var array<string, mixed> $hintMap */
                        $hintMap = $opts['metadataHint'];
                        $hint = new MultipartInitiateRequestMetadataHint([
                            'duration_seconds' => $hintMap['durationSeconds'] ?? null,
                            'width' => $hintMap['width'] ?? null,
                            'height' => $hintMap['height'] ?? null,
                        ]);
                    }
                    // SDK-3 (Wb6ebOMM): resumeUploadId on UploadOptions
                    // wires the parity fixture to the resume branch.
                    $resumeUploadId = isset($opts['resumeUploadId']) && \is_string($opts['resumeUploadId'])
                        ? $opts['resumeUploadId']
                        : null;
                }
                $options = new UploadOptions(
                    metadataHint: $hint,
                    resumeUploadId: $resumeUploadId,
                    contentType: $contentType,
                );
                return $client->uploadFile($tempPath, $options);

            case 'createWorkflow':
                $first = $args[0] ?? null;
                if (!\is_array($first)) {
                    throw new \RuntimeException("createWorkflow fixture {$fixture->name}: first arg must be a mapping");
                }
                /** @var array<string, mixed> $first */
                return $client->createWorkflow(self::buildWorkflowPayload($first));

            case 'getWorkflowStatus':
                return $client->getWorkflowStatus(self::stringArg($args, 0, $fixture->name));

            case 'getWorkflowDownloads':
                return $client->getWorkflowDownloads(self::stringArg($args, 0, $fixture->name));

            case 'cancelWorkflow':
                return $client->cancelWorkflow(self::stringArg($args, 0, $fixture->name));

            case 'resumeWorkflow':
                return $client->resumeWorkflow(self::stringArg($args, 0, $fixture->name));

            case 'retryOperation':
                return $client->retryOperation(self::stringArg($args, 0, $fixture->name));

            case 'getMetadata':
                return $client->getMetadata(self::stringArg($args, 0, $fixture->name));

            case 'streamEvents':
                // streamEvents returns Generator<GislSseEvent>. The runner
                // collects them into a list to compare against the
                // fixture's expected event sequence.
                $generator = $client->streamEvents(self::stringArg($args, 0, $fixture->name));
                /** @var list<array<string, mixed>> $events */
                $events = [];
                foreach ($generator as $event) {
                    $events[] = ['event' => $event->event, 'data' => $event->data];
                }
                return $events;

            case 'login':
                $first = $args[0] ?? null;
                if (!\is_array($first)) {
                    throw new \RuntimeException("login fixture {$fixture->name}: first arg must be a mapping");
                }
                /** @var array<string, mixed> $first */
                return $client->login(new LoginUserRequest([
                    'email' => $first['email'] ?? null,
                    'password' => $first['password'] ?? null,
                ]));

            case 'logout':
                $client->logout();
                return null;

            case 'submitContact':
                $first = $args[0] ?? null;
                if (!\is_array($first)) {
                    throw new \RuntimeException("submitContact fixture {$fixture->name}: first arg must be a mapping");
                }
                /** @var array<string, mixed> $first */
                $client->submitContact(new ContactRequest([
                    'name' => $first['name'] ?? null,
                    'email' => $first['email'] ?? null,
                    'subject' => $first['subject'] ?? null,
                    'message' => $first['message'] ?? null,
                    'website' => $first['website'] ?? null,
                ]));
                return null;

            case 'getCreditsBalance':
                return $client->getCreditsBalance();

            case 'getAccountLimits':
                return $client->getAccountLimits();

            case 'getCreditsUsage':
                if (!isset($args[0])) {
                    return $client->getCreditsUsage(null);
                }
                $first = $args[0];
                if (!\is_array($first)) {
                    throw new \RuntimeException(
                        "getCreditsUsage fixture {$fixture->name}: first arg must be a mapping or omitted",
                    );
                }
                /** @var array<string, mixed> $first */
                $limit = isset($first['limit']) ? (int) $first['limit'] : null;
                $offset = isset($first['offset']) ? (int) $first['offset'] : null;
                return $client->getCreditsUsage(new CreditsUsageOptions(limit: $limit, offset: $offset));

            case 'getSchema':
                if (!isset($args[0])) {
                    return $client->getSchema(null);
                }
                $first = $args[0];
                if (!\is_array($first)) {
                    throw new \RuntimeException(
                        "getSchema fixture {$fixture->name}: first arg must be a mapping or omitted",
                    );
                }
                /** @var array<string, mixed> $first */
                return $client->getSchema(new GetSchemaOptions(
                    mimeType: isset($first['mimeType']) ? (string) $first['mimeType'] : null,
                    operation: isset($first['operation']) ? (string) $first['operation'] : null,
                    ifNoneMatch: isset($first['ifNoneMatch']) ? (string) $first['ifNoneMatch'] : null,
                    ifModifiedSince: isset($first['ifModifiedSince']) ? (string) $first['ifModifiedSince'] : null,
                ));

            case 'probeUpload':
                return $client->probeUpload(self::stringArg($args, 0, $fixture->name));

            case 'preflightClips':
                $first = $args[0] ?? null;
                if (!\is_array($first) || !\array_is_list($first)) {
                    throw new \RuntimeException(
                        "preflightClips fixture {$fixture->name}: first arg must be a list",
                    );
                }
                $ids = [];
                foreach ($first as $id) {
                    $ids[] = (string) $id;
                }
                return $client->preflightClips($ids);

            case 'decodeAudioWatermark':
                $first = $args[0] ?? null;
                if (!\is_array($first)) {
                    throw new \RuntimeException(
                        "decodeAudioWatermark fixture {$fixture->name}: first arg must be a mapping",
                    );
                }
                /** @var array<string, mixed> $first */
                $awData = ['file_id' => $first['fileId'] ?? null];
                // Only include method_hint when the fixture explicitly sets
                // it. Post-`09eNib6R` Issue 3 the generated
                // `AudioWatermarkDecodeRequest` constructor defaults
                // `method_hint` to `null` (was `'auto'`), so an omitted hint is
                // simply dropped from the wire body — no forced default emit.
                // Mirrors the TS reference where the typed argument leaves
                // `methodHint` undefined when the caller omits it.
                if (\array_key_exists('methodHint', $first)) {
                    $awData['method_hint'] = $first['methodHint'];
                }
                return $client->decodeAudioWatermark(new AudioWatermarkDecodeRequest($awData));

            case 'createExternalImport':
                $first = $args[0] ?? null;
                if (!\is_array($first)) {
                    throw new \RuntimeException(
                        "createExternalImport fixture {$fixture->name}: first arg must be a mapping",
                    );
                }
                /** @var array<string, mixed> $first */
                return $client->createExternalImport(new ExternalImportRequest([
                    'url' => $first['url'] ?? null,
                    'provider_hint' => $first['providerHint'] ?? null,
                    'password' => $first['password'] ?? null,
                ]));

            case 'waitForWorkflow':
                return $client->waitForWorkflow(self::stringArg($args, 0, $fixture->name));

            // SDK-3 (Wb6ebOMM) resume-support endpoints.
            case 'getUploadStatus':
                return $client->getUploadStatus(self::stringArg($args, 0, $fixture->name));

            case 'presignParts':
                $uploadId = self::stringArg($args, 0, $fixture->name);
                $partsRaw = $args[1] ?? null;
                $totalParts = $args[2] ?? null;
                if (!\is_array($partsRaw) || !\is_int($totalParts)) {
                    throw new \RuntimeException(
                        "presignParts fixture {$fixture->name}: args must be (uploadId:string, partNumbers:list<int>, totalParts:int)",
                    );
                }
                /** @var list<int> $parts */
                $parts = [];
                foreach ($partsRaw as $n) {
                    if (!\is_int($n)) {
                        throw new \RuntimeException(
                            "presignParts fixture {$fixture->name}: partNumbers entries must be ints",
                        );
                    }
                    $parts[] = $n;
                }
                return $client->presignParts($uploadId, $parts, $totalParts);

            case 'keepaliveUpload':
                return $client->keepaliveUpload(self::stringArg($args, 0, $fixture->name));
        }

        throw new \RuntimeException("Invoke: unsupported method \"{$method}\" for fixture {$fixture->name}");
    }

    /**
     * Dispatch an ergonomic verb (PHP P2 / 7QXkzoIi).
     *
     * Fixture-arg shape: `[bytesValue, optionsMap?, terminalMap?]`.
     *  - `bytesValue`: standard `{kind: 'bytes', source, value, filename?, content-type?}`.
     *  - `optionsMap`: the per-op options (e.g. `{quality: 75, format: 'webp'}`); defaults to `[]`.
     *  - `terminalMap`: ONE of `{submit: {webhook}}` or `{run: {maxWait, useSSE?, pollIntervalMs?}}`.
     *                   Defaults to `{submit: {webhook: 'https://example.com/webhook'}}` so a
     *                   fixture exercising only the upload+create wire shape doesn't also
     *                   need to drive the full run orchestration.
     *
     * @param list<mixed>  $args
     * @param list<string> $tempFiles
     */
    private static function dispatchErgonomic(
        GislErgonomicClient $client,
        string $method,
        array $args,
        Fixture $fixture,
        array &$tempFiles,
    ): mixed {
        $first = $args[0] ?? null;
        if (!\is_array($first) || ($first['kind'] ?? null) !== 'bytes') {
            throw new \RuntimeException("ergonomic fixture {$fixture->name}: first arg must be a bytes value");
        }
        /** @var array<string, mixed> $first */
        $bytes = BytesDecoder::decode($first, $fixture->absolutePath);
        $filename = isset($first['filename']) ? (string) $first['filename'] : 'fixture.bin';
        $tempPath = self::writeTemp($bytes, $filename);
        $tempFiles[] = $tempPath;

        /** @var array<string, mixed> $opOptions */
        $opOptions = [];
        if (isset($args[1])) {
            if (!\is_array($args[1])) {
                throw new \RuntimeException("ergonomic fixture {$fixture->name}: args[1] (options) must be a mapping or omitted");
            }
            /** @var array<string, mixed> $opOptions */
            $opOptions = $args[1];
        }

        /** @var array<string, mixed> $terminal */
        $terminal = ['submit' => ['webhook' => 'https://example.com/webhook']];
        if (isset($args[2])) {
            if (!\is_array($args[2])) {
                throw new \RuntimeException("ergonomic fixture {$fixture->name}: args[2] (terminal) must be a mapping or omitted");
            }
            /** @var array<string, mixed> $terminal */
            $terminal = $args[2];
        }

        $builder = self::buildErgonomicBuilder($client, $method, $tempPath, $opOptions);
        return self::invokeErgonomicTerminal($builder, $terminal, $fixture->name);
    }

    /**
     * @param array<string, mixed> $opOptions
     */
    private static function buildErgonomicBuilder(
        GislErgonomicClient $client,
        string $method,
        string $input,
        array $opOptions,
    ): OperationBuilder {
        return match ($method) {
            'compress' => $client->compress($input, $opOptions),
            'thumbnail' => $client->thumbnail($input, $opOptions),
            'convert' => $client->convert($input, $opOptions),
            default => throw new \LogicException("Unreachable: unsupported ergonomic verb \"{$method}\""),
        };
    }

    /**
     * @param array<string, mixed> $terminal
     */
    private static function invokeErgonomicTerminal(
        OperationBuilder $builder,
        array $terminal,
        string $fixtureName,
    ): mixed {
        if (isset($terminal['submit'])) {
            if (!\is_array($terminal['submit'])) {
                throw new \RuntimeException("ergonomic fixture {$fixtureName}: terminal.submit must be a mapping");
            }
            /** @var array<string, mixed> $submitMap */
            $submitMap = $terminal['submit'];
            $webhook = (string) ($submitMap['webhook'] ?? '');
            if ($webhook === '') {
                throw new \RuntimeException("ergonomic fixture {$fixtureName}: terminal.submit.webhook must be a non-empty string");
            }
            return $builder->submit(new SubmitOptions(webhook: $webhook));
        }
        if (isset($terminal['run'])) {
            if (!\is_array($terminal['run'])) {
                throw new \RuntimeException("ergonomic fixture {$fixtureName}: terminal.run must be a mapping");
            }
            /** @var array<string, mixed> $runMap */
            $runMap = $terminal['run'];
            $maxWait = $runMap['maxWait'] ?? null;
            if (!\is_string($maxWait) && !\is_int($maxWait)) {
                throw new \RuntimeException("ergonomic fixture {$fixtureName}: terminal.run.maxWait must be a string or int");
            }
            $useSSE = isset($runMap['useSSE']) ? (bool) $runMap['useSSE'] : true;
            $pollIntervalMs = isset($runMap['pollIntervalMs']) ? (int) $runMap['pollIntervalMs'] : null;
            return $builder->run(new RunOptions(
                maxWait: $maxWait,
                useSSE: $useSSE,
                pollIntervalMs: $pollIntervalMs,
            ));
        }
        throw new \RuntimeException("ergonomic fixture {$fixtureName}: terminal must declare exactly one of 'run' or 'submit'");
    }

    /**
     * Multi-input ergonomic dispatch — currently `merge` only (PHP P3 /
     * dxIeLVbP). See {@see ERGONOMIC_MULTI_INPUT_VERBS} for the args
     * shape. Each bytes asset is materialised to its own temp file +
     * tracked in `$tempFiles` for caller-side cleanup.
     *
     * @param list<mixed>  $args
     * @param list<string> $tempFiles
     */
    private static function dispatchErgonomicMultiInput(
        GislErgonomicClient $client,
        string $method,
        array $args,
        Fixture $fixture,
        array &$tempFiles,
    ): mixed {
        $assetsRaw = $args[0] ?? null;
        if (!\is_array($assetsRaw) || !\array_is_list($assetsRaw) || \count($assetsRaw) === 0) {
            throw new \RuntimeException(
                "ergonomic multi-input fixture {$fixture->name}: args[0] must be a non-empty list of asset entries",
            );
        }

        /** @var list<\Gisl\Sdk\Ergonomic\Asset> $assets */
        $assets = [];
        foreach ($assetsRaw as $idx => $entry) {
            if (!\is_array($entry)) {
                throw new \RuntimeException(
                    "ergonomic multi-input fixture {$fixture->name}: args[0][{$idx}] must be a mapping",
                );
            }
            $kind = $entry['kind'] ?? null;
            if ($kind === 'bytes') {
                /** @var array<string, mixed> $entry */
                $bytes = BytesDecoder::decode($entry, $fixture->absolutePath);
                $filename = isset($entry['filename']) ? (string) $entry['filename'] : "fixture_{$idx}.bin";
                $tempPath = self::writeTemp($bytes, $filename);
                $tempFiles[] = $tempPath;
                $assets[] = \Gisl\Sdk\Ergonomic\Merge::asset($tempPath);
            } elseif ($kind === 'handle') {
                $fileId = isset($entry['fileId']) ? (string) $entry['fileId'] : '';
                if ($fileId === '') {
                    throw new \RuntimeException(
                        "ergonomic multi-input fixture {$fixture->name}: args[0][{$idx}] handle requires non-empty fileId",
                    );
                }
                $assets[] = \Gisl\Sdk\Ergonomic\Merge::handle($fileId);
            } else {
                throw new \RuntimeException(
                    "ergonomic multi-input fixture {$fixture->name}: args[0][{$idx}].kind must be 'bytes' or 'handle' (got " . var_export($kind, true) . ')',
                );
            }
        }

        $mergeOptions = self::buildMergeOptions($args[1] ?? null, $fixture->name);

        $builder = match ($method) {
            'merge' => $client->merge($assets, $mergeOptions),
            default => throw new \LogicException("Unreachable: unsupported multi-input verb \"{$method}\""),
        };

        if (\array_key_exists(2, $args) && $args[2] !== null) {
            if (!\is_array($args[2])) {
                throw new \RuntimeException(
                    "ergonomic multi-input fixture {$fixture->name}: args[2] (sequence) must be a list or null",
                );
            }
            /** @var list<mixed> $sequenceRaw */
            $sequenceRaw = $args[2];
            $entries = self::buildSequenceEntries($sequenceRaw, $assets, $fixture->name);
            $builder->sequence($entries);
        }

        /** @var array<string, mixed> $terminal */
        $terminal = ['submit' => ['webhook' => 'https://example.com/webhook']];
        if (\array_key_exists(3, $args) && $args[3] !== null) {
            if (!\is_array($args[3])) {
                throw new \RuntimeException(
                    "ergonomic multi-input fixture {$fixture->name}: args[3] (terminal) must be a mapping or null",
                );
            }
            /** @var array<string, mixed> $terminal */
            $terminal = $args[3];
        }

        return self::invokeMergeTerminal($builder, $terminal, $fixture->name);
    }

    private static function buildMergeOptions(mixed $raw, string $fixtureName): \Gisl\Sdk\Ergonomic\MergeOptions
    {
        if ($raw === null) {
            return new \Gisl\Sdk\Ergonomic\MergeOptions();
        }
        if (!\is_array($raw)) {
            throw new \RuntimeException(
                "ergonomic multi-input fixture {$fixtureName}: args[1] (options) must be a mapping or null",
            );
        }
        /** @var array<string, mixed> $raw */
        $mediaKind = isset($raw['mediaKind']) ? (string) $raw['mediaKind'] : null;
        if ($mediaKind !== null && !\in_array($mediaKind, ['video', 'audio', 'image'], true)) {
            throw new \RuntimeException(
                "ergonomic multi-input fixture {$fixtureName}: args[1].mediaKind must be 'video'|'audio'|'image' (got '{$mediaKind}')",
            );
        }
        $targetSize = $raw['targetSize'] ?? null;
        if ($targetSize !== null && !\is_int($targetSize) && !\is_string($targetSize)) {
            throw new \RuntimeException(
                "ergonomic multi-input fixture {$fixtureName}: args[1].targetSize must be int|string|null",
            );
        }
        return new \Gisl\Sdk\Ergonomic\MergeOptions(
            transition: isset($raw['transition']) ? (string) $raw['transition'] : null,
            crossfadeDuration: isset($raw['crossfadeDuration']) ? (float) $raw['crossfadeDuration'] : null,
            gapDuration: isset($raw['gapDuration']) ? (float) $raw['gapDuration'] : null,
            normalizeAudio: isset($raw['normalizeAudio']) ? (bool) $raw['normalizeAudio'] : null,
            reEncodeMode: isset($raw['reEncodeMode']) ? (string) $raw['reEncodeMode'] : null,
            codec: isset($raw['codec']) ? (string) $raw['codec'] : null,
            crf: isset($raw['crf']) ? (int) $raw['crf'] : null,
            preset: isset($raw['preset']) ? (string) $raw['preset'] : null,
            targetResolution: isset($raw['targetResolution']) ? (string) $raw['targetResolution'] : null,
            targetSize: $targetSize,
            transitionDuration: isset($raw['transitionDuration']) ? (float) $raw['transitionDuration'] : null,
            fps: isset($raw['fps']) ? (float) $raw['fps'] : null,
            durationPerImage: isset($raw['durationPerImage']) ? (float) $raw['durationPerImage'] : null,
            delay: isset($raw['delay']) ? (int) $raw['delay'] : null,
            loopCount: isset($raw['loopCount']) ? (int) $raw['loopCount'] : null,
            output: isset($raw['output']) ? (string) $raw['output'] : null,
            videoFormat: isset($raw['videoFormat']) ? (string) $raw['videoFormat'] : null,
            outputType: isset($raw['outputType']) ? (string) $raw['outputType'] : null,
            mediaKind: $mediaKind,
            allowUnusedAssets: isset($raw['allowUnusedAssets']) ? (bool) $raw['allowUnusedAssets'] : false,
        );
    }

    /**
     * @param list<mixed>                          $rawEntries
     * @param list<\Gisl\Sdk\Ergonomic\Asset>      $assets
     * @return list<\Gisl\Sdk\Ergonomic\Asset|\Gisl\Sdk\Ergonomic\ClipEntry>
     */
    private static function buildSequenceEntries(array $rawEntries, array $assets, string $fixtureName): array
    {
        $entries = [];
        foreach ($rawEntries as $idx => $entry) {
            if (!\is_array($entry)) {
                throw new \RuntimeException(
                    "ergonomic multi-input fixture {$fixtureName}: sequence[{$idx}] must be a mapping",
                );
            }
            if (!isset($entry['asset']) || !\is_int($entry['asset'])) {
                throw new \RuntimeException(
                    "ergonomic multi-input fixture {$fixtureName}: sequence[{$idx}].asset must be an int index into args[0]",
                );
            }
            $assetIdx = $entry['asset'];
            if (!isset($assets[$assetIdx])) {
                throw new \RuntimeException(
                    "ergonomic multi-input fixture {$fixtureName}: sequence[{$idx}].asset index {$assetIdx} is out of range (declared " . \count($assets) . ' assets)',
                );
            }
            $asset = $assets[$assetIdx];
            if (\array_key_exists('options', $entry) && $entry['options'] !== null) {
                if (!\is_array($entry['options'])) {
                    throw new \RuntimeException(
                        "ergonomic multi-input fixture {$fixtureName}: sequence[{$idx}].options must be a mapping or null",
                    );
                }
                /** @var array<string, mixed> $optsRaw */
                $optsRaw = $entry['options'];
                $entries[] = \Gisl\Sdk\Ergonomic\Merge::clip(
                    $asset,
                    new \Gisl\Sdk\Ergonomic\ClipOptions(
                        transition: isset($optsRaw['transition']) ? (string) $optsRaw['transition'] : null,
                        crossfadeDuration: isset($optsRaw['crossfadeDuration']) ? (float) $optsRaw['crossfadeDuration'] : null,
                        gapDuration: isset($optsRaw['gapDuration']) ? (float) $optsRaw['gapDuration'] : null,
                    ),
                );
            } else {
                $entries[] = $asset;
            }
        }
        return $entries;
    }

    /**
     * @param array<string, mixed> $terminal
     */
    private static function invokeMergeTerminal(
        \Gisl\Sdk\Ergonomic\MergeBuilder $builder,
        array $terminal,
        string $fixtureName,
    ): mixed {
        if (isset($terminal['submit'])) {
            if (!\is_array($terminal['submit'])) {
                throw new \RuntimeException("ergonomic multi-input fixture {$fixtureName}: terminal.submit must be a mapping");
            }
            /** @var array<string, mixed> $submitMap */
            $submitMap = $terminal['submit'];
            $webhook = (string) ($submitMap['webhook'] ?? '');
            if ($webhook === '') {
                throw new \RuntimeException("ergonomic multi-input fixture {$fixtureName}: terminal.submit.webhook must be a non-empty string");
            }
            return $builder->submit(new SubmitOptions(webhook: $webhook));
        }
        if (isset($terminal['run'])) {
            if (!\is_array($terminal['run'])) {
                throw new \RuntimeException("ergonomic multi-input fixture {$fixtureName}: terminal.run must be a mapping");
            }
            /** @var array<string, mixed> $runMap */
            $runMap = $terminal['run'];
            $maxWait = $runMap['maxWait'] ?? null;
            if (!\is_string($maxWait) && !\is_int($maxWait)) {
                throw new \RuntimeException("ergonomic multi-input fixture {$fixtureName}: terminal.run.maxWait must be a string or int");
            }
            $useSSE = isset($runMap['useSSE']) ? (bool) $runMap['useSSE'] : true;
            $pollIntervalMs = isset($runMap['pollIntervalMs']) ? (int) $runMap['pollIntervalMs'] : null;
            return $builder->run(new RunOptions(
                maxWait: $maxWait,
                useSSE: $useSSE,
                pollIntervalMs: $pollIntervalMs,
            ));
        }
        throw new \RuntimeException("ergonomic multi-input fixture {$fixtureName}: terminal must declare exactly one of 'run' or 'submit'");
    }

    /**
     * @param array<string, mixed> $payload Plain-array fixture form.
     */
    private static function buildWorkflowPayload(array $payload): WorkflowCreatePayload
    {
        $jobsRaw = $payload['jobs'] ?? [];
        if (!\is_array($jobsRaw)) {
            throw new \RuntimeException('createWorkflow: jobs must be a list');
        }
        $jobs = [];
        foreach ($jobsRaw as $jobRaw) {
            if (!\is_array($jobRaw)) {
                throw new \RuntimeException('createWorkflow: each job must be a mapping');
            }
            /** @var array<string, mixed> $jobRaw */
            $jobs[] = self::buildJob($jobRaw);
        }

        /** @var list<array{from: string, to: string}>|null $edges */
        $edges = null;
        if (isset($payload['workflow_edges']) && \is_array($payload['workflow_edges'])) {
            $edges = [];
            foreach ($payload['workflow_edges'] as $e) {
                if (\is_array($e) && isset($e['from'], $e['to'])) {
                    $edges[] = ['from' => (string) $e['from'], 'to' => (string) $e['to']];
                }
            }
        }

        $callbackEvents = null;
        if (isset($payload['callback_events']) && \is_array($payload['callback_events'])) {
            $callbackEvents = [];
            foreach ($payload['callback_events'] as $ev) {
                $callbackEvents[] = (string) $ev;
            }
        }

        return new WorkflowCreatePayload(
            jobs: $jobs,
            workflowEdges: $edges,
            callbackUrl: isset($payload['callback_url']) ? (string) $payload['callback_url'] : null,
            callbackEvents: $callbackEvents,
            exportPayload: isset($payload['export']) && \is_array($payload['export'])
                ? self::asAssoc($payload['export'])
                : null,
            delivery: isset($payload['delivery']) && \is_array($payload['delivery'])
                ? self::asAssoc($payload['delivery'])
                : null,
            processing: isset($payload['processing']) && \is_array($payload['processing'])
                ? self::asAssoc($payload['processing'])
                : null,
        );
    }

    /**
     * @param array<string, mixed> $job
     */
    private static function buildJob(array $job): JobDefinitionPayload
    {
        $opsRaw = $job['operations'] ?? [];
        if (!\is_array($opsRaw)) {
            throw new \RuntimeException('createWorkflow: operations must be a list');
        }
        $ops = [];
        foreach ($opsRaw as $opRaw) {
            if (!\is_array($opRaw)) {
                throw new \RuntimeException('createWorkflow: each operation must be a mapping');
            }
            $type = isset($opRaw['type']) ? (string) $opRaw['type'] : '';
            $options = null;
            if (isset($opRaw['options']) && \is_array($opRaw['options'])) {
                $options = self::asAssoc($opRaw['options']);
            }
            $ops[] = new OperationDef(type: $type, options: $options);
        }

        /** @var array<string, mixed>|null $source */
        $source = null;
        if (isset($job['source']) && \is_array($job['source'])) {
            $source = self::asAssoc($job['source']);
        }
        /** @var list<array<string, mixed>>|null $inputs */
        $inputs = null;
        if (isset($job['inputs']) && \is_array($job['inputs'])) {
            $inputs = [];
            foreach ($job['inputs'] as $input) {
                if (\is_array($input)) {
                    $inputs[] = self::asAssoc($input);
                }
            }
        }

        return new JobDefinitionPayload(
            operations: $ops,
            id: isset($job['id']) ? (string) $job['id'] : null,
            source: $source,
            inputs: $inputs,
            deliver: isset($job['deliver']) ? (bool) $job['deliver'] : null,
        );
    }

    /**
     * Deep-copy a fixture-shape associative array verbatim. Used for top-
     * level workflow-create blocks (export, delivery, processing, source,
     * operation options) where the wire shape IS the fixture shape and no
     * key conversion is needed.
     *
     * @return array<string, mixed>
     */
    private static function asAssoc(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            $key = (string) $k;
            $out[$key] = \is_array($v) ? self::deepCopy($v) : $v;
        }
        return $out;
    }

    /**
     * Recursive companion to {@see asAssoc} that preserves list-vs-assoc
     * shape on nested arrays — fixture nested arrays are typically lists
     * (e.g. workflow_edges, callback_events) and JSON-encoding a numeric-
     * keyed assoc would emit `{"0":...}` instead of a JSON array.
     *
     * @return array<string, mixed>|list<mixed>
     */
    private static function deepCopy(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        if (\array_is_list($value)) {
            $list = [];
            foreach ($value as $v) {
                $list[] = \is_array($v) ? self::deepCopy($v) : $v;
            }
            return $list;
        }
        $assoc = [];
        foreach ($value as $k => $v) {
            $assoc[(string) $k] = \is_array($v) ? self::deepCopy($v) : $v;
        }
        return $assoc;
    }

    /**
     * @param list<mixed> $args
     */
    private static function stringArg(array $args, int $index, string $fixtureName): string
    {
        if (!isset($args[$index]) || !\is_string($args[$index])) {
            throw new \RuntimeException(
                "Invoke: fixture {$fixtureName} arg #{$index} must be a string",
            );
        }
        return $args[$index];
    }

    /**
     * Write fixture bytes to a temp file whose basename matches the fixture's
     * `filename` — the SDK's `singleShotUpload`/`multipartUpload` path uses
     * `basename($filePath)` for the multipart form-data `filename`, so the
     * captured part filename must match the fixture's `filename` exactly.
     *
     * Each fixture gets its own ephemeral subdirectory so concurrent tests
     * cannot collide on a shared filename.
     */
    private static function writeTemp(string $bytes, string $filename): string
    {
        $base = \basename($filename);
        if ($base === '' || $base === '.' || $base === '..') {
            $base = 'fixture.bin';
        }
        $dir = \sys_get_temp_dir() . '/gisl-parity-' . \bin2hex(\random_bytes(6));
        if (!\mkdir($dir, 0700, true) && !\is_dir($dir)) {
            throw new \RuntimeException("Invoke: failed to mkdir {$dir}");
        }
        $path = $dir . '/' . $base;
        if (\file_put_contents($path, $bytes) === false) {
            throw new \RuntimeException("Invoke: failed to write {$path}");
        }
        return $path;
    }

    private static function runWebhook(Fixture $fixture): InvokeResult
    {
        if ($fixture->webhook === null) {
            throw new \RuntimeException("webhook fixture {$fixture->name}: missing webhook block");
        }
        $w = $fixture->webhook;
        $secret = (string) $w['secret'];
        $body = (string) $w['body'];
        $expectedHex = (string) $w['expected_signature_hex'];
        $headerFormat = (string) ($w['header_format'] ?? 'sha256={hex}');

        // Independently compute the digest so two buggy SDKs sharing one
        // bug can't both pass. Mirror the TS reference's parity-property
        // assertion before round-tripping through Webhook::verify.
        $computedHex = \hash_hmac('sha256', $body, $secret);
        if ($computedHex !== $expectedHex) {
            throw new \RuntimeException(
                "[{$fixture->name}] webhook parity failure: computed HMAC {$computedHex} != "
                . "expected_signature_hex {$expectedHex}",
            );
        }

        $headerValue = \str_replace('{hex}', $computedHex, $headerFormat);
        try {
            $ok = Webhook::verify($secret, $headerValue, $body);
            return new InvokeResult(returnValue: $ok, sseEvents: null, thrown: null, tempFiles: []);
        } catch (\Throwable $e) {
            return new InvokeResult(returnValue: false, sseEvents: null, thrown: $e, tempFiles: []);
        }
    }
}
