<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Generated\SdkSpec\Version;
use Gisl\Sdk\Preset\AudioCompressPresetOptions;
use Gisl\Sdk\Preset\DocumentEpubCompressPresetOptions;
use Gisl\Sdk\Preset\DocumentOdfCompressPresetOptions;
use Gisl\Sdk\Preset\DocumentOfficeCompressPresetOptions;
use Gisl\Sdk\Preset\ImageCompressPresetOptions;
use Gisl\Sdk\Preset\VideoCompressPresetOptions;
use Gisl\Sdk\PresetDefaults;

/**
 * Compress preset resolver (P6 / koMKJjLY — PHP equivalent of TS T4b
 * `27rE1fZn`). Faithful port of
 * `packages/typescript/src/ergonomic/preset_resolver.ts`.
 *
 * Walks five precedence layers (low → high) for a single compress
 * operation call and emits both:
 *  - `wireOptions` — snake_case payload ready for `OperationDef.options`.
 *  - `resolvedOptions` — introspection projection: which layer contributed
 *    each field (`sources`), plus presetVersion + optional presetConfigHash.
 *
 * Layer order (lowest precedence first; later layers overwrite earlier):
 *   1. `*CompressPresetOptions::shippedDefaultsFor($optimize)` — SDK
 *      defaults from the generated PRESETS matrix. Skipped entirely when
 *      `$optimize` is null.
 *   2. `$presetDefaults->cellFor(...)` — caller-side defaults registered via
 *      `Gisl::create(presetDefaults: ...)`.
 *   3. Scoped layer — reserved for P7 (`5k3ZWo6B`). Always empty in P6.
 *   4. Per-call `presetOverrides`.
 *   5. Explicit per-call knobs (the remaining operation-option fields).
 *
 * Validation runs AFTER the merge so post-merge-only invariants
 * (targetSize + codec, Lossless + quality) catch combinations where the
 * explicit knob and a layered default disagree. The resolver throws
 * {@see GislConfigError} BEFORE any network round-trip, with a
 * `resolvedSnapshot` showing the would-have-been-sent payload.
 *
 * PHP-specific divergence from the TS reference: leaf DTO fields hold the
 * ergonomic enum CASE (e.g. {@see \Gisl\Sdk\Generated\SdkSpec\Enums\VideoCodec}::H264),
 * whereas in TS the enum value IS the wire string. So where TS spreads a DTO
 * into a plain record, this resolver converts each typed non-null property
 * to its wire form via `$case->value` ({@see leafToRecord()}). The merge,
 * validation, source-attribution, and hashing logic then mirror TS exactly.
 *
 * @internal Not part of the public API — consumed by {@see OperationBuilder}.
 *
 * @phpstan-type WireRecord array<string, mixed>
 * @phpstan-type ResolveOutput array{wireOptions: array<string, mixed>, resolvedOptions: ResolvedOptions}
 */
final class PresetResolver
{
    /**
     * Sourced from the generated `sdk-spec/version.yaml` `presetVersion` (via
     * {@see Version::PRESET_VERSION}) so it can NEVER drift from the contracts
     * value — a regen propagates a bump with no hand edit here. Mirrors the TS
     * resolver, which re-exports `PRESET_VERSION` from the generated `version.ts`.
     */
    public const PRESET_VERSION = Version::PRESET_VERSION;

    /** @var list<string> Resolver source layers, lowest precedence first. */
    private const SOURCE_KEYS = ['sdkDefault', 'clientDefault', 'scopedDefault', 'callPresetOverride', 'explicit'];

    /**
     * Leaf preset DTO class → its media. Doubles as the allowlist of object
     * `presetOverrides` types AND the per-media type guard (a typed DTO for
     * the wrong media is rejected even when its fields overlap the operation
     * media — codex r2 MEDIUM, using the class info PHP has that TS doesn't).
     *
     * @var array<class-string, string>
     */
    private const DTO_MEDIA = [
        ImageCompressPresetOptions::class => 'image',
        AudioCompressPresetOptions::class => 'audio',
        VideoCompressPresetOptions::class => 'video',
        DocumentOfficeCompressPresetOptions::class => 'document_office',
        DocumentOdfCompressPresetOptions::class => 'document_odf',
        DocumentEpubCompressPresetOptions::class => 'document_epub',
    ];

    /**
     * Declarative camelCase → snake_case wire-field alias map. Lifted verbatim
     * from `preset_resolver.ts`. Fields whose ergonomic name IS the wire
     * name (`quality`, `codec`, …) are NOT here — {@see applyAlias()}
     * returns them unchanged. A generic snake_case regex would mistranslate
     * acronym/camel names (e.g. an `outputFormat` rename or the former
     * `iccProfile`); the declarative map is the only safe path. `targetSize`
     * is deliberately ABSENT — it is DERIVED into `target_size_bytes` +
     * `encoding_mode`, never emitted under its own snake form.
     *
     * @var array<string, string>
     */
    private const WIRE_ALIASES = [
        'outputFormat' => 'output_format',
        'sampleRate' => 'sample_rate',
        'audioCodec' => 'audio_codec',
        'audioBitrate' => 'audio_bitrate',
        'stripMacros' => 'strip_macros',
        'stripHiddenData' => 'strip_hidden_data',
        'stripUnusedFonts' => 'strip_unused_fonts',
        'stripMetadata' => 'strip_metadata',
        'stripUnusedStyles' => 'strip_unused_styles',
        'fontSubsetting' => 'font_subsetting',
        'stripUnusedCss' => 'strip_unused_css',
    ];

    /** Binary multipliers for the targetSize parser (1 KB = 1024). Verbatim from TS. */
    private const TARGET_SIZE_MULTIPLIERS = [
        'B' => 1,
        'KB' => 1024,
        'MB' => 1024 ** 2,
        'GB' => 1024 ** 3,
        'TB' => 1024 ** 4,
    ];

    /**
     * camelCase field sets per media — used by {@see detectMismatchedOverrides()}.
     * Verbatim from `preset_resolver.ts:338-346`.
     *
     * @var array<string, list<string>>
     */
    private const MEDIA_FIELDS = [
        'image' => ['quality', 'metadata', 'outputFormat'],
        'audio' => ['bitrate', 'channels', 'sampleRate', 'normalize'],
        'video' => ['codec', 'targetSize', 'crf', 'preset', 'width', 'height', 'fit', 'fps', 'faststart', 'audioCodec', 'audioBitrate'],
        'document_office' => ['stripMacros', 'stripHiddenData', 'stripUnusedFonts'],
        'document_odf' => ['stripMetadata', 'stripUnusedStyles'],
        'document_epub' => ['fontSubsetting', 'stripUnusedCss'],
    ];

    /**
     * snake_case wire-field surface per media — used by {@see validateMerged()}
     * unknown-field defence. Verbatim from `preset_resolver.ts:423-431`.
     *
     * Public so the wire-key conformance guard (WireKeyConformanceTest) can pin
     * this hand-maintained allowlist to the generated contract metadata: every
     * field the resolver may emit MUST be a real `compress` contract option key.
     *
     * @var array<string, list<string>>
     */
    public const KNOWN_WIRE_FIELDS = [
        'image' => ['quality', 'metadata', 'output_format'],
        'audio' => ['bitrate', 'channels', 'sample_rate', 'normalize', 'trim_start', 'trim_end'],
        'video' => ['codec', 'encoding_mode', 'crf', 'target_size_bytes', 'preset', 'width', 'height', 'fit', 'fps', 'faststart', 'audio_codec', 'audio_bitrate', 'trim_start', 'trim_end'],
        'document_office' => ['strip_macros', 'strip_hidden_data', 'strip_unused_fonts'],
        'document_odf' => ['strip_metadata', 'strip_unused_styles'],
        'document_epub' => ['font_subsetting', 'strip_unused_css'],
    ];

    private function __construct()
    {
    }

    /**
     * Resolve the wire payload + introspection projection for a compress
     * operation call. Throws {@see GislConfigError} before any network
     * round-trip when the merged options violate a documented constraint.
     *
     * @param string                         $media           One of the {@see MEDIA_FIELDS} keys.
     * @param PresetDefaults|null             $presetDefaults  Client-scope defaults (layer 2).
     * @param PresetDefaults|null             $scopedDefaults  Scoped defaults (layer 3 — P7; null in P6).
     * @param object|array<string, mixed>|null $presetOverrides Per-call overrides (layer 4): a leaf
     *                                                          `*CompressPresetOptions` instance or a
     *                                                          camelCase array.
     * @param OptimizeFor|null               $optimize        Preset level; null ⇒ no shipped/client/scoped layers.
     * @param array<string, mixed>           $explicitOptions Explicit per-call knobs (layer 5), camelCase.
     * @param bool|null                      $audioLossless   Classifier result from media detection. When `true`
     *                                                         on audio, the shipped-preset (sdkDefault) bitrate is
     *                                                         dropped — the worker rejects `bitrate` on lossless
     *                                                         outputs (flac/wav — contracts iakhSy3E).
     *
     * @return array{wireOptions: array<string, mixed>, resolvedOptions: ResolvedOptions}
     */
    public static function resolveCompress(
        string $media,
        ?PresetDefaults $presetDefaults,
        ?PresetDefaults $scopedDefaults,
        object|array|null $presetOverrides,
        ?OptimizeFor $optimize,
        array $explicitOptions,
        ?bool $audioLossless = null,
    ): array {
        if ($media === 'document_pdf') {
            throw new GislConfigError(
                'PDF compression was removed at contracts v2.166.0; convert() / transform() still accept PDF.',
                reason: 'unsupported_media',
            );
        }
        if (!isset(self::MEDIA_FIELDS[$media])) {
            throw new GislConfigError(
                "Preset resolution received an unknown compress media '{$media}'.",
                reason: 'unknown_media',
            );
        }

        // Normalise each layer to a camelCase record of wire-ready values
        // (enum case → value). These mirror the TS layer records (camelCase
        // keys, wire values) before alias application.
        if (\is_object($presetOverrides)) {
            // codex r1 LOW 9b8bbc2542cb — an arbitrary object would normalise
            // to an empty override (no public props) and silently produce a
            // presetConfigHash. Only the leaf preset DTOs are valid object
            // overrides; untyped maps must be passed as arrays.
            $overrideClass = $presetOverrides::class;
            $dtoMedia = self::DTO_MEDIA[$overrideClass] ?? null;
            if ($dtoMedia === null) {
                throw new GislConfigError(
                    "presetOverrides object must be a *CompressPresetOptions leaf DTO; got {$overrideClass}.",
                    reason: 'invalid_preset_overrides',
                    conflictingFields: ['presetOverrides'],
                );
            }
            // codex r2 MEDIUM — a typed DTO carries its media in its class;
            // reject a wrong-media DTO up front even when its fields overlap
            // the operation media (the field-based detectMismatchedOverrides
            // below cannot, having lost the type).
            if ($dtoMedia !== $media) {
                throw new GislConfigError(
                    "presetOverrides is a {$overrideClass} (media '{$dtoMedia}') but the operation media is '{$media}'.",
                    reason: 'type_mismatch',
                    conflictingFields: ['presetOverrides'],
                    suggestion: "Pass the {$media}-media preset DTO, or use the matching builder method.",
                );
            }
        }
        $overrideRecord = $presetOverrides === null ? null : self::toRecord($presetOverrides);

        // 0. Type-mismatch detection runs BEFORE the merge so the error
        // points at the typed argument the caller passed, not the wire shape.
        if ($overrideRecord !== null) {
            self::detectMismatchedOverrides($media, $overrideRecord);
        }

        $mediaOpKey = $media . '_compress';

        $sdkDefault = $optimize === null ? null : self::leafToRecord(self::sdkDefaultDto($media, $optimize));
        $clientDefault = $optimize === null ? null : self::cellRecord($presetDefaults, $mediaOpKey, $optimize);
        $scopedDefault = $optimize === null ? null : self::cellRecord($scopedDefaults, $mediaOpKey, $optimize);

        // 1-5. Walk layers and accumulate (merged payload + per-field winners).
        /** @var array<string, mixed> $merged */
        $merged = [];
        /** @var array<string, string> $winners wireKey → source */
        $winners = [];

        self::mergeLayer($merged, $winners, $sdkDefault, 'sdkDefault');
        self::mergeLayer($merged, $winners, $clientDefault, 'clientDefault');
        self::mergeLayer($merged, $winners, $scopedDefault, 'scopedDefault');
        self::mergeLayer($merged, $winners, $overrideRecord, 'callPresetOverride');
        $explicitRecord = self::toRecord($explicitOptions);
        self::mergeLayer($merged, $winners, $explicitRecord, 'explicit');

        // 6. Resolve `targetSize` (video-only) → `target_size_bytes` +
        // `encoding_mode='target_size'`; explicit `crf` → `encoding_mode='crf'`.
        if ($media === 'video') {
            if (\array_key_exists('targetSize', $merged)) {
                $rawTargetSize = $merged['targetSize'];
                unset($merged['targetSize'], $winners['targetSize']);
                $bytes = self::parseTargetSize($rawTargetSize);
                // Which layer "wins" the derived keys = whichever last set
                // the camelCase `targetSize` (walk HIGH → LOW).
                $targetSizeSource = self::targetSizeSource(
                    $explicitRecord,
                    $overrideRecord,
                    $scopedDefault,
                    $clientDefault,
                );
                $merged['target_size_bytes'] = $bytes;
                $merged['encoding_mode'] = 'target_size';
                $winners['target_size_bytes'] = $targetSizeSource;
                $winners['encoding_mode'] = $targetSizeSource;
            } elseif (\array_key_exists('crf', $merged) && !\array_key_exists('encoding_mode', $merged)) {
                // crf present (even an explicit null — mirrors TS `!== undefined`)
                // with no targetSize: emit encoding_mode='crf'.
                $crfSource = $winners['crf'] ?? 'explicit';
                $merged['encoding_mode'] = 'crf';
                $winners['encoding_mode'] = $crfSource;
            }
        }

        // audio_compress bakes a bitrate (Size 96 / Balanced 192 / Quality 320);
        // the worker rejects `bitrate` on lossless outputs (flac/wav — contracts
        // iakhSy3E). Drop ONLY the shipped-preset (sdkDefault) bitrate for
        // clear-cut lossless audio; any user-supplied bitrate (client/scoped
        // default, per-call override or explicit) is left for the worker to
        // reject — no silent-ignore. Mirrors preset_resolver.ts.
        if ($media === 'audio' && $audioLossless === true && ($winners['bitrate'] ?? null) === 'sdkDefault') {
            unset($merged['bitrate'], $winners['bitrate']);
        }

        // 7. Validate the merged payload (post-merge — catches cross-layer
        // disagreements). May throw GislConfigError with a resolvedSnapshot.
        $explicitWireKeys = [];
        foreach (\array_keys($explicitRecord) as $camelKey) {
            $explicitWireKeys[self::applyAlias($camelKey)] = true;
        }
        if (\array_key_exists('targetSize', $explicitRecord)) {
            $explicitWireKeys['targetSize'] = true;
        }
        if (\array_key_exists('crf', $explicitRecord)) {
            $explicitWireKeys['crf'] = true;
        }
        self::validateMerged($media, $merged, $explicitWireKeys, $winners);

        // 8. Build the source buckets from the winners map.
        $sources = self::buildSources($winners);

        // 9. presetConfigHash — present iff any non-SDK layer's cell was
        // REGISTERED (an empty registered cell still contributes presence).
        $presetConfigHash = self::computePresetConfigHash($clientDefault, $scopedDefault, $overrideRecord);

        // 10. ResolvedOptions surface. `overrides` retained for back-compat
        // (mirror of sources->explicit).
        $resolvedOptions = new ResolvedOptions(
            preset: $optimize?->value,
            applied: $merged,
            overrides: $sources->explicit,
            presetVersion: self::PRESET_VERSION,
            sources: $sources,
            presetConfigHash: $presetConfigHash,
        );

        return ['wireOptions' => $merged, 'resolvedOptions' => $resolvedOptions];
    }

    private static function applyAlias(string $camelKey): string
    {
        return self::WIRE_ALIASES[$camelKey] ?? $camelKey;
    }

    /**
     * Normalise a leaf DTO or a camelCase array to a camelCase record of
     * wire-ready values, converting backed-enum cases to their wire value.
     *
     * NULL HANDLING mirrors the TS resolver, which skips only `undefined`
     * and PRESERVES `null` (`mergeLayer` at preset_resolver.ts:411). PHP has
     * no `undefined`, so the two source kinds are handled distinctly:
     *  - a leaf DTO ({@see leafToRecord}) treats `null` as "field unset"
     *    (sparse delta) and DROPS it — the DTO's optional-field default;
     *  - a caller-supplied array (explicit options / array presetOverrides)
     *    has no `undefined`, so every present key is intentional and is KEPT
     *    even when its value is `null` (codex r1 MEDIUM 8474a34aa0aa —
     *    dropping array nulls silently diverged from TS, which would send the
     *    explicit `null`).
     *
     * @param object|array<string, mixed> $source
     *
     * @return array<string, mixed>
     */
    private static function toRecord(object|array $source): array
    {
        if (\is_object($source)) {
            return self::leafToRecord($source);
        }
        $out = [];
        foreach ($source as $key => $value) {
            $out[(string) $key] = self::toWireValue($value);
        }
        return $out;
    }

    /**
     * Convert a `*CompressPresetOptions` leaf DTO to a camelCase wire record,
     * dropping null fields (sparse-delta semantics) and converting enum cases.
     *
     * @param object $dto A `*CompressPresetOptions` leaf DTO.
     *
     * @return array<string, mixed>
     */
    private static function leafToRecord(object $dto): array
    {
        $out = [];
        foreach (\get_object_vars($dto) as $key => $value) {
            if ($value === null) {
                continue;
            }
            $out[(string) $key] = self::toWireValue($value);
        }
        return $out;
    }

    private static function toWireValue(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        return $value;
    }

    private static function sdkDefaultDto(string $media, OptimizeFor $optimize): object
    {
        return match ($media) {
            'image' => ImageCompressPresetOptions::shippedDefaultsFor($optimize),
            'audio' => AudioCompressPresetOptions::shippedDefaultsFor($optimize),
            'video' => VideoCompressPresetOptions::shippedDefaultsFor($optimize),
            'document_office' => DocumentOfficeCompressPresetOptions::shippedDefaultsFor($optimize),
            'document_odf' => DocumentOdfCompressPresetOptions::shippedDefaultsFor($optimize),
            'document_epub' => DocumentEpubCompressPresetOptions::shippedDefaultsFor($optimize),
            default => throw new GislConfigError(
                "Preset resolution received an unknown compress media '{$media}'.",
                reason: 'unknown_media',
            ),
        };
    }

    /**
     * Look up the `(mediaOpKey, optimize)` cell in a PresetDefaults and return
     * a camelCase record, or null when the defaults aren't supplied or the
     * cell wasn't registered. A REGISTERED empty cell returns `[]` (present —
     * contributes to presence/hash), mirroring TS `{...cell}`.
     *
     * @return array<string, mixed>|null
     */
    private static function cellRecord(?PresetDefaults $defaults, string $mediaOpKey, OptimizeFor $optimize): ?array
    {
        if ($defaults === null) {
            return null;
        }
        $cell = $defaults->cellFor($mediaOpKey, $optimize);
        if ($cell === null) {
            return null;
        }
        return self::leafToRecord($cell);
    }

    /**
     * @param array<string, mixed>      $merged
     * @param array<string, string>     $winners
     * @param array<string, mixed>|null $layer
     */
    private static function mergeLayer(array &$merged, array &$winners, ?array $layer, string $source): void
    {
        if ($layer === null) {
            return;
        }
        // NOTE: null values are NOT skipped here — leaf DTOs already dropped
        // their unset (null) fields in leafToRecord(), and array layers keep
        // intentional `null`s to mirror the TS resolver (which skips only
        // `undefined`, never `null`). See toRecord()/leafToRecord().
        foreach ($layer as $camelKey => $value) {
            $wireKey = self::applyAlias((string) $camelKey);
            $merged[$wireKey] = $value;
            $winners[$wireKey] = $source;
        }
    }

    /**
     * @param array<string, mixed>      $explicitRecord
     * @param array<string, mixed>|null $overrideRecord
     * @param array<string, mixed>|null $scopedDefault
     * @param array<string, mixed>|null $clientDefault
     *
     * @return 'explicit'|'callPresetOverride'|'scopedDefault'|'clientDefault'|'sdkDefault'
     */
    private static function targetSizeSource(
        array $explicitRecord,
        ?array $overrideRecord,
        ?array $scopedDefault,
        ?array $clientDefault,
    ): string {
        if (\array_key_exists('targetSize', $explicitRecord)) {
            return 'explicit';
        }
        if ($overrideRecord !== null && \array_key_exists('targetSize', $overrideRecord)) {
            return 'callPresetOverride';
        }
        if ($scopedDefault !== null && \array_key_exists('targetSize', $scopedDefault)) {
            return 'scopedDefault';
        }
        if ($clientDefault !== null && \array_key_exists('targetSize', $clientDefault)) {
            return 'clientDefault';
        }
        return 'sdkDefault';
    }

    /**
     * Parse a `targetSize` value into a positive integer byte count.
     *
     * - Integer input passes through after a positive-whole check.
     * - String input must match `<number><unit?>` with unit in
     *   B|KB|MB|GB|TB (case-insensitive). Multipliers are BINARY
     *   (1 KB = 1024). Decimal magnitudes allowed (`'1.5GB'`), truncated
     *   toward zero to integer bytes.
     *
     * Throws {@see GislConfigError} (`reason: invalid_target_size`) on any
     * other input. Verbatim port of `_parseTargetSize` (`preset_resolver.ts:172-244`).
     *
     * @internal Exposed for unit tests.
     */
    public static function parseTargetSize(mixed $value): int
    {
        if (\is_int($value)) {
            if ($value <= 0) {
                throw new GislConfigError(
                    "targetSize integer must be a positive whole byte count; got {$value}.",
                    reason: 'invalid_target_size',
                    conflictingFields: ['targetSize'],
                    suggestion: 'Pass a positive integer or a suffixed string like "50MB".',
                );
            }
            return $value;
        }
        if (!\is_string($value)) {
            $type = \get_debug_type($value);
            throw new GislConfigError(
                "targetSize must be a positive integer (bytes) or a string like \"50MB\"; got {$type}.",
                reason: 'invalid_target_size',
                conflictingFields: ['targetSize'],
                suggestion: 'Pass a positive integer or a suffixed string like "50MB".',
            );
        }

        $trimmed = \trim($value);
        if (\preg_match('/^(\d+(?:\.\d+)?)\s*([A-Za-z]+)?$/', $trimmed, $match) !== 1) {
            throw new GislConfigError(
                "targetSize string '{$value}' is not a valid size — expected '<number><B|KB|MB|GB|TB>'.",
                reason: 'invalid_target_size',
                conflictingFields: ['targetSize'],
                suggestion: "Use '50MB', '1.5GB', or a raw integer byte count.",
            );
        }
        $magnitudeStr = $match[1];
        $unitStr = ($match[2] ?? '') === '' ? 'B' : \strtoupper($match[2]);
        if (!\array_key_exists($unitStr, self::TARGET_SIZE_MULTIPLIERS)) {
            $rawUnit = $match[2] ?? '';
            throw new GislConfigError(
                "targetSize unit '{$rawUnit}' is not recognised — expected B / KB / MB / GB / TB.",
                reason: 'invalid_target_size',
                conflictingFields: ['targetSize'],
                suggestion: 'Use one of B / KB / MB / GB / TB (binary; 1 KB = 1024).',
            );
        }
        $magnitude = (float) $magnitudeStr;
        if ($magnitude <= 0.0) {
            throw new GislConfigError(
                "targetSize magnitude '{$magnitudeStr}' must be a positive number.",
                reason: 'invalid_target_size',
                conflictingFields: ['targetSize'],
            );
        }
        $bytes = (int) \floor($magnitude * self::TARGET_SIZE_MULTIPLIERS[$unitStr]);
        if ($bytes <= 0) {
            throw new GislConfigError(
                "targetSize '{$value}' resolves to zero bytes after binary multiplication.",
                reason: 'invalid_target_size',
                conflictingFields: ['targetSize'],
            );
        }
        return $bytes;
    }

    /**
     * Detect a `presetOverrides` whose keys belong entirely to a DIFFERENT
     * media — throws `type_mismatch` before the merge. Verbatim port of
     * `detectMismatchedOverrides` (`preset_resolver.ts:348-389`).
     *
     * @param array<string, mixed> $overrides camelCase record.
     */
    private static function detectMismatchedOverrides(string $media, array $overrides): void
    {
        $expected = self::MEDIA_FIELDS[$media];
        $keys = \array_keys($overrides);
        if (\count($keys) === 0) {
            return;
        }
        $unknownFields = \array_values(\array_filter(
            $keys,
            static fn (int|string $k): bool => !\in_array((string) $k, $expected, true),
        ));
        if (\count($unknownFields) === 0) {
            return;
        }
        foreach (self::MEDIA_FIELDS as $otherMedia => $otherSet) {
            if ($otherMedia === $media) {
                continue;
            }
            $allInOther = true;
            foreach ($unknownFields as $k) {
                if (!\in_array((string) $k, $otherSet, true)) {
                    $allInOther = false;
                    break;
                }
            }
            if ($allInOther) {
                $className = \implode('', \array_map(
                    static fn (string $s): string => \ucfirst($s),
                    \explode('_', $otherMedia),
                )) . 'CompressPresetOptions';
                $fieldList = \implode(', ', \array_map(static fn (int|string $k): string => (string) $k, $unknownFields));
                throw new GislConfigError(
                    "presetOverrides for operation media '{$media}' contained fields from '{$otherMedia}': {$fieldList}.",
                    reason: 'type_mismatch',
                    conflictingFields: \array_map(static fn (int|string $k): string => (string) $k, $unknownFields),
                    suggestion: "Pass a {$className} shape, or use the matching builder method.",
                );
            }
        }
        // Otherwise the unknown fields are nonsense — fall through to the
        // unknown_field validation that runs against the merged record.
    }

    /**
     * Post-merge validation. Verbatim port of `validateMerged`
     * (`preset_resolver.ts:433-521`).
     *
     * @param array<string, mixed>  $merged
     * @param array<string, bool>   $explicitWireKeys keyset (value ignored).
     * @param array<string, string> $winners
     */
    private static function validateMerged(string $media, array &$merged, array $explicitWireKeys, array &$winners): void
    {
        // Unknown-field defence-in-depth.
        $known = self::KNOWN_WIRE_FIELDS[$media];
        foreach (\array_keys($merged) as $key) {
            if (!\in_array((string) $key, $known, true)) {
                throw new GislConfigError(
                    "Resolved wire payload contains unknown field '{$key}' for media '{$media}'.",
                    reason: 'unknown_field',
                    conflictingFields: [(string) $key],
                    resolvedSnapshot: $merged,
                );
            }
        }

        // Video: targetSize-derived encoding_mode='target_size' is only valid
        // for H264 today, and is mutually exclusive with explicit crf.
        if ($media === 'video' && ($merged['encoding_mode'] ?? null) === 'target_size') {
            $codec = $merged['codec'] ?? null;
            if ($codec !== null && $codec !== 'h264') {
                $codecStr = \is_scalar($codec) ? (string) $codec : \get_debug_type($codec);
                throw new GislConfigError(
                    "Video compress: 'targetSize' only supports codec 'h264' today; resolved codec is '{$codecStr}'.",
                    reason: 'invalid_combination',
                    conflictingFields: ['targetSize', 'codec'],
                    resolvedSnapshot: $merged,
                    suggestion: 'Either use codec H264, or drop targetSize and use crf instead.',
                );
            }
            if (\array_key_exists('crf', $explicitWireKeys)) {
                throw new GislConfigError(
                    "Video compress: 'targetSize' and 'crf' are mutually exclusive encoding modes.",
                    reason: 'invalid_combination',
                    conflictingFields: ['targetSize', 'crf'],
                    resolvedSnapshot: $merged,
                    suggestion: 'Choose one — drop targetSize to use crf, or drop crf to use targetSize.',
                );
            }
            // crf from a lower layer is silently dropped since
            // encoding_mode='target_size' supersedes it — keep `applied` and
            // `sources.*` consistent (drop the orphaned winners entry too).
            if (\array_key_exists('crf', $merged)) {
                unset($merged['crf'], $winners['crf']);
            }
        }
    }

    /**
     * @param array<string, string> $winners wireKey → source.
     */
    private static function buildSources(array $winners): ResolvedOptionsSources
    {
        $buckets = [
            'sdkDefault' => [],
            'clientDefault' => [],
            'scopedDefault' => [],
            'callPresetOverride' => [],
            'explicit' => [],
        ];
        foreach ($winners as $wireKey => $source) {
            $buckets[$source][] = (string) $wireKey;
        }
        foreach (self::SOURCE_KEYS as $key) {
            \sort($buckets[$key], \SORT_STRING);
        }
        return new ResolvedOptionsSources(
            sdkDefault: $buckets['sdkDefault'],
            clientDefault: $buckets['clientDefault'],
            scopedDefault: $buckets['scopedDefault'],
            callPresetOverride: $buckets['callPresetOverride'],
            explicit: $buckets['explicit'],
        );
    }

    /**
     * sha256 over the canonical JSON of `{clientDefault, scopedDefault,
     * callPresetOverride}`. Present iff a non-SDK layer cell was REGISTERED
     * (an empty registered cell still contributes presence). Verbatim port of
     * `computePresetConfigHash` (`preset_resolver.ts:552-569`).
     *
     * @param array<string, mixed>|null $clientDefault
     * @param array<string, mixed>|null $scopedDefault
     * @param array<string, mixed>|null $callPresetOverride
     */
    private static function computePresetConfigHash(?array $clientDefault, ?array $scopedDefault, ?array $callPresetOverride): ?string
    {
        if ($clientDefault === null && $scopedDefault === null && $callPresetOverride === null) {
            return null;
        }
        $canonical = self::canonicalJson([
            'clientDefault' => $clientDefault,
            'scopedDefault' => $scopedDefault,
            'callPresetOverride' => $callPresetOverride,
        ]);
        return 'sha256:' . \hash('sha256', $canonical);
    }

    /**
     * Canonical JSON matching the TS reference's hand-rolled serialiser
     * (`preset_resolver.ts:527-550`): recursive key-sort + `JSON.stringify`
     * semantics. The resolver's hash inputs are maps-or-scalars-or-null and
     * NEVER JS arrays, so every PHP array is treated as a sorted-key OBJECT —
     * critically, an empty registered cell `[]` serialises as `{}` (matching
     * TS `{...cell}` → `{}`), not `[]`.
     *
     * Keys are camelCase ASCII, so PHP `SORT_STRING` (byte order) matches
     * JS `Object.keys().sort()` (UTF-16 order) for this input set.
     */
    private static function canonicalJson(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_int($value)) {
            return (string) $value;
        }
        if (\is_string($value) || \is_float($value)) {
            return \json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        }
        // array — treated as an object/map (see method docblock).
        /** @var array<string, mixed> $value */
        $keys = \array_map(static fn (int|string $k): string => (string) $k, \array_keys($value));
        \sort($keys, \SORT_STRING);
        $parts = [];
        foreach ($keys as $k) {
            $parts[] = \json_encode($k, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)
                . ':' . self::canonicalJson($value[$k]);
        }
        return '{' . \implode(',', $parts) . '}';
    }
}
