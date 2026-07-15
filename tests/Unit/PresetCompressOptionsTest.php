<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Generated\SdkSpec\Enums\AudioBitrate;
use Gisl\Sdk\Generated\SdkSpec\Enums\AudioSampleRate;
use Gisl\Sdk\Generated\SdkSpec\Enums\ImageFormat;
use Gisl\Sdk\Generated\SdkSpec\Enums\ImageMetadataPolicy;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Generated\SdkSpec\Enums\VideoPreset;
use Gisl\Sdk\Generated\SdkSpec\Presets;
use Gisl\Sdk\Preset\AudioCompressPresetOptions;
use Gisl\Sdk\Preset\DocumentEpubCompressPresetOptions;
use Gisl\Sdk\Preset\DocumentOdfCompressPresetOptions;
use Gisl\Sdk\Preset\DocumentOfficeCompressPresetOptions;
use Gisl\Sdk\Preset\ImageCompressPresetOptions;
use Gisl\Sdk\Preset\PresetCellTranslator;
use Gisl\Sdk\Preset\VideoCompressPresetOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PresetCellTranslator::class)]
#[CoversClass(ImageCompressPresetOptions::class)]
#[CoversClass(AudioCompressPresetOptions::class)]
#[CoversClass(VideoCompressPresetOptions::class)]
#[CoversClass(DocumentOfficeCompressPresetOptions::class)]
#[CoversClass(DocumentOdfCompressPresetOptions::class)]
#[CoversClass(DocumentEpubCompressPresetOptions::class)]
final class PresetCompressOptionsTest extends TestCase
{
    // -----------------------------------------------------------------
    // PresetCellTranslator
    // -----------------------------------------------------------------

    public function testCaseByNameResolvesStringBackedEnum(): void
    {
        $this->assertSame(ImageMetadataPolicy::All, PresetCellTranslator::caseByName(ImageMetadataPolicy::class, 'All'));
    }

    public function testCaseByNameResolvesIntBackedEnumByName(): void
    {
        // Cells store the canonical NAME '_96', not the int wire value 96 —
        // so name-matching (not ::from) is required for int-backed enums.
        $this->assertSame(AudioBitrate::_96, PresetCellTranslator::caseByName(AudioBitrate::class, '_96'));
    }

    public function testCaseByNameThrowsOnUnknownName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'Nope' is not a case of");
        PresetCellTranslator::caseByName(ImageMetadataPolicy::class, 'Nope');
    }

    public function testCellReadersReturnNullWhenAbsent(): void
    {
        $this->assertNull(PresetCellTranslator::enum([], 'metadata', ImageMetadataPolicy::class));
        $this->assertNull(PresetCellTranslator::int([], 'quality'));
        $this->assertNull(PresetCellTranslator::bool([], 'normalize'));
    }

    public function testEnumReaderThrowsOnNonStringCellValue(): void
    {
        // An int leaking into an enum-typed field is a generator-invariant
        // violation and must fail loudly (distinct from caseByName's
        // unknown-name InvalidArgumentException).
        $this->expectException(\LogicException::class);
        PresetCellTranslator::enum(['metadata' => 96], 'metadata', ImageMetadataPolicy::class);
    }

    public function testIntReaderThrowsOnNonInt(): void
    {
        $this->expectException(\LogicException::class);
        PresetCellTranslator::int(['quality' => 'oops'], 'quality');
    }

    public function testBoolReaderThrowsOnNonBool(): void
    {
        $this->expectException(\LogicException::class);
        PresetCellTranslator::bool(['normalize' => 1], 'normalize');
    }

    // -----------------------------------------------------------------
    // Leaf DTOs — named-arg construction (sparse)
    // -----------------------------------------------------------------

    public function testLeafDtoNamedArgConstructionIsSparse(): void
    {
        $dto = new ImageCompressPresetOptions(quality: 75);
        $this->assertSame(75, $dto->quality);
        // Unset fields stay null (sparse delta).
        $this->assertNull($dto->metadata);
        $this->assertNull($dto->outputFormat);
    }

    // -----------------------------------------------------------------
    // shippedDefaultsFor — values come from the generated PRESETS matrix
    // -----------------------------------------------------------------

    public function testImageShippedDefaultsForSize(): void
    {
        // v2.80.0 honesty pass (lossy-only): the cell ships only quality /
        // metadata / outputFormat. `mode` + `iccProfile` were removed.
        $dto = ImageCompressPresetOptions::shippedDefaultsFor(OptimizeFor::Size);
        $this->assertSame(65, $dto->quality);
        $this->assertSame(ImageMetadataPolicy::Strip, $dto->metadata);
        // VcPeRWdD (contracts v2.73.0): Size outputFormat re-pointed Smallest -> Original
        // (`smallest` is now per_value_availability:planned — facade self-422 guard).
        $this->assertSame(ImageFormat::Original, $dto->outputFormat);
    }

    public function testImageShippedDefaultsForQualityShipsQuality(): void
    {
        // v2.80.0 honesty pass: the worker is lossy-only, so the Quality cell
        // now ships a concrete quality:92 (was previously omitted under the
        // removed mode:lossless gate).
        $dto = ImageCompressPresetOptions::shippedDefaultsFor(OptimizeFor::Quality);
        $this->assertSame(92, $dto->quality);
        $this->assertSame(ImageMetadataPolicy::Strip, $dto->metadata);
        $this->assertSame(ImageFormat::Original, $dto->outputFormat);
    }

    public function testAudioShippedDefaultsForSize(): void
    {
        $dto = AudioCompressPresetOptions::shippedDefaultsFor(OptimizeFor::Size);
        $this->assertSame(AudioBitrate::_96, $dto->bitrate);
        $this->assertSame(AudioSampleRate::_44100, $dto->sampleRate);
        $this->assertTrue($dto->normalize);
        $this->assertNull($dto->channels);
    }

    public function testVideoShippedDefaultsForSize(): void
    {
        $dto = VideoCompressPresetOptions::shippedDefaultsFor(OptimizeFor::Size);
        $this->assertSame(30, $dto->crf);
        $this->assertSame(VideoPreset::Slow, $dto->preset);
        // v2.71.0 (rza1htNO): audioBitrate dropped from video_compress presets
        // (audio re-encode is opt-in — the worker rejects audio_bitrate + the
        // default `copy` audio_codec). The int-backed enum() reader is still
        // exercised end-to-end by the audio `bitrate` cell test above.
        $this->assertNull($dto->audioBitrate);
        // v2.66.0 (contracts ADR-0020): presets no longer bake codec /
        // audioCodec / faststart — the server container-resolves those so a
        // WebM target cannot 422 on a baked MP4-oriented codec.
        $this->assertNull($dto->codec);
        $this->assertNull($dto->faststart);
        $this->assertNull($dto->audioCodec);
        // Per-call / sparse knobs absent from the cell stay null.
        $this->assertNull($dto->targetSize);
        $this->assertNull($dto->width);
        $this->assertNull($dto->height);
        $this->assertNull($dto->fit);
        $this->assertNull($dto->fps);
    }

    public function testOfficeShippedDefaultsForSize(): void
    {
        $dto = DocumentOfficeCompressPresetOptions::shippedDefaultsFor(OptimizeFor::Size);
        $this->assertTrue($dto->stripMacros);
        $this->assertTrue($dto->stripHiddenData);
        $this->assertTrue($dto->stripUnusedFonts);
    }

    public function testOdfShippedDefaultsForSize(): void
    {
        $dto = DocumentOdfCompressPresetOptions::shippedDefaultsFor(OptimizeFor::Size);
        $this->assertTrue($dto->stripMetadata);
        $this->assertTrue($dto->stripUnusedStyles);
    }

    public function testEpubShippedDefaultsForSize(): void
    {
        $dto = DocumentEpubCompressPresetOptions::shippedDefaultsFor(OptimizeFor::Size);
        $this->assertTrue($dto->fontSubsetting);
        $this->assertTrue($dto->stripUnusedCss);
    }

    /**
     * Drift guard: the DTO's enum fields must carry the SAME canonical name
     * the generated PRESETS cell stores — proving shippedDefaultsFor reads
     * the generated lookup rather than hardcoding values.
     */
    public function testShippedDefaultsAreDerivedFromGeneratedLookup(): void
    {
        foreach (OptimizeFor::cases() as $level) {
            $cell = Presets::shippedDefaultsFor('image_compress', $level);
            $dto = ImageCompressPresetOptions::shippedDefaultsFor($level);

            if (\array_key_exists('metadata', $cell)) {
                $this->assertNotNull($dto->metadata);
                $this->assertSame($cell['metadata'], $dto->metadata->name);
            }
            if (\array_key_exists('outputFormat', $cell)) {
                $this->assertNotNull($dto->outputFormat);
                $this->assertSame($cell['outputFormat'], $dto->outputFormat->name);
            }
            // v2.80.0: lossy-only, so every level ships a concrete quality.
            $this->assertArrayHasKey('quality', $cell);
            $this->assertSame($cell['quality'], $dto->quality);
        }
    }
}
