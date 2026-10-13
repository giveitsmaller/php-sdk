<?php

declare(strict_types=1);

namespace Gisl\Sdk\Preset;

use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Generated\SdkSpec\Presets;

/**
 * PDF-document-compress preset leaf DTO — sparse delta. Mirrors the TS
 * `DocumentPdfCompressPresetOptions` (oavTfC6C). Field set per contracts
 * compress.yaml `document_pdf` (beta): quality, maxImageDimension. Both act on
 * the IMAGES inside the PDF; text and vector content is not re-encoded.
 * No enum-typed fields.
 */
final class DocumentPdfCompressPresetOptions
{
    public function __construct(
        /** JPEG quality 1-100 for images inside the PDF (contract default 70). */
        public readonly ?int $quality = null,
        /** Longest side in px for images inside the PDF, 64-10000 (contract default 1600). */
        public readonly ?int $maxImageDimension = null,
    ) {
    }

    public static function shippedDefaultsFor(OptimizeFor $level): self
    {
        $cell = Presets::shippedDefaultsFor('document_pdf_compress', $level);

        return new self(
            quality: PresetCellTranslator::int($cell, 'quality'),
            maxImageDimension: PresetCellTranslator::int($cell, 'maxImageDimension'),
        );
    }
}
