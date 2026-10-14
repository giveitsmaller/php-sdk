<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Preset\DocumentThumbnailPresetOptions;
use Gisl\Sdk\Preset\ImageConvertPresetOptions;
use Gisl\Sdk\Preset\ImageThumbnailPresetOptions;
use Gisl\Sdk\Preset\VideoConvertPresetOptions;
use Gisl\Sdk\Preset\VideoThumbnailPresetOptions;
use Gisl\Sdk\PresetDefaults;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Convert / thumbnail leaf DTOs (f5ti3pik). The values are DRAFT per
 * contracts v2.234.0 ("a coherent ladder, not measured"); the classes are
 * exported only — PresetDefaults and optimize do not consume them until
 * uUSSVNJA.
 */
#[CoversClass(ImageConvertPresetOptions::class)]
#[CoversClass(VideoConvertPresetOptions::class)]
#[CoversClass(ImageThumbnailPresetOptions::class)]
#[CoversClass(VideoThumbnailPresetOptions::class)]
#[CoversClass(DocumentThumbnailPresetOptions::class)]
final class PresetConvertThumbnailOptionsTest extends TestCase
{
    public function testImageConvertShipsQualityLadder(): void
    {
        $this->assertSame(
            [65, 80, 92],
            \array_map(
                static fn (OptimizeFor $l): ?int => ImageConvertPresetOptions::shippedDefaultsFor($l)->quality,
                [OptimizeFor::Size, OptimizeFor::Balanced, OptimizeFor::Quality],
            ),
        );
    }

    public function testVideoConvertShipsCrfLadder(): void
    {
        $this->assertSame(
            [30, 23, 18],
            \array_map(
                static fn (OptimizeFor $l): ?int => VideoConvertPresetOptions::shippedDefaultsFor($l)->crf,
                [OptimizeFor::Size, OptimizeFor::Balanced, OptimizeFor::Quality],
            ),
        );
    }

    /**
     * @return iterable<string, array{callable(OptimizeFor): (ImageThumbnailPresetOptions|VideoThumbnailPresetOptions|DocumentThumbnailPresetOptions)}>
     */
    public static function thumbnailLeaves(): iterable
    {
        yield 'image' => [ImageThumbnailPresetOptions::shippedDefaultsFor(...)];
        yield 'video' => [VideoThumbnailPresetOptions::shippedDefaultsFor(...)];
        yield 'document' => [DocumentThumbnailPresetOptions::shippedDefaultsFor(...)];
    }

    /**
     * @param callable(OptimizeFor): (ImageThumbnailPresetOptions|VideoThumbnailPresetOptions|DocumentThumbnailPresetOptions) $leaf
     */
    #[DataProvider('thumbnailLeaves')]
    public function testThumbnailShipsWidthAndQualityLadder(callable $leaf): void
    {
        $actual = [];
        foreach ([OptimizeFor::Size, OptimizeFor::Balanced, OptimizeFor::Quality] as $level) {
            $dto = $leaf($level);
            $actual[] = [$dto->width, $dto->quality];
        }
        $this->assertSame([[160, 70], [320, 85], [640, 92]], $actual);
    }

    public function testDtosAreSparseByDefault(): void
    {
        $this->assertNull((new VideoConvertPresetOptions())->crf);
        $this->assertNull((new ImageConvertPresetOptions())->quality);
        $thumb = new ImageThumbnailPresetOptions(width: 200);
        $this->assertSame(200, $thumb->width);
        $this->assertNull($thumb->quality);
    }

    public function testPresetDefaultsGainsNoConvertOrThumbnailMethod(): void
    {
        // No behaviour change: the builder wiring is uUSSVNJA.
        foreach (['imageConvert', 'videoConvert', 'imageThumbnail', 'videoThumbnail', 'documentThumbnail'] as $method) {
            $this->assertFalse(\method_exists(PresetDefaults::class, $method), $method);
        }
    }
}
