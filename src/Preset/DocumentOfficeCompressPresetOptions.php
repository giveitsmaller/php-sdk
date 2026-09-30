<?php

declare(strict_types=1);

namespace Gisl\Sdk\Preset;

use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Generated\SdkSpec\Presets;

/**
 * Office-document-compress preset leaf DTO — sparse delta. Mirrors the TS
 * `DocumentOfficeCompressPresetOptions` (T4a). Field set: stripMacros,
 * stripHiddenData, stripUnusedFonts. No enum-typed fields.
 * `quality` (1-100) is the one STABLE document compress option (f3JiTxkK);
 * it is the LAST constructor parameter so positional callers are unaffected.
 */
final class DocumentOfficeCompressPresetOptions
{
    public function __construct(
        public readonly ?bool $stripMacros = null,
        public readonly ?bool $stripHiddenData = null,
        public readonly ?bool $stripUnusedFonts = null,
        /** Compression quality 1-100 (contract default 50) — the one stable document compress option. */
        public readonly ?int $quality = null,
    ) {
    }

    public static function shippedDefaultsFor(OptimizeFor $level): self
    {
        $cell = Presets::shippedDefaultsFor('document_office_compress', $level);

        return new self(
            stripMacros: PresetCellTranslator::bool($cell, 'stripMacros'),
            stripHiddenData: PresetCellTranslator::bool($cell, 'stripHiddenData'),
            stripUnusedFonts: PresetCellTranslator::bool($cell, 'stripUnusedFonts'),
            quality: PresetCellTranslator::int($cell, 'quality'),
        );
    }
}
