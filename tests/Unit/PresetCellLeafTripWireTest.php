<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Generated\SdkSpec\Presets;
use Gisl\Sdk\Preset\AudioCompressPresetOptions;
use Gisl\Sdk\Preset\DocumentEpubCompressPresetOptions;
use Gisl\Sdk\Preset\DocumentOdfCompressPresetOptions;
use Gisl\Sdk\Preset\DocumentOfficeCompressPresetOptions;
use Gisl\Sdk\Preset\DocumentPdfCompressPresetOptions;
use Gisl\Sdk\Preset\DocumentThumbnailPresetOptions;
use Gisl\Sdk\Preset\ImageCompressPresetOptions;
use Gisl\Sdk\Preset\ImageConvertPresetOptions;
use Gisl\Sdk\Preset\ImageThumbnailPresetOptions;
use Gisl\Sdk\Preset\VideoCompressPresetOptions;
use Gisl\Sdk\Preset\VideoConvertPresetOptions;
use Gisl\Sdk\Preset\VideoThumbnailPresetOptions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * PHP twin of the TS `PRESETS regen drift trip-wire` (presets.test.ts).
 *
 * Iterates the GENERATED `Presets::PRESETS` keys — not a hand list — so a
 * re-vendor that adds a cell fails here until a leaf DTO is wired, and a leaf
 * whose shippedDefaultsFor drops a generated field fails too.
 */
#[CoversNothing]
final class PresetCellLeafTripWireTest extends TestCase
{
    /**
     * @return array<string, callable(OptimizeFor): object>
     */
    private static function cellToLeaf(): array
    {
        return [
            'image_compress' => ImageCompressPresetOptions::shippedDefaultsFor(...),
            'audio_compress' => AudioCompressPresetOptions::shippedDefaultsFor(...),
            'video_compress' => VideoCompressPresetOptions::shippedDefaultsFor(...),
            'document_office_compress' => DocumentOfficeCompressPresetOptions::shippedDefaultsFor(...),
            'document_odf_compress' => DocumentOdfCompressPresetOptions::shippedDefaultsFor(...),
            'document_epub_compress' => DocumentEpubCompressPresetOptions::shippedDefaultsFor(...),
            'document_pdf_compress' => DocumentPdfCompressPresetOptions::shippedDefaultsFor(...),
            'image_convert' => ImageConvertPresetOptions::shippedDefaultsFor(...),
            'video_convert' => VideoConvertPresetOptions::shippedDefaultsFor(...),
            'image_thumbnail' => ImageThumbnailPresetOptions::shippedDefaultsFor(...),
            'video_thumbnail' => VideoThumbnailPresetOptions::shippedDefaultsFor(...),
            'document_thumbnail' => DocumentThumbnailPresetOptions::shippedDefaultsFor(...),
        ];
    }

    /**
     * Every problem found, as human-readable lines. Empty means clean.
     *
     * @param array<string, array<string, array<string, string|int|bool>>> $matrix
     * @param array<string, callable(OptimizeFor): object>                 $leaves
     *
     * @return list<string>
     */
    private static function problems(array $matrix, array $leaves): array
    {
        $problems = [];
        foreach ($matrix as $cellKey => $group) {
            $leaf = $leaves[$cellKey] ?? null;
            if ($leaf === null) {
                $problems[] = "{$cellKey}: no leaf DTO";
                continue;
            }
            foreach (OptimizeFor::cases() as $level) {
                $cell = $group[$level->value] ?? null;
                if ($cell === null) {
                    $problems[] = "{$cellKey}.{$level->value}: no generated cell";
                    continue;
                }
                $dto = $leaf($level);
                foreach (\array_keys($cell) as $field) {
                    if (!\property_exists($dto, $field)) {
                        $problems[] = "{$cellKey}.{$level->value}.{$field}: not a property of " . $dto::class;
                    } elseif ($dto->{$field} === null) {
                        $problems[] = "{$cellKey}.{$level->value}.{$field}: dropped by shippedDefaultsFor";
                    }
                }
            }
        }

        return $problems;
    }

    public function testGeneratedMatrixIsNonEmpty(): void
    {
        // Positive control on the population: an empty matrix would pass
        // every assertion below vacuously.
        $this->assertGreaterThanOrEqual(12, \count(Presets::PRESETS));
    }

    public function testEveryGeneratedCellHasALeafAndEveryFieldSurvives(): void
    {
        $this->assertSame([], self::problems(Presets::PRESETS, self::cellToLeaf()));
    }

    public function testNegativeControlCatchesAMissingLeafAndADroppedField(): void
    {
        $leaves = self::cellToLeaf();
        unset($leaves['video_convert']);
        // A leaf that silently drops `quality` from every image_thumbnail cell.
        $leaves['image_thumbnail'] = static fn (OptimizeFor $level): object => new ImageThumbnailPresetOptions(
            width: ImageThumbnailPresetOptions::shippedDefaultsFor($level)->width,
        );
        // A leaf whose class has no property for a generated field.
        $matrix = Presets::PRESETS;
        $matrix['document_thumbnail']['Size']['notAField'] = 1;

        $problems = self::problems($matrix, $leaves);

        $this->assertContains('video_convert: no leaf DTO', $problems);
        $this->assertContains('image_thumbnail.Size.quality: dropped by shippedDefaultsFor', $problems);
        $this->assertContains('image_thumbnail.Quality.quality: dropped by shippedDefaultsFor', $problems);
        $this->assertContains(
            'document_thumbnail.Size.notAField: not a property of ' . DocumentThumbnailPresetOptions::class,
            $problems,
        );
        $this->assertCount(5, $problems);
    }
}
