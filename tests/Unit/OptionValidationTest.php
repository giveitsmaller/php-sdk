<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Ergonomic\MergeOptions;
use Gisl\Sdk\Ergonomic\OptionValidation;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\MergedRecipe;
use Gisl\Sdk\FileFirst\Recipe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Dhje3Faq — eager, synchronous, PRE-UPLOAD option-key validation for the
 * ergonomic verbs (convert / thumbnail / textWatermark / watermark). Mirrors the
 * TS `option-validation.test.ts`. Covers the shared validator
 * ({@see OptionValidation}) directly AND through a file-first {@see Recipe} built
 * WITHOUT a client, proving the throw fires at the verb call (no HTTP). Network-free.
 */
#[CoversClass(OptionValidation::class)]
final class OptionValidationTest extends TestCase
{
    /**
     * A file-first Recipe with NO client — the verb call must throw
     * synchronously before any upload. Mirrors the `recipe()` helper in the
     * file-first test suites.
     */
    private function recipe(string $path = 'photo.jpg'): Recipe
    {
        return new Recipe(FileInput::path($path));
    }

    // --- valid bags pass (one real key per verb) ----------------------------

    /**
     * A real contract option key per verb passes validation without throwing.
     *
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('validBagProvider')]
    public function a_valid_bag_passes_per_verb(string $verb, array $options): void
    {
        $this->expectNotToPerformAssertions();
        OptionValidation::validateVerbOptions($verb, $options);
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function validBagProvider(): iterable
    {
        yield 'convert quality' => ['convert', ['quality' => 80]];
        yield 'thumbnail both dims + fit' => ['thumbnail', ['width' => 10, 'height' => 10, 'fit' => 'crop']];
        yield 'textWatermark font_size' => ['textWatermark', ['font_size' => 12]];
        yield 'watermark anchor' => ['watermark', ['anchor' => 'center']];
    }

    // --- typo / unknown key rejected ----------------------------------------

    #[Test]
    public function a_typo_key_throws_unknown_field_naming_the_typo(): void
    {
        try {
            OptionValidation::validateVerbOptions('convert', ['quaity' => 80]);
            self::fail('an unknown key must throw');
        } catch (GislConfigError $err) {
            self::assertSame('unknown_field', $err->reason);
            self::assertSame(['quaity'], $err->conflictingFields);
            self::assertStringContainsString("unknown option 'quaity'", $err->getMessage());
        }
    }

    // --- positional-owned keys rejected -------------------------------------

    /**
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('positionalOwnedProvider')]
    public function a_positional_owned_key_throws_unknown_field(string $verb, array $options, string $expectedField): void
    {
        try {
            OptionValidation::validateVerbOptions($verb, $options);
            self::fail("a positional-owned {$expectedField} key must throw");
        } catch (GislConfigError $err) {
            self::assertSame('unknown_field', $err->reason);
            self::assertSame([$expectedField], $err->conflictingFields);
            self::assertStringContainsString('first argument', $err->getMessage());
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public static function positionalOwnedProvider(): iterable
    {
        yield 'convert output_format' => ['convert', ['output_format' => 'webm'], 'output_format'];
        yield 'convert format alias' => ['convert', ['format' => 'legacy'], 'format'];
        yield 'textWatermark text' => ['textWatermark', ['text' => 'fake'], 'text'];
    }

    #[Test]
    public function the_positional_owned_check_precedes_the_unknown_key_check(): void
    {
        // A bag carrying BOTH a positional-owned key and a genuine unknown key
        // reports the positional-owned one first (its check runs before the
        // generic unknown-key scan), with the more specific message.
        try {
            OptionValidation::validateVerbOptions('convert', ['output_format' => 'webm', 'quaity' => 1]);
            self::fail('must throw');
        } catch (GislConfigError $err) {
            self::assertSame(['output_format'], $err->conflictingFields);
            self::assertStringContainsString('first argument', $err->getMessage());
        }
    }

    // --- thumbnail requires both dimensions ---------------------------------

    /**
     * @param array<string, mixed> $options
     * @param list<string>         $expectedMissing
     */
    #[Test]
    #[DataProvider('missingThumbnailDimensionProvider')]
    public function thumbnail_missing_or_null_dimension_throws_missing_required_field(
        array $options,
        array $expectedMissing,
    ): void {
        try {
            OptionValidation::assertThumbnailDimensions($options);
            self::fail('a missing/null thumbnail dimension must throw');
        } catch (GislConfigError $err) {
            self::assertSame('missing_required_field', $err->reason);
            self::assertSame($expectedMissing, $err->conflictingFields);
        }
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: list<string>}>
     */
    public static function missingThumbnailDimensionProvider(): iterable
    {
        yield 'no dims' => [[], ['width', 'height']];
        yield 'width only' => [['width' => 320], ['height']];
        yield 'height only' => [['height' => 240], ['width']];
        yield 'null width' => [['width' => null, 'height' => 240], ['width']];
        yield 'null height' => [['width' => 320, 'height' => null], ['height']];
        yield 'both null' => [['width' => null, 'height' => null], ['width', 'height']];
    }

    #[Test]
    public function thumbnail_with_both_dimensions_passes(): void
    {
        $this->expectNotToPerformAssertions();
        OptionValidation::assertThumbnailDimensions(['width' => 320, 'height' => 240]);
    }

    // --- watermark validates against the image ∪ video union -----------------

    /**
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('watermarkUnionProvider')]
    public function watermark_accepts_keys_from_the_image_video_union(array $options): void
    {
        $this->expectNotToPerformAssertions();
        OptionValidation::validateVerbOptions('watermark', $options);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function watermarkUnionProvider(): iterable
    {
        // overlay_width + the shared anchor/opacity/margin keys live on both the
        // image_watermark and video_watermark contracts — the union accepts them
        // because the base media may be undetectable at the `.watermark()` call.
        yield 'overlay_width' => [['overlay_width' => '30%']];
        yield 'anchor + opacity + margins' => [['anchor' => 'bottom_right', 'opacity' => 0.5, 'margin_x' => 8, 'margin_y' => 8]];
    }

    #[Test]
    public function watermark_still_rejects_a_key_outside_the_union(): void
    {
        try {
            OptionValidation::validateVerbOptions('watermark', ['nonsense' => 1]);
            self::fail('a key outside the image∪video union must throw');
        } catch (GislConfigError $err) {
            self::assertSame('unknown_field', $err->reason);
            self::assertSame(['nonsense'], $err->conflictingFields);
        }
    }

    // --- PRE-UPLOAD: the throw happens at the verb call (no client / no HTTP) -

    #[Test]
    public function convert_typo_throws_synchronously_at_the_verb_call_without_a_client(): void
    {
        // The Recipe carries NO client and the call performs NO upload — the
        // validator fires eagerly at convert(), proving pre-upload rejection.
        try {
            $this->recipe('photo.jpg')->convert('webp', ['quaity' => 1]);
            self::fail('an unknown convert key must throw at the verb call');
        } catch (GislConfigError $err) {
            self::assertSame('unknown_field', $err->reason);
            self::assertSame(['quaity'], $err->conflictingFields);
        }
    }

    #[Test]
    public function convert_positional_owned_throws_synchronously_at_the_verb_call(): void
    {
        try {
            $this->recipe('clip.mov')->convert('mp4', ['output_format' => 'webm']);
            self::fail('a positional-owned output_format must throw at the verb call');
        } catch (GislConfigError $err) {
            self::assertSame('unknown_field', $err->reason);
            self::assertSame(['output_format'], $err->conflictingFields);
        }
    }

    #[Test]
    public function text_watermark_positional_owned_throws_synchronously_at_the_verb_call(): void
    {
        try {
            $this->recipe('photo.jpg')->textWatermark('real', ['text' => 'fake']);
            self::fail('a positional-owned text must throw at the verb call');
        } catch (GislConfigError $err) {
            self::assertSame('unknown_field', $err->reason);
            self::assertSame(['text'], $err->conflictingFields);
        }
    }

    #[Test]
    public function thumbnail_missing_dimension_throws_synchronously_at_the_verb_call(): void
    {
        try {
            $this->recipe('photo.jpg')->thumbnail(['width' => 320]);
            self::fail('a width-only thumbnail must throw at the verb call');
        } catch (GislConfigError $err) {
            self::assertSame('missing_required_field', $err->reason);
            self::assertNotNull($err->conflictingFields);
            self::assertContains('height', $err->conflictingFields);
        }
    }

    #[Test]
    public function thumbnail_null_dimension_throws_synchronously_at_the_verb_call(): void
    {
        // PHP drops null before lowering, so a null dimension must be caught by
        // the both-required gate (array_key_exists alone is insufficient).
        try {
            $this->recipe('photo.jpg')->thumbnail(['width' => 320, 'height' => null]);
            self::fail('a null height must throw at the verb call');
        } catch (GislConfigError $err) {
            self::assertSame('missing_required_field', $err->reason);
            self::assertNotNull($err->conflictingFields);
            self::assertContains('height', $err->conflictingFields);
        }
    }

    #[Test]
    public function a_valid_convert_call_does_not_throw_at_the_verb(): void
    {
        // Sanity counter-test: a clean bag flows past the eager validator and
        // appends the step (no client needed for the chain method itself).
        $recipe = $this->recipe('clip.mov')->convert('mp4', ['crf' => 23]);
        self::assertSame(1, $recipe->stepCount());
    }

    // --- duplicated validator bodies (MergedRecipe / WatermarkedRecipe) ------
    // The validator is COPY-PASTED into MergedRecipe::convert/thumbnail, the
    // WatermarkedRecipe post-verbs, and the Recipe::watermark body (no
    // delegation). The valid-bag paths alone would ship green even if a
    // copy-paste omitted the guard, so these drive REJECTIONS through the actual
    // duplicated verb calls on client-less recipes (the throw is pre-upload).

    private function overlay(string $path = 'logo.png'): Recipe
    {
        return new Recipe(FileInput::path($path));
    }

    #[Test]
    public function merged_thumbnail_missing_dimension_throws_at_the_verb_call(): void
    {
        $merged = new MergedRecipe(
            [FileInput::path('a.mp4'), FileInput::path('b.mp4')],
            new MergeOptions(mediaKind: 'video'),
        );
        try {
            $merged->thumbnail(['width' => 320]);
            self::fail('a width-only MergedRecipe thumbnail must throw');
        } catch (GislConfigError $err) {
            self::assertSame('missing_required_field', $err->getReason());
            self::assertNotNull($err->getConflictingFields());
            self::assertContains('height', $err->getConflictingFields());
        }
    }

    #[Test]
    public function watermarked_convert_typo_throws_at_the_verb_call(): void
    {
        $watermarked = (new Recipe(FileInput::path('photo.jpg')))->watermark($this->overlay());
        try {
            $watermarked->convert('webp', ['quaity' => 1]);
            self::fail('an unknown WatermarkedRecipe convert key must throw');
        } catch (GislConfigError $err) {
            self::assertSame('unknown_field', $err->getReason());
            self::assertSame(['quaity'], $err->getConflictingFields());
        }
    }

    #[Test]
    public function watermarked_thumbnail_missing_dimension_throws_at_the_verb_call(): void
    {
        $watermarked = (new Recipe(FileInput::path('photo.jpg')))->watermark($this->overlay());
        try {
            $watermarked->thumbnail(['width' => 320]);
            self::fail('a width-only WatermarkedRecipe thumbnail must throw');
        } catch (GislConfigError $err) {
            self::assertSame('missing_required_field', $err->getReason());
            self::assertNotNull($err->getConflictingFields());
            self::assertContains('height', $err->getConflictingFields());
        }
    }

    #[Test]
    public function watermark_verb_rejects_an_unknown_key_before_the_media_gate(): void
    {
        // A bad key in the watermark bag throws at the watermark() verb call —
        // BEFORE the media-routing gate / upload. The base (photo.jpg) would
        // route fine, so a throw here proves the eager key-validator fires first.
        try {
            (new Recipe(FileInput::path('photo.jpg')))->watermark($this->overlay(), ['bogus' => 1]);
            self::fail('an unknown watermark key must throw at the verb call');
        } catch (GislConfigError $err) {
            self::assertSame('unknown_field', $err->getReason());
            self::assertSame(['bogus'], $err->getConflictingFields());
        }
    }

    #[Test]
    public function recipe_transform_rejects_an_unknown_key_at_the_verb_call(): void
    {
        try {
            $this->recipe('photo.jpg')->transform(['bogus' => 1]);
            self::fail('an unknown Recipe transform key must throw');
        } catch (GislConfigError $err) {
            self::assertSame('unknown_field', $err->getReason());
            self::assertSame(['bogus'], $err->getConflictingFields());
        }
    }

    #[Test]
    public function merged_transform_rejects_an_unknown_key_at_the_verb_call(): void
    {
        $merged = new MergedRecipe(
            [FileInput::path('a.mp4'), FileInput::path('b.mp4')],
            new MergeOptions(mediaKind: 'video'),
        );
        try {
            $merged->transform(['bogus' => 1]);
            self::fail('an unknown MergedRecipe transform key must throw');
        } catch (GislConfigError $err) {
            self::assertSame('unknown_field', $err->getReason());
            self::assertSame(['bogus'], $err->getConflictingFields());
        }
    }

    #[Test]
    public function watermarked_transform_rejects_an_unknown_key_at_the_verb_call(): void
    {
        $watermarked = (new Recipe(FileInput::path('photo.jpg')))->watermark($this->overlay());
        try {
            $watermarked->transform(['bogus' => 1]);
            self::fail('an unknown WatermarkedRecipe transform key must throw');
        } catch (GislConfigError $err) {
            self::assertSame('unknown_field', $err->getReason());
            self::assertSame(['bogus'], $err->getConflictingFields());
        }
    }

    // --- single-op convert guard (ExVcchMz) --------------------------------
    // The single-op builder convert carries its target in the bag as
    // `output_format` (no positional format), so it uses a DISTINCT guard that
    // allows+requires output_format and rejects the `format` alias. Mirrors the
    // TS `validateSingleOpConvertOptions` coverage.

    #[Test]
    public function single_op_convert_accepts_output_format_plus_contract_keys(): void
    {
        $this->expectNotToPerformAssertions();
        OptionValidation::validateSingleOpConvertOptions(['output_format' => 'webp', 'quality' => 80]);
    }

    #[Test]
    public function single_op_convert_rejects_a_missing_output_format(): void
    {
        try {
            OptionValidation::validateSingleOpConvertOptions(['quality' => 80]);
            self::fail('single-op convert must require output_format');
        } catch (GislConfigError $err) {
            self::assertSame('missing_required_field', $err->getReason());
            self::assertSame(['output_format'], $err->getConflictingFields());
        }
    }

    #[Test]
    public function single_op_convert_rejects_a_null_output_format(): void
    {
        try {
            OptionValidation::validateSingleOpConvertOptions(['output_format' => null]);
            self::fail('single-op convert must reject a null output_format');
        } catch (GislConfigError $err) {
            self::assertSame('missing_required_field', $err->getReason());
        }
    }

    #[Test]
    public function single_op_convert_rejects_an_unknown_key(): void
    {
        try {
            OptionValidation::validateSingleOpConvertOptions(['output_format' => 'webp', 'bogus' => 1]);
            self::fail('single-op convert must reject an unknown key');
        } catch (GislConfigError $err) {
            self::assertSame('unknown_field', $err->getReason());
            self::assertSame(['bogus'], $err->getConflictingFields());
        }
    }

    #[Test]
    public function single_op_convert_rejects_the_format_alias(): void
    {
        // `format` is the SDK alias for the positional file-first arg; the
        // single-op bag lowers verbatim to the wire, so only the wire key
        // `output_format` is accepted — `format` must be rejected as unknown,
        // never silently shipped as a wire `format` the server 422s.
        try {
            OptionValidation::validateSingleOpConvertOptions(['format' => 'webp']);
            self::fail('single-op convert must reject the `format` alias');
        } catch (GislConfigError $err) {
            self::assertSame('unknown_field', $err->getReason());
            self::assertSame(['format'], $err->getConflictingFields());
        }
    }
}
