<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Preset\AudioCompressPresetOptions;
use Gisl\Sdk\Preset\DocumentEpubCompressPresetOptions;
use Gisl\Sdk\Preset\DocumentOdfCompressPresetOptions;
use Gisl\Sdk\Preset\DocumentOfficeCompressPresetOptions;
use Gisl\Sdk\Generated\SdkSpec\Enums\ImageFormat;
use Gisl\Sdk\Preset\ImageCompressPresetOptions;
use Gisl\Sdk\Preset\VideoCompressPresetOptions;
use Gisl\Sdk\PresetDefaults;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PresetDefaults::class)]
final class PresetDefaultsTest extends TestCase
{
    public function testCreateStartsEmpty(): void
    {
        $defaults = PresetDefaults::create();
        $this->assertNull($defaults->cellFor('image_compress', OptimizeFor::Size));
    }

    public function testPerCellMethodsAreImmutable(): void
    {
        $base = PresetDefaults::create();
        $a = $base->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 75));
        $b = $base->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 90));

        // Same method twice on the same instance yields distinct objects.
        $this->assertNotSame($a, $b);
        $this->assertNotSame($base, $a);

        // The base is untouched by the derive calls.
        $this->assertNull($base->cellFor('image_compress', OptimizeFor::Size));

        $aCell = $a->cellFor('image_compress', OptimizeFor::Size);
        $bCell = $b->cellFor('image_compress', OptimizeFor::Size);
        $this->assertInstanceOf(ImageCompressPresetOptions::class, $aCell);
        $this->assertInstanceOf(ImageCompressPresetOptions::class, $bCell);
        $this->assertSame(75, $aCell->quality);
        $this->assertSame(90, $bCell->quality);
    }

    public function testRegistrationsAccumulateAcrossCells(): void
    {
        $defaults = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 70))
            ->videoCompress(OptimizeFor::Quality);

        $this->assertInstanceOf(
            ImageCompressPresetOptions::class,
            $defaults->cellFor('image_compress', OptimizeFor::Size),
        );
        $this->assertInstanceOf(
            VideoCompressPresetOptions::class,
            $defaults->cellFor('video_compress', OptimizeFor::Quality),
        );
        // A level that was never registered for video stays null.
        $this->assertNull($defaults->cellFor('video_compress', OptimizeFor::Size));
    }

    public function testEmptyInputRegistersAnEmptyDelta(): void
    {
        // Calling with no input registers an empty delta (distinct from "not
        // registered") — the resolver reads it as "apply shipped defaults".
        $defaults = PresetDefaults::create()->videoCompress(OptimizeFor::Balanced);

        $cell = $defaults->cellFor('video_compress', OptimizeFor::Balanced);
        $this->assertInstanceOf(VideoCompressPresetOptions::class, $cell);
        $this->assertNull($cell->codec);
        $this->assertNull($cell->crf);
    }

    public function testCellForDistinguishesLevels(): void
    {
        $defaults = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 60));

        $this->assertNotNull($defaults->cellFor('image_compress', OptimizeFor::Size));
        $this->assertNull($defaults->cellFor('image_compress', OptimizeFor::Balanced));
        $this->assertNull($defaults->cellFor('image_compress', OptimizeFor::Quality));
    }

    // -----------------------------------------------------------------
    // merge (P7 / 5k3ZWo6B)
    // -----------------------------------------------------------------

    public function testMergeFieldWiseChildWinsParentFillsGaps(): void
    {
        $parent = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 70, outputFormat: ImageFormat::Webp));
        $child = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 92));

        $merged = PresetDefaults::merge($parent, $child);
        $cell = $merged->cellFor('image_compress', OptimizeFor::Size);

        $this->assertInstanceOf(ImageCompressPresetOptions::class, $cell);
        $this->assertSame(92, $cell->quality);                  // child wins
        $this->assertSame(ImageFormat::Webp, $cell->outputFormat); // parent fills gap
    }

    public function testMergeTakesChildOnlyAndParentOnlyCellsVerbatim(): void
    {
        $parent = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 70));
        $child = PresetDefaults::create()
            ->videoCompress(OptimizeFor::Quality, new VideoCompressPresetOptions(crf: 18));

        $merged = PresetDefaults::merge($parent, $child);

        // Different (cellKey, level) entries coexist untouched.
        $img = $merged->cellFor('image_compress', OptimizeFor::Size);
        $vid = $merged->cellFor('video_compress', OptimizeFor::Quality);
        $this->assertInstanceOf(ImageCompressPresetOptions::class, $img);
        $this->assertSame(70, $img->quality);
        $this->assertInstanceOf(VideoCompressPresetOptions::class, $vid);
        $this->assertSame(18, $vid->crf);
    }

    public function testMergeLeavesParentAndChildUnchanged(): void
    {
        $parent = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 70));
        $child = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 92));

        PresetDefaults::merge($parent, $child);

        $this->assertSame(70, $parent->cellFor('image_compress', OptimizeFor::Size)?->quality);
        $this->assertSame(92, $child->cellFor('image_compress', OptimizeFor::Size)?->quality);
    }

    /**
     * Every one of the 6 mergeCell arms is exercised: parent sets field A,
     * child sets a different field B on an overlapping (cell, level); the merged
     * cell must carry child's B AND parent's A (field-wise child-wins/parent-fills).
     */
    public function testMergeCoversAllSixLeafTypes(): void
    {
        $level = OptimizeFor::Size;

        $parent = PresetDefaults::create()
            ->imageCompress($level, new ImageCompressPresetOptions(outputFormat: ImageFormat::Webp))
            ->audioCompress($level, new AudioCompressPresetOptions(normalize: true))
            ->videoCompress($level, new VideoCompressPresetOptions(fps: 24))
            ->officeCompress($level, new DocumentOfficeCompressPresetOptions(stripMacros: true))
            ->odfCompress($level, new DocumentOdfCompressPresetOptions(stripMetadata: true))
            ->epubCompress($level, new DocumentEpubCompressPresetOptions(fontSubsetting: true));

        $child = PresetDefaults::create()
            ->imageCompress($level, new ImageCompressPresetOptions(quality: 92))
            ->audioCompress($level, new AudioCompressPresetOptions(channels: 2))
            ->videoCompress($level, new VideoCompressPresetOptions(crf: 18))
            ->officeCompress($level, new DocumentOfficeCompressPresetOptions(stripHiddenData: true))
            ->odfCompress($level, new DocumentOdfCompressPresetOptions(stripUnusedStyles: true))
            ->epubCompress($level, new DocumentEpubCompressPresetOptions(stripUnusedCss: true));

        $m = PresetDefaults::merge($parent, $child);

        $img = $m->cellFor('image_compress', $level);
        $this->assertInstanceOf(ImageCompressPresetOptions::class, $img);
        $this->assertSame(92, $img->quality);              // child
        $this->assertSame(ImageFormat::Webp, $img->outputFormat); // parent

        $aud = $m->cellFor('audio_compress', $level);
        $this->assertInstanceOf(AudioCompressPresetOptions::class, $aud);
        $this->assertSame(2, $aud->channels);          // child
        $this->assertTrue($aud->normalize);            // parent

        $vid = $m->cellFor('video_compress', $level);
        $this->assertInstanceOf(VideoCompressPresetOptions::class, $vid);
        $this->assertSame(18, $vid->crf);              // child
        $this->assertSame(24, $vid->fps);              // parent

        $office = $m->cellFor('document_office_compress', $level);
        $this->assertInstanceOf(DocumentOfficeCompressPresetOptions::class, $office);
        $this->assertTrue($office->stripHiddenData);   // child
        $this->assertTrue($office->stripMacros);       // parent

        $odf = $m->cellFor('document_odf_compress', $level);
        $this->assertInstanceOf(DocumentOdfCompressPresetOptions::class, $odf);
        $this->assertTrue($odf->stripUnusedStyles);    // child
        $this->assertTrue($odf->stripMetadata);        // parent

        $epub = $m->cellFor('document_epub_compress', $level);
        $this->assertInstanceOf(DocumentEpubCompressPresetOptions::class, $epub);
        $this->assertTrue($epub->stripUnusedCss);      // child
        $this->assertTrue($epub->fontSubsetting);      // parent
    }
}
