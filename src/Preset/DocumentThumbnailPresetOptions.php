<?php

declare(strict_types=1);

namespace Gisl\Sdk\Preset;

use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Generated\SdkSpec\Presets;

/**
 * Document-thumbnail preset leaf DTO — sparse delta. Mirrors the TS
 * `DocumentThumbnailPresetOptions` (f5ti3pik). Field set per contracts thumbnail.yaml `document`: width,
 * quality (`format` deliberately unset).
 * No enum-typed fields.
 *
 * NOT YET CONSUMED: PresetDefaults and optimize do not read this class;
 * cellFor/merge stay compress-only. The wiring is card uUSSVNJA.
 *
 * DRAFT VALUES: the shipped cells are DRAFT per contracts v2.234.0 —
 * "a coherent ladder, not measured".
 */
final class DocumentThumbnailPresetOptions
{
    public function __construct(
        /** Target width in px, 1-16384 (draft ladder 160 / 320 / 640). */
        public readonly ?int $width = null,
        /** Output quality 1-100 for the thumbnail (draft ladder 70 / 85 / 92). */
        public readonly ?int $quality = null,
    ) {
    }

    public static function shippedDefaultsFor(OptimizeFor $level): self
    {
        $cell = Presets::shippedDefaultsFor('document_thumbnail', $level);

        return new self(
            width: PresetCellTranslator::int($cell, 'width'),
            quality: PresetCellTranslator::int($cell, 'quality'),
        );
    }
}
