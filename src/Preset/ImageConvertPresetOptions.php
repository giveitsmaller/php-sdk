<?php

declare(strict_types=1);

namespace Gisl\Sdk\Preset;

use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Generated\SdkSpec\Presets;

/**
 * Image-convert preset leaf DTO — sparse delta. Mirrors the TS
 * `ImageConvertPresetOptions` (f5ti3pik). Field set per contracts convert.yaml `image`: quality,
 * honoured on lossy targets (jpeg/webp/avif) only.
 * No enum-typed fields.
 *
 * NOT YET CONSUMED: PresetDefaults and optimize do not read this class;
 * cellFor/merge stay compress-only. The wiring is card uUSSVNJA.
 *
 * DRAFT VALUES: the shipped cells are DRAFT per contracts v2.234.0 —
 * "a coherent ladder, not measured".
 */
final class ImageConvertPresetOptions
{
    public function __construct(
        /** Output quality 1-100 for lossy targets (draft ladder 65 / 80 / 92). */
        public readonly ?int $quality = null,
    ) {
    }

    public static function shippedDefaultsFor(OptimizeFor $level): self
    {
        $cell = Presets::shippedDefaultsFor('image_convert', $level);

        return new self(
            quality: PresetCellTranslator::int($cell, 'quality'),
        );
    }
}
