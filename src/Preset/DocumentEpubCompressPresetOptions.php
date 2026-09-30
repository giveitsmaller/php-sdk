<?php

declare(strict_types=1);

namespace Gisl\Sdk\Preset;

use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Generated\SdkSpec\Presets;

/**
 * EPUB-document-compress preset leaf DTO — sparse delta. Mirrors the TS
 * `DocumentEpubCompressPresetOptions` (T4a). Field set: fontSubsetting,
 * stripUnusedCss. No enum-typed fields.
 * `quality` (1-100) is the one STABLE document compress option (f3JiTxkK);
 * it is the LAST constructor parameter so positional callers are unaffected.
 */
final class DocumentEpubCompressPresetOptions
{
    public function __construct(
        public readonly ?bool $fontSubsetting = null,
        public readonly ?bool $stripUnusedCss = null,
        /** Compression quality 1-100 (contract default 50) — the one stable document compress option. */
        public readonly ?int $quality = null,
    ) {
    }

    public static function shippedDefaultsFor(OptimizeFor $level): self
    {
        $cell = Presets::shippedDefaultsFor('document_epub_compress', $level);

        return new self(
            fontSubsetting: PresetCellTranslator::bool($cell, 'fontSubsetting'),
            stripUnusedCss: PresetCellTranslator::bool($cell, 'stripUnusedCss'),
            quality: PresetCellTranslator::int($cell, 'quality'),
        );
    }
}
