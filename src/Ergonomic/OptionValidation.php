<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

use Gisl\Generated\Operations\ConvertMetadata;
use Gisl\Generated\Operations\ImageWatermarkMetadata;
use Gisl\Generated\Operations\OperationMetadata;
use Gisl\Generated\Operations\TextWatermarkMetadata;
use Gisl\Generated\Operations\ThumbnailMetadata;
use Gisl\Generated\Operations\TransformMetadata;
use Gisl\Generated\Operations\VideoWatermarkMetadata;
use Gisl\Sdk\Errors\GislConfigError;

/**
 * Eager, synchronous, PRE-UPLOAD option-key validation for the ergonomic verbs
 * (card Dhje3Faq). PHP arm of the TS `option_validation.ts` — keep in lockstep.
 *
 * The file-first builders accept verb options as untyped `array<string, mixed>`
 * bags; a user typo (`['quaity' => 80]`) would otherwise flow to the server and
 * 422. This rejects unknown keys at the verb call — before any upload — mirroring
 * the `compress()` optimize-check and the watermark eager gate. The allowed key
 * set is read from the generated `OperationMetadata` (the same contract-anchored
 * source the wire-key conformance guard uses), so it cannot silently drift.
 *
 * SCOPE: `convert` / `thumbnail` / `textWatermark` / `watermark` / `output`
 * only. `compress` is deliberately EXCLUDED — its bag legitimately carries
 * SDK-only keys (`optimize`, `presetOverrides`) and resolver aliases; it has its
 * own `unknown_field` validation through the preset resolver.
 */
final class OptionValidation
{
    /**
     * Lazily-computed allowed-key set per verb. `null` until first use.
     *
     * @var array<string, array<string, true>>|null
     */
    private static ?array $allowedCache = null;

    /**
     * Keys a verb OWNS via a positional argument: a user must not also supply
     * them in the options bag (they would be silently overridden). Checked
     * BEFORE the generic key check so they get a specific message. `format` is
     * an SDK alias for the positional (not a contract key) and is owned too.
     *
     * @var array<string, list<string>>
     */
    private const POSITIONAL_OWNED = [
        'convert' => ['output_format', 'format'],
        'textWatermark' => ['text'],
        // `output(format, …)` sets the target format via its first argument; the
        // wire key `output_format` and the SDK alias `format` must not be supplied
        // in the bag.
        'output' => ['output_format', 'format'],
    ];

    /**
     * The user-supplyable option keys for the image `output` facade — the UNION
     * of every image route's honored+planned options (the image-output-routes
     * projection). This is the COARSE static gate (reject keys no image route
     * ever honors, e.g. a video `crf`); the precise per-route honored/planned
     * narrowing happens in the `output()` lowering ({@see ImageOutputRoutes::resolveOutputRoute()}).
     * Mirrors the TS `OUTPUT_OPTION_KEYS`; pinned to the projection union by the
     * output-route conformance test.
     *
     * @var list<string>
     */
    private const OUTPUT_OPTION_KEYS = [
        'quality', 'quality_preset', 'encoding_mode', 'target_size_bytes', 'chroma_subsampling', 'width', 'height', 'fit',
        'background', 'progressive', 'optimization_level', 'avif_speed', 'metadata',
        'color_profile', 'auto_orient', 'lossless',
    ];

    /**
     * OPERATION-LEVEL contract option keys: the union of every mime group's
     * `options` plus `direct_options`. Excludes `per_input_options`. Mirrors the
     * wire-key conformance guard's helper.
     *
     * @return array<string, true>
     */
    public static function operationOptionKeys(OperationMetadata $metadata): array
    {
        $keys = [];
        foreach ($metadata->mime_groups as $group) {
            foreach (array_keys($group->options) as $k) {
                $keys[$k] = true;
            }
        }
        foreach (array_keys($metadata->direct_options) as $k) {
            $keys[$k] = true;
        }

        return $keys;
    }

    /**
     * Allowed user-supplyable option keys for a validated verb. `watermark` is
     * the UNION of `image_watermark` + `video_watermark` (base media may be
     * undetectable at the `.watermark()` call; routing is gated separately).
     *
     * @return array<string, true>
     */
    public static function allowedKeysFor(string $verb): array
    {
        if (self::$allowedCache === null) {
            $output = [];
            foreach (self::OUTPUT_OPTION_KEYS as $k) {
                $output[$k] = true;
            }
            // Like `convert`, the allowlist INCLUDES the positional-owned
            // `output_format` (in every route's honored set) — rejected first by
            // the POSITIONAL_OWNED guard, not the allowed-key check. Keeps the PHP
            // allowlist == the full projection union, matching TS.
            $output['output_format'] = true;
            self::$allowedCache = [
                'convert' => self::operationOptionKeys(ConvertMetadata::instance()),
                'thumbnail' => self::operationOptionKeys(ThumbnailMetadata::instance()),
                'transform' => self::operationOptionKeys(TransformMetadata::instance()),
                'textWatermark' => self::operationOptionKeys(TextWatermarkMetadata::instance()),
                'watermark' => self::operationOptionKeys(ImageWatermarkMetadata::instance())
                    + self::operationOptionKeys(VideoWatermarkMetadata::instance()),
                // `output` is the image Output facade — its bag-allowed keys are
                // the UNION of every image route's honored+planned options (the
                // image-output-routes projection), NOT a single op's contract keys.
                'output' => $output,
            ];
        }

        return self::$allowedCache[$verb] ?? [];
    }

    /**
     * Validate a USER-supplied options bag for an ergonomic verb. Throws
     * {@see GislConfigError} (reason `unknown_field`) synchronously, BEFORE any
     * upload or wire-key injection. Call at the TOP of every verb body.
     *
     * @param array<string, mixed> $options
     *
     * @throws GislConfigError reason `unknown_field` for a positional-owned key
     *                         supplied in the bag, or a key absent from the op's
     *                         contract option set.
     */
    public static function validateVerbOptions(string $verb, array $options): void
    {
        foreach (self::POSITIONAL_OWNED[$verb] ?? [] as $key) {
            if (array_key_exists($key, $options)) {
                $arg = $verb === 'textWatermark' ? 'text' : 'output format';
                throw new GislConfigError(
                    sprintf("%s() sets the %s via its first argument; remove '%s' from the options bag.", $verb, $arg, $key),
                    'unknown_field',
                    [$key],
                );
            }
        }

        $allowed = self::allowedKeysFor($verb);
        foreach (array_keys($options) as $key) {
            if (!isset($allowed[$key])) {
                $valid = array_keys($allowed);
                sort($valid);
                throw new GislConfigError(
                    sprintf("%s: unknown option '%s'. Valid options: %s.", $verb, (string) $key, implode(', ', $valid)),
                    'unknown_field',
                    [(string) $key],
                );
            }
        }
    }

    /**
     * Validate the option bag for the SINGLE-OP builder `convert(input, options)`
     * (ExVcchMz). DISTINCT from {@see validateVerbOptions()} with verb `convert`,
     * which is for the file-first `Recipe::convert(format, options)` where
     * `output_format` is set by the positional `$format` arg and is therefore
     * positional-owned (rejected in the bag). The single-op builder has NO
     * positional format — its target rides the bag as the wire key `output_format`
     * — so this guard ALLOWS `output_format` (and only that; the SDK alias `format`
     * is NOT accepted, since the single-op bag lowers verbatim to the wire) while
     * still rejecting any other unknown key, AND requires `output_format` present
     * (a convert with no target is a guaranteed server 422). Mirrors the TS
     * `validateSingleOpConvertOptions`.
     *
     * @param array<string, mixed> $options
     *
     * @throws GislConfigError reason `unknown_field` for a key outside the convert
     *                         contract set ∪ {output_format}; reason
     *                         `missing_required_field` when `output_format` is absent/null.
     */
    public static function validateSingleOpConvertOptions(array $options): void
    {
        $allowed = self::allowedKeysFor('convert');
        $allowed['output_format'] = true;
        foreach (array_keys($options) as $key) {
            if (!isset($allowed[$key])) {
                $valid = array_keys($allowed);
                sort($valid);
                throw new GislConfigError(
                    sprintf("convert: unknown option '%s'. Valid options: %s.", (string) $key, implode(', ', $valid)),
                    'unknown_field',
                    [(string) $key],
                );
            }
        }
        if (!array_key_exists('output_format', $options) || $options['output_format'] === null) {
            throw new GislConfigError(
                "convert requires 'output_format' (the target format) in the options bag; "
                . "e.g. \$client->convert(\$input, ['output_format' => 'webp']).",
                'missing_required_field',
                ['output_format'],
            );
        }
    }

    /**
     * Assert thumbnail `width` AND `height` are both present and non-null (the
     * contract marks both `required`). PHP drops null/absent values before
     * lowering, so a missing OR null dimension would otherwise slip through —
     * `array_key_exists` alone is insufficient, the value must be non-null.
     * Mirrors the TS `assertThumbnailDimensions`.
     *
     * @param array<string, mixed> $options
     *
     * @throws GislConfigError reason `missing_required_field` naming the absent
     *                         dimension(s).
     */
    public static function assertThumbnailDimensions(array $options): void
    {
        $missing = [];
        if (!array_key_exists('width', $options) || $options['width'] === null) {
            $missing[] = 'width';
        }
        if (!array_key_exists('height', $options) || $options['height'] === null) {
            $missing[] = 'height';
        }
        if ($missing !== []) {
            throw new GislConfigError(
                sprintf('thumbnail requires both width and height (the contract marks both required); missing: %s.', implode(', ', $missing)),
                'missing_required_field',
                $missing,
            );
        }
    }
}
