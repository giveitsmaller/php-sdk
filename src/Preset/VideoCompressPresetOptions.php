<?php

declare(strict_types=1);

namespace Gisl\Sdk\Preset;

use Gisl\Sdk\Generated\SdkSpec\Enums\AudioBitrate;
use Gisl\Sdk\Generated\SdkSpec\Enums\AudioCodec;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Generated\SdkSpec\Enums\VideoCodec;
use Gisl\Sdk\Generated\SdkSpec\Enums\VideoFit;
use Gisl\Sdk\Generated\SdkSpec\Enums\VideoPreset;
use Gisl\Sdk\Generated\SdkSpec\Presets;

/**
 * Video-compress preset leaf DTO — sparse delta. Mirrors the TS
 * `VideoCompressPresetOptions` (T4a). Field set: codec, targetSize, crf,
 * preset, width, height, fit, fps, faststart, audioCodec, audioBitrate.
 *
 * `targetSize` is `string|int|null` (e.g. "8MB" or a byte count) and is a
 * per-call knob — it never appears in a shipped preset cell, so
 * shippedDefaultsFor leaves it null.
 */
final class VideoCompressPresetOptions
{
    public function __construct(
        public readonly ?VideoCodec $codec = null,
        /**
         * Target output size ("50MB"-style string — BINARY units, 1 KB = 1024 — or a
         * byte count). Derived by the resolver into `target_size_bytes` +
         * `encoding_mode: 'target_size'`.
         *
         * NOT AVAILABLE FOR LONG INPUTS. A compress whose input duration routes to the
         * long-form path rejects it (reject_long_form_target_size): that path is
         * single-pass-CRF by construction and two-pass target-size is unbuilt. The
         * request fails during execution, and the SDK cannot warn earlier — routing is
         * decided server-side at create-plan time. Short-form compresses honour it
         * normally. Tracked by zJN6XIi5. Same limit applies to MergeOptions::$targetSize.
         */
        public readonly string|int|null $targetSize = null,
        public readonly ?int $crf = null,
        public readonly ?VideoPreset $preset = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?VideoFit $fit = null,
        public readonly ?int $fps = null,
        public readonly ?bool $faststart = null,
        public readonly ?AudioCodec $audioCodec = null,
        public readonly ?AudioBitrate $audioBitrate = null,
    ) {
    }

    public static function shippedDefaultsFor(OptimizeFor $level): self
    {
        $cell = Presets::shippedDefaultsFor('video_compress', $level);

        return new self(
            codec: PresetCellTranslator::enum($cell, 'codec', VideoCodec::class),
            crf: PresetCellTranslator::int($cell, 'crf'),
            preset: PresetCellTranslator::enum($cell, 'preset', VideoPreset::class),
            width: PresetCellTranslator::int($cell, 'width'),
            height: PresetCellTranslator::int($cell, 'height'),
            fit: PresetCellTranslator::enum($cell, 'fit', VideoFit::class),
            fps: PresetCellTranslator::int($cell, 'fps'),
            faststart: PresetCellTranslator::bool($cell, 'faststart'),
            audioCodec: PresetCellTranslator::enum($cell, 'audioCodec', AudioCodec::class),
            audioBitrate: PresetCellTranslator::enum($cell, 'audioBitrate', AudioBitrate::class),
        );
    }
}
