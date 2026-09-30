<?php

declare(strict_types=1);

namespace Gisl\Sdk\Preset;

use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Generated\SdkSpec\Presets;

/**
 * ODF-document-compress preset leaf DTO — sparse delta. Mirrors the TS
 * `DocumentOdfCompressPresetOptions` (T4a). Field set: stripMetadata,
 * stripUnusedStyles. No enum-typed fields.
 * `quality` (1-100) is the one STABLE document compress option (f3JiTxkK);
 * it is the LAST constructor parameter so positional callers are unaffected.
 */
final class DocumentOdfCompressPresetOptions
{
    public function __construct(
        public readonly ?bool $stripMetadata = null,
        public readonly ?bool $stripUnusedStyles = null,
        /** Compression quality 1-100 (contract default 50) — the one stable document compress option. */
        public readonly ?int $quality = null,
    ) {
    }

    public static function shippedDefaultsFor(OptimizeFor $level): self
    {
        $cell = Presets::shippedDefaultsFor('document_odf_compress', $level);

        return new self(
            stripMetadata: PresetCellTranslator::bool($cell, 'stripMetadata'),
            stripUnusedStyles: PresetCellTranslator::bool($cell, 'stripUnusedStyles'),
            quality: PresetCellTranslator::int($cell, 'quality'),
        );
    }
}
