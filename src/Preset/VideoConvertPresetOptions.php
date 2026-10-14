<?php

declare(strict_types=1);

namespace Gisl\Sdk\Preset;

use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Generated\SdkSpec\Presets;

/**
 * Video-convert preset leaf DTO — sparse delta. Mirrors the TS
 * `VideoConvertPresetOptions` (f5ti3pik). Field set per contracts convert.yaml `video`: crf only
 * (convert.video has no `preset`).
 * No enum-typed fields.
 *
 * NOT YET CONSUMED: PresetDefaults and optimize do not read this class;
 * cellFor/merge stay compress-only. The wiring is card uUSSVNJA.
 *
 * DRAFT VALUES: the shipped cells are DRAFT per contracts v2.234.0 —
 * "a coherent ladder, not measured".
 *
 * GIF: an explicit `crf` on a `gif` output is REJECTED by the contract
 * (invalid_options at workflow-create). The uUSSVNJA resolver must drop
 * `crf` when the output format is gif; this DTO carries it unconditionally.
 */
final class VideoConvertPresetOptions
{
    public function __construct(
        /** Constant Rate Factor 0-51, lower = better (draft ladder 30 / 23 / 18). */
        public readonly ?int $crf = null,
    ) {
    }

    public static function shippedDefaultsFor(OptimizeFor $level): self
    {
        $cell = Presets::shippedDefaultsFor('video_convert', $level);

        return new self(
            crf: PresetCellTranslator::int($cell, 'crf'),
        );
    }
}
