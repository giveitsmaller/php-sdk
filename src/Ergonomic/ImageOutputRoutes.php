<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

use Gisl\Generated\Operations\CompressMetadata;

/**
 * Route-aware image "Output" model (card YNLrGhNo, contracts tewB37Jg /
 * v2.97.0). PHP arm of the TS `image_output_routes.ts` — keep in lockstep.
 *
 * The file-first `output()`/`resize()` helpers resolve a single user-facing
 * "Output" operation to the right underlying wire op + options, driven by the
 * contract's `accepted-options/image-output-routes.json` projection. The route
 * is `(input format token, output_format token)`:
 *   - `same_format` (output == input) → `source_op: compress` (libcaesium
 *     optimiser), wire `output_format: 'original'`;
 *   - `format_change` (output != input) → `source_op: convert` (transcoder),
 *     wire `output_format: <token>`.
 *
 * Each route cell lists the options the worker HONORS (live) and PLANS
 * (advertised, not yet honored — gated unavailable). Resize (`width`/`height`/
 * `fit`) is INPUT-gated: since v2.103.0 convert is the resize engine, so the
 * projection lists resize on every `format_change` cell too — but resizability
 * is keyed to the INPUT (raster only — `svg` is vector and carries no resize).
 * The lowering reads resize capability from `same_format[input]` on BOTH routes,
 * so a `png → webp + resize` request resizes (png is raster) while an `svg → png
 * + resize` request does NOT (svg's same_format cell has no resize keys).
 *
 * This hand table MIRRORS the generated projection and is PINNED to it by the
 * output-route conformance test (the watermark-capability-gate precedent) — a
 * contract regen that changes a route's source_op / honored / planned options
 * fails that test. Kept a hand table (not a runtime JSON read) so the gate is
 * deterministic + offline, exactly like {@see WatermarkGate::CAPABILITY}.
 */
final class ImageOutputRoutes
{
    /** The resize option keys — input-keyed, raster-only (see class doc). */
    public const RESIZE_KEYS = ['width', 'height', 'fit'];

    /**
     * Options whose availability follows the INPUT format's raster capability,
     * not the output format — resize (`width`/`height`/`fit`) plus
     * `auto_orient`. The projection lists them on every `format_change` cell
     * (keyed by OUTPUT), so on a format change they must be re-gated against the
     * INPUT's `same_format` cell: a raster input carries them, an SVG (vector)
     * input does not. Before rtkzl9gr only the resize keys were input-gated, so
     * `auto_orient` leaked onto the `svg → raster` route and 422-ed server-side.
     *
     * @var list<string>
     */
    public const INPUT_GATED_KEYS = ['width', 'height', 'fit', 'auto_orient'];

    /** Image area cap shared by every resizable route (projection `max_output_pixels`). */
    public const MAX_OUTPUT_PIXELS = 16_000_000;

    /**
     * Output formats reachable via the legacy `compress(output_format=…)` facade
     * (projection `facade_managed_outputs`). Used ONLY as the undetectable-input
     * fallback — a detectable input always routes via `source_op`.
     *
     * @var list<string>
     */
    public const FACADE_MANAGED_OUTPUTS = ['webp'];

    /** Canonical MIME → bare format token (projection `mime_tokens`). */
    private const MIME_TOKEN = [
        'image/avif' => 'avif',
        'image/gif' => 'gif',
        'image/jpeg' => 'jpeg',
        'image/png' => 'png',
        'image/svg+xml' => 'svg',
        'image/tiff' => 'tiff',
        'image/webp' => 'webp',
    ];

    /** File extension → bare format token (for path / named-resource inputs). */
    private const EXT_TOKEN = [
        'jpg' => 'jpeg', 'jpeg' => 'jpeg', 'jpe' => 'jpeg', 'jfif' => 'jpeg',
        'png' => 'png', 'webp' => 'webp', 'gif' => 'gif', 'avif' => 'avif',
        'tif' => 'tiff', 'tiff' => 'tiff', 'svg' => 'svg',
    ];

    /**
     * Per-route, per-output-format honored + planned option keys, mirroring
     * `image-output-routes.json` `media.image`. `source_op` is uniform
     * (same_format→compress, format_change→convert) so it is a derivation rule,
     * not a table column. `same_format` is keyed by the INPUT token;
     * `format_change` by the OUTPUT token.
     *
     * @var array{
     *   same_format: array<string, array{honored: list<string>, planned: list<string>}>,
     *   format_change: array<string, array{honored: list<string>, planned: list<string>}>,
     * }
     */
    public const IMAGE_OUTPUT_ROUTES = [
        'same_format' => [
            'avif' => ['honored' => ['auto_orient', 'avif_speed', 'color_profile', 'encoding_mode', 'fit', 'height', 'metadata', 'output_format', 'quality', 'quality_preset', 'target_size_bytes', 'width'], 'planned' => []],
            'gif' => ['honored' => ['auto_orient', 'color_profile', 'fit', 'height', 'metadata', 'output_format', 'quality', 'width'], 'planned' => []],
            'jpeg' => ['honored' => ['auto_orient', 'chroma_subsampling', 'color_profile', 'encoding_mode', 'fit', 'height', 'lossless', 'metadata', 'output_format', 'progressive', 'quality', 'quality_preset', 'target_size_bytes', 'width'], 'planned' => []],
            'png' => ['honored' => ['auto_orient', 'color_profile', 'fit', 'height', 'metadata', 'optimization_level', 'output_format', 'quality', 'width'], 'planned' => []],
            'svg' => ['honored' => ['metadata', 'output_format'], 'planned' => []],
            'tiff' => ['honored' => ['auto_orient', 'color_profile', 'fit', 'height', 'metadata', 'output_format', 'quality', 'width'], 'planned' => []],
            'webp' => ['honored' => ['auto_orient', 'color_profile', 'encoding_mode', 'fit', 'height', 'lossless', 'metadata', 'output_format', 'quality', 'quality_preset', 'target_size_bytes', 'width'], 'planned' => []],
        ],
        'format_change' => [
            'avif' => ['honored' => ['auto_orient', 'color_profile', 'fit', 'height', 'output_format', 'quality', 'width'], 'planned' => ['metadata']],
            'gif' => ['honored' => ['auto_orient', 'color_profile', 'fit', 'height', 'output_format', 'width'], 'planned' => ['metadata']],
            'jpeg' => ['honored' => ['auto_orient', 'background', 'color_profile', 'fit', 'height', 'output_format', 'quality', 'width'], 'planned' => ['metadata']],
            'png' => ['honored' => ['auto_orient', 'color_profile', 'fit', 'height', 'output_format', 'width'], 'planned' => ['metadata']],
            'tiff' => ['honored' => ['auto_orient', 'color_profile', 'fit', 'height', 'output_format', 'width'], 'planned' => ['metadata']],
            'webp' => ['honored' => ['auto_orient', 'color_profile', 'fit', 'height', 'output_format', 'quality', 'width'], 'planned' => ['metadata']],
        ],
    ];

    /**
     * Compress-route enum members per image mime-group, mirroring the shipped
     * `availability/availability.json` `operations.compress.mime_groups.<group>.
     * options.<opt>.values`. Kept as a hand table (NOT a runtime read of the
     * ~238KB availability sidecar) so the enum-membership gate stays offline +
     * deterministic, exactly like self::IMAGE_OUTPUT_ROUTES — and so the gate
     * has NO dependency on a contracts version carrying the enum in a compact
     * form. PINNED to `availability.json` by ImageOutputRouteConformanceTest;
     * a contract regen that adds/changes an enum member fails there. Mirrors the
     * TS `COMPRESS_OPTION_VALUES`.
     *
     * `image_svg`/`image_avif` carry the NARROW `metadata: ['strip', 'all']`
     * (no `keep`) — the reason a value gate that consulted only the generic
     * `image` group (`['strip', 'keep', 'all']`) let `metadata: 'keep'` reach a
     * server 422 on those bases (rtkzl9gr). `output_format` is listed for a
     * faithful mirror but is never gated here (Output owns it positionally).
     *
     * @var array<string, array<string, list<string>>>
     */
    public const COMPRESS_OPTION_VALUES = [
        'image' => ['color_profile' => ['keep', 'srgb', 'strip'], 'fit' => ['max', 'crop', 'scale'], 'metadata' => ['strip', 'keep', 'all'], 'output_format' => ['original', 'webp', 'auto', 'smallest']],
        'image_jpeg' => ['chroma_subsampling' => ['420', '422', '444'], 'color_profile' => ['keep', 'srgb', 'strip'], 'encoding_mode' => ['quality', 'target_size', 'auto_quality'], 'fit' => ['max', 'crop', 'scale'], 'metadata' => ['strip', 'keep', 'all'], 'output_format' => ['original', 'webp', 'auto', 'smallest'], 'quality_preset' => ['best', 'good', 'fair', 'low']],
        'image_png' => ['color_profile' => ['keep', 'srgb', 'strip'], 'fit' => ['max', 'crop', 'scale'], 'metadata' => ['strip', 'keep', 'all'], 'output_format' => ['original', 'webp', 'auto', 'smallest']],
        'image_avif' => ['color_profile' => ['keep', 'srgb', 'strip'], 'encoding_mode' => ['quality', 'target_size', 'auto_quality'], 'fit' => ['max', 'crop', 'scale'], 'metadata' => ['strip', 'all'], 'output_format' => ['original', 'webp', 'auto', 'smallest'], 'quality_preset' => ['best', 'good', 'fair', 'low']],
        'image_svg' => ['metadata' => ['strip', 'all'], 'output_format' => ['original', 'webp', 'auto', 'smallest']],
        'image_webp' => ['color_profile' => ['keep', 'srgb', 'strip'], 'encoding_mode' => ['quality', 'target_size', 'auto_quality'], 'fit' => ['max', 'crop', 'scale'], 'metadata' => ['strip', 'keep', 'all'], 'output_format' => ['original', 'webp', 'auto', 'smallest'], 'quality_preset' => ['best', 'good', 'fair', 'low']],
    ];

    /** The bare format token for a MIME type, or null if not a known image MIME. */
    public static function tokenForMime(string $mime): ?string
    {
        $bare = \strtolower(\trim(\explode(';', $mime)[0]));
        return self::MIME_TOKEN[$bare] ?? null;
    }

    /** The bare format token for a filename / path extension, or null. */
    public static function tokenForPath(string $path): ?string
    {
        $dot = \strrpos($path, '.');
        if ($dot === false) {
            return null;
        }
        $ext = \strtolower(\substr($path, $dot + 1));
        return self::EXT_TOKEN[$ext] ?? null;
    }

    /**
     * Every image format token the projection knows (for validation / tests).
     *
     * @return list<string>
     */
    public static function knownImageTokens(): array
    {
        return \array_keys(self::IMAGE_OUTPUT_ROUTES['same_format']);
    }

    /**
     * Resolve an Output request to its wire op + gating sets. Returns null when
     * the route is unrepresentable (e.g. converting TO a format no
     * `format_change` cell covers). `$outputFormat` null → same-format (keep
     * input format). Mirrors the TS `resolveOutputRoute`.
     *
     * The returned shape (a small assoc array) mirrors the TS
     * `ResolvedOutputRoute`: `route`, `sourceOp`, `outputFormatWire`,
     * `inputToken`, plus `honored`/`planned` as key-sets (`array<string, true>`
     * for O(1) membership, the PHP idiom for the TS `ReadonlySet`).
     *
     * @return array{
     *   route: 'same_format'|'format_change',
     *   sourceOp: 'compress'|'convert',
     *   outputFormatWire: string,
     *   inputToken: string,
     *   honored: array<string, true>,
     *   planned: array<string, true>,
     * }|null
     */
    public static function resolveOutputRoute(string $inputToken, ?string $outputFormat): ?array
    {
        $outToken = $outputFormat ?? $inputToken;
        if ($outToken === $inputToken) {
            $cell = self::IMAGE_OUTPUT_ROUTES['same_format'][$inputToken] ?? null;
            if ($cell === null) {
                return null;
            }
            return [
                'route' => 'same_format',
                'sourceOp' => 'compress',
                'outputFormatWire' => 'original',
                'inputToken' => $inputToken,
                'honored' => self::keySet($cell['honored']),
                'planned' => self::keySet($cell['planned']),
            ];
        }
        $cell = self::IMAGE_OUTPUT_ROUTES['format_change'][$outToken] ?? null;
        if ($cell === null) {
            return null;
        }
        // Resize + auto_orient are INPUT-gated (see self::INPUT_GATED_KEYS).
        // Since v2.103.0 convert is the resize engine, so the projection lists
        // width/height/fit AND auto_orient on EVERY format_change cell — but an
        // SVG INPUT cannot be raster-resized or auto-oriented (the convert
        // worker rejects it). So strip the cell's input-gated keys and re-add
        // only those the INPUT's same_format cell honors: raster inputs carry
        // them, svg does not. The transcoder options (output_format/quality/
        // background/color_profile) ride the cell.
        $transcoderHonored = \array_values(\array_filter(
            $cell['honored'],
            static fn (string $k): bool => !\in_array($k, self::INPUT_GATED_KEYS, true),
        ));
        $inCell = self::IMAGE_OUTPUT_ROUTES['same_format'][$inputToken] ?? null;
        $inputGated = $inCell !== null
            ? \array_values(\array_filter(self::INPUT_GATED_KEYS, static fn (string $k): bool => \in_array($k, $inCell['honored'], true)))
            : [];
        return [
            'route' => 'format_change',
            'sourceOp' => 'convert',
            'outputFormatWire' => $outToken,
            'inputToken' => $inputToken,
            'honored' => self::keySet([...$transcoderHonored, ...$inputGated]),
            'planned' => self::keySet($cell['planned']),
        ];
    }

    /**
     * Whether a specific VALUE of an option is `availability: 'planned'` for the
     * given input format — the per-value gate (e.g. `metadata: 'keep'` is planned
     * even though the `metadata` key is honored). Reads the generated
     * `CompressMetadata` `per_value_availability`; same_format only (the only
     * route where value-level options like `metadata` are honored). Returns false
     * when the option / value / group is unknown (no gate). Mirrors the TS
     * `isPlannedValue`.
     */
    public static function isPlannedValue(string $inputToken, string $optionKey, mixed $value): bool
    {
        $group = CompressMetadata::instance()->mime_groups[self::compressGroupForToken($inputToken)] ?? null;
        if ($group === null) {
            return false;
        }
        $opt = $group->options[$optionKey] ?? null;
        if ($opt === null) {
            return false;
        }
        $entry = $opt->per_value_availability[self::stringifyForMessage($value)] ?? null;
        return $entry !== null && $entry->availability === 'planned';
    }

    /**
     * The compress mime-group whose enum members are authoritative for an image
     * token's SAME_FORMAT route — the exact `image_<token>` group when
     * self::COMPRESS_OPTION_VALUES carries one, else the generic `image` group
     * (gif/tiff). Mirrors the TS `enumGroupForToken`.
     *
     * Deliberately DISTINCT from {@see compressGroupForToken()} (the planned
     * gate). The planned gate routes webp/gif/svg/tiff through the generic
     * `image` group, where cross-format `planned` markers live (e.g. `srgb`).
     * Enum MEMBERSHIP is the opposite: it needs the format-specific enum,
     * because `image_svg`'s `metadata` enum is the narrow `['strip', 'all']`
     * while the generic group's is `['strip', 'keep', 'all']` — so only the
     * specific group rejects `metadata: 'keep'` on SVG (and AVIF, which already
     * maps specifically).
     */
    public static function enumGroupForToken(string $token): string
    {
        $specific = "image_{$token}";
        return isset(self::COMPRESS_OPTION_VALUES[$specific]) ? $specific : 'image';
    }

    /**
     * Whether a VALUE lies OUTSIDE the option's compress-route enum for the
     * given input format — the pre-upload enum-membership gate (rtkzl9gr).
     * Reads the hand self::COMPRESS_OPTION_VALUES table. Returns false when the
     * option is not an enum on this group (no entry), so a non-enum option
     * (e.g. integer `quality`) is never gated. Meaningful only on the
     * same_format (compress) route, where the compress option enums
     * definitionally apply. Membership is STRICT: a value whose type differs
     * from the string enum members (e.g. numeric `420`) is treated as unknown
     * rather than coerced to a match. Mirrors the TS `isUnknownEnumValue`.
     */
    public static function isUnknownEnumValue(string $inputToken, string $optionKey, mixed $value): bool
    {
        $members = self::COMPRESS_OPTION_VALUES[self::enumGroupForToken($inputToken)][$optionKey] ?? null;
        if ($members === null) {
            return false;
        }
        return !(\is_string($value) && \in_array($value, $members, true));
    }

    /**
     * Contract `depends_on` per compress-image output option (ehHU08Hu), mirroring
     * `availability.json` `operations.compress.mime_groups.<group>.options.<opt>.depends_on`.
     * Each entry is scalar-equality `['requiresKey' => k, 'requiresValue' => v]`
     * (contract `{ <key>: <value> }`) or set/logic:or `['requiresAnyOf' => [k1, k2]]`
     * (contract `{ k1: set, k2: set, logic: or }`). The rule is option-consistent
     * across every image group, so this is a FLAT table (pinned to availability.json
     * by `ImageOutputRouteConformanceTest`). Mirrors TS `OUTPUT_OPTION_DEPENDS_ON`.
     *
     * @var array<string, array{requiresKey: string, requiresValue: string}|array{requiresAnyOf: list<string>}>
     */
    public const OUTPUT_OPTION_DEPENDS_ON = [
        'quality' => ['requiresKey' => 'encoding_mode', 'requiresValue' => 'quality'],
        'lossless' => ['requiresKey' => 'encoding_mode', 'requiresValue' => 'quality'],
        'quality_preset' => ['requiresKey' => 'encoding_mode', 'requiresValue' => 'auto_quality'],
        'target_size_bytes' => ['requiresKey' => 'encoding_mode', 'requiresValue' => 'target_size'],
        'fit' => ['requiresAnyOf' => ['width', 'height']],
    ];

    /**
     * Default of each depended-on key — an ABSENT key resolves to this before the
     * dependency check (the server applies the same default). `encoding_mode`
     * defaults to `quality`. Mirrors TS `DEPENDS_ON_KEY_DEFAULTS`.
     *
     * @var array<string, string>
     */
    public const DEPENDS_ON_KEY_DEFAULTS = ['encoding_mode' => 'quality'];


    /**
     * The first contract `depends_on` an already-lowered compress-image wire-option
     * set violates for the resolved `$route`, or null when every dependency is
     * satisfied (ehHU08Hu). Only options PRESENT in `$wireOptions` are checked; a
     * scalar dependency reads the depended-on key's effective value
     * (self::DEPENDS_ON_KEY_DEFAULTS when absent). A scalar dependency on a
     * self::SAME_FORMAT_ONLY_DEPENDS_ON_KEYS key is skipped on a `format_change`;
     * universal deps (e.g. `fit → width|height`) run on BOTH routes. Mirrors the
     * TS `dependsOnViolation`.
     *
     * @param array<string, mixed>          $wireOptions
     * @param 'same_format'|'format_change' $route
     *
     * @return array{message: string, conflictingFields: list<string>}|null
     */
    public static function dependsOnViolation(array $wireOptions, string $route): ?array
    {
        foreach (self::OUTPUT_OPTION_DEPENDS_ON as $option => $rule) {
            // array_key_exists (not isset): match the TS `wireOptions[opt] !==
            // undefined` presence test — a present key counts as set regardless of
            // value (parity; isset would skip a present null).
            if (!\array_key_exists($option, $wireOptions)) {
                continue;
            }
            if (isset($rule['requiresAnyOf'])) {
                $anyPresent = false;
                foreach ($rule['requiresAnyOf'] as $key) {
                    if (\array_key_exists($key, $wireOptions)) {
                        $anyPresent = true;
                        break;
                    }
                }
                if (!$anyPresent) {
                    $keys = \implode(', ', $rule['requiresAnyOf']);
                    $keysOr = \implode(' or ', $rule['requiresAnyOf']);
                    return [
                        'message' => "output(): '{$option}' requires at least one of {$keys} to be set "
                            . "(its contract dependency). Set {$keysOr}, or drop '{$option}'.",
                        'conflictingFields' => \array_merge([$option], $rule['requiresAnyOf']),
                    ];
                }
                continue;
            }
            $key = $rule['requiresKey'];
            $required = $rule['requiresValue'];
            // Scalar deps in this (compress-image) table are all on `encoding_mode`,
            // a same_format optimiser key — validate them on same_format ONLY. A
            // format_change routes via `convert`, which has no encoding_mode and its
            // own output_format-based deps (a follow-up). The universal requiresAnyOf
            // dep (fit -> width|height) above runs on BOTH routes.
            if ($route !== 'same_format') {
                continue;
            }
            // Every scalar-dependency key carries a default in DEPENDS_ON_KEY_DEFAULTS
            // (a missing entry is a PHPStan error against the literal const), so the
            // depended-on key's effective value is its wire value or that default.
            $effective = $wireOptions[$key] ?? self::DEPENDS_ON_KEY_DEFAULTS[$key];
            if ($effective !== $required) {
                $shown = self::stringifyForMessage($effective);
                return [
                    'message' => "output(): '{$option}' requires {$key} '{$required}' (its contract "
                        . "dependency), but {$key} is '{$shown}'. Set {$key}: '{$required}', or drop '{$option}'.",
                    'conflictingFields' => [$key, $option],
                ];
            }
        }

        return null;
    }

    /**
     * Input token → its `compress.image*` mime-group name (for per-value
     * availability lookup). Mirrors the TS `compressGroupForToken`.
     */
    public static function compressGroupForToken(string $token): string
    {
        if ($token === 'jpeg') {
            return 'image_jpeg';
        }
        if ($token === 'png') {
            return 'image_png';
        }
        if ($token === 'avif') {
            return 'image_avif';
        }
        return 'image'; // webp / gif / svg / tiff
    }

    /**
     * Convert a list of keys to a presence-keyed set (`array<string, true>`),
     * the PHP idiom for the TS `ReadonlySet<string>` (O(1) `isset` membership).
     *
     * @param list<string> $keys
     *
     * @return array<string, true>
     */
    private static function keySet(array $keys): array
    {
        $set = [];
        foreach ($keys as $k) {
            $set[$k] = true;
        }
        return $set;
    }

    /**
     * Stringify an option value to match the TS `String(value)` — used BOTH as
     * the `per_value_availability` lookup key AND in the planned-value error
     * message, so PHP and TS produce byte-identical gating + identical message
     * text (`true`/`false` lowercase, numbers bare).
     */
    public static function stringifyForMessage(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_string($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        return \get_debug_type($value);
    }
}
