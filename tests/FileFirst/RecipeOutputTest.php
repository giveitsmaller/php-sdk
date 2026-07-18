<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\FileFirst;

use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\Recipe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Image "Output" + Resize file-first helpers (card YNLrGhNo, contracts v2.97.0).
 *
 * `output(format?, options)` resolves the route from (input format, output_format)
 * against the image-output-routes projection and lowers to that route's wire op —
 * `compress` (same_format, `output_format: 'original'`) or `convert` (format_change,
 * `output_format: <fmt>`) — emitting only route-honored options. `resize()` merges
 * width/height/fit into the SAME Output step (one artifact, never a thumbnail).
 * Planned / not-honored / planned-per-value options throw BEFORE upload.
 *
 * PHP arm of the TS `file-first-output.test.ts` — every case mirrors a TS case so
 * the lowered wire shapes are asserted byte-identical across SDKs. Network-free:
 * the recipe carries no client and only the pure `toWorkflowPayload()` lowering
 * seam is exercised.
 */
final class RecipeOutputTest extends TestCase
{
    private const FILE_ID = 'file_0001';

    private function recipe(string $path): Recipe
    {
        return new Recipe(FileInput::path($path));
    }

    /**
     * Lower a single-input Recipe and return its one job's operations.
     *
     * @return list<array<string, mixed>>
     */
    private function operations(Recipe $recipe): array
    {
        $wire = $recipe->toWorkflowPayload(self::FILE_ID)->toWire();
        self::assertIsArray($wire['jobs']);
        self::assertCount(1, $wire['jobs'], 'a single-input chain lowers to exactly one job');
        $job = $wire['jobs'][0];
        self::assertIsArray($job);
        self::assertIsArray($job['operations']);

        /** @var list<array<string, mixed>> $operations */
        $operations = $job['operations'];

        return $operations;
    }

    /**
     * The single lowered op (asserts exactly one). Mirrors the TS `soleOp`.
     *
     * @return array<string, mixed>
     */
    private function soleOp(Recipe $recipe): array
    {
        $ops = $this->operations($recipe);
        self::assertCount(1, $ops);

        return $ops[0];
    }

    // --- output(): route resolution + wire op -------------------------------

    #[Test]
    public function same_format_lowers_to_a_compress_op_with_output_format_original(): void
    {
        $op = $this->soleOp($this->recipe('photo.jpg')->output('jpeg', ['quality' => 80]));
        self::assertSame(['type' => 'compress', 'options' => ['output_format' => 'original', 'quality' => 80]], $op);
    }

    #[Test]
    public function format_omitted_lowers_to_a_same_format_compress_op(): void
    {
        // null format → keep the input format → same-format optimiser route.
        $op = $this->soleOp($this->recipe('photo.png')->output(null, ['quality' => 70]));
        self::assertSame(['type' => 'compress', 'options' => ['output_format' => 'original', 'quality' => 70]], $op);
    }

    #[Test]
    public function format_change_lowers_to_a_convert_op_with_the_target_output_format(): void
    {
        $op = $this->soleOp($this->recipe('photo.png')->output('webp', ['quality' => 80]));
        self::assertSame(['type' => 'convert', 'options' => ['output_format' => 'webp', 'quality' => 80]], $op);
    }

    #[Test]
    public function output_never_emits_a_thumbnail_op(): void
    {
        $ops = $this->operations($this->recipe('photo.png')->output('webp')->resize(800, 600));
        self::assertNotContains('thumbnail', \array_column($ops, 'type'));
    }

    // --- output() + resize(): one artifact ----------------------------------

    #[Test]
    public function format_change_plus_resize_is_one_convert_op_carrying_resize_keys(): void
    {
        $op = $this->soleOp($this->recipe('photo.png')->output('webp')->resize(1200, 800, 'max'));
        self::assertSame(
            ['type' => 'convert', 'options' => ['output_format' => 'webp', 'width' => 1200, 'height' => 800, 'fit' => 'max']],
            $op,
        );
    }

    #[Test]
    public function same_format_width_only_resize_omits_height_and_fit(): void
    {
        $op = $this->soleOp($this->recipe('photo.jpg')->output('jpeg')->resize(800));
        self::assertSame(['type' => 'compress', 'options' => ['output_format' => 'original', 'width' => 800]], $op);
    }

    #[Test]
    public function resize_with_no_preceding_output_appends_a_same_format_output_step(): void
    {
        $op = $this->soleOp($this->recipe('photo.png')->resize(800, 600));
        self::assertSame(
            ['type' => 'compress', 'options' => ['output_format' => 'original', 'width' => 800, 'height' => 600]],
            $op,
        );
    }

    #[Test]
    public function resize_merges_into_the_preceding_output_step_no_extra_op(): void
    {
        $ops = $this->operations($this->recipe('photo.png')->output('webp')->resize(800));
        self::assertCount(1, $ops);
    }

    // --- output(): route-aware option gating --------------------------------

    #[Test]
    public function progressive_is_honored_on_same_format_jpeg(): void
    {
        $op = $this->soleOp($this->recipe('photo.jpg')->output('jpeg', ['progressive' => true]));
        self::assertSame(true, $op['options']['progressive'] ?? null);
    }

    #[Test]
    public function progressive_on_a_format_change_throws_option_not_on_route(): void
    {
        // png → jpeg is a format-change (convert); progressive is a same-format
        // optimiser knob, not honored on the transcoder route.
        try {
            $this->operations($this->recipe('photo.png')->output('jpeg', ['progressive' => true]));
            self::fail('progressive on a format-change must throw');
        } catch (GislConfigError $err) {
            self::assertSame('option_not_on_route', $err->reason);
        }
    }

    #[Test]
    public function background_is_honored_on_format_change_to_jpeg(): void
    {
        $op = $this->soleOp($this->recipe('photo.png')->output('jpeg', ['background' => '#ffffff']));
        self::assertSame(['type' => 'convert', 'options' => ['output_format' => 'jpeg', 'background' => '#ffffff']], $op);
    }

    #[Test]
    public function background_on_a_same_format_route_throws_option_not_on_route(): void
    {
        // jpeg → jpeg is same-format; background is a transcoder-only fill option.
        try {
            $this->operations($this->recipe('photo.jpg')->output('jpeg', ['background' => '#fff']));
            self::fail('background on a same-format route must throw');
        } catch (GislConfigError $err) {
            self::assertSame('option_not_on_route', $err->reason);
            self::assertStringContainsString('not honored', $err->getMessage());
        }
    }

    #[Test]
    public function optimization_level_is_honored_on_same_format_png(): void
    {
        $op = $this->soleOp($this->recipe('a.png')->output('png', ['optimization_level' => 6]));
        self::assertSame(6, $op['options']['optimization_level'] ?? null);
    }

    #[Test]
    public function optimization_level_on_jpeg_throws_option_not_on_route(): void
    {
        try {
            $this->operations($this->recipe('a.jpg')->output('jpeg', ['optimization_level' => 6]));
            self::fail('optimization_level on jpeg must throw');
        } catch (GislConfigError $err) {
            self::assertSame('option_not_on_route', $err->reason);
        }
    }

    // --- output(): lossless (stable on jpeg/webp since v2.101.0) ------------

    #[Test]
    public function lossless_honored_on_same_format_jpeg_and_webp(): void
    {
        self::assertSame(true, $this->soleOp($this->recipe('photo.jpg')->output('jpeg', ['lossless' => true]))['options']['lossless'] ?? null);
        self::assertSame(true, $this->soleOp($this->recipe('photo.webp')->output('webp', ['lossless' => true]))['options']['lossless'] ?? null);
    }

    #[Test]
    public function lossless_not_honored_on_png_throws_option_not_on_route(): void
    {
        try {
            $this->operations($this->recipe('photo.png')->output('png', ['lossless' => true]));
            self::fail('lossless is not honored on png');
        } catch (GislConfigError $err) {
            self::assertSame('option_not_on_route', $err->reason);
        }
    }

    // --- output(): removed and still-planned options gated locally -----------

    #[Test]
    public function removed_lossy_on_png_throws_unknown_field(): void
    {
        try {
            $this->recipe('photo.png')->output('png', ['lossy' => true]);
            self::fail('a removed lossy option must throw');
        } catch (GislConfigError $err) {
            self::assertSame('unknown_field', $err->reason);
        }
    }

    #[Test]
    public function metadata_strip_and_keep_honored_on_same_format_jpeg(): void
    {
        // v2.107.0 rename: all -> strip (strip = remove EXIF/IPTC/XMP, default).
        $op = $this->soleOp($this->recipe('a.jpg')->output('jpeg', ['metadata' => 'strip']));
        self::assertSame('strip', $op['options']['metadata'] ?? null);

        $op = $this->soleOp($this->recipe('a.jpg')->output('jpeg', ['metadata' => 'keep']));
        self::assertSame('keep', $op['options']['metadata'] ?? null);
    }

    #[Test]
    public function metadata_on_a_format_change_throws_feature_not_available(): void
    {
        // v2.106.0: convert.image metadata is PLANNED → planned on format_change.
        try {
            $this->operations($this->recipe('a.png')->output('webp', ['metadata' => 'strip']));
            self::fail('metadata is planned on a format-change route');
        } catch (GislConfigError $err) {
            self::assertSame('feature_not_available', $err->reason);
        }
    }

    // --- output(): target-size (v2.108.0; encoding_mode + target_size_bytes STABLE) --

    #[Test]
    public function encoding_mode_quality_honored_on_same_format_jpeg_and_webp(): void
    {
        // `quality` is the default mode (the optimiser quality-slider path).
        self::assertSame('quality', $this->soleOp($this->recipe('a.jpg')->output('jpeg', ['encoding_mode' => 'quality']))['options']['encoding_mode'] ?? null);
        self::assertSame('quality', $this->soleOp($this->recipe('a.webp')->output('webp', ['encoding_mode' => 'quality']))['options']['encoding_mode'] ?? null);
    }

    #[Test]
    public function encoding_mode_target_size_honored_since_v2_108_0(): void
    {
        // STABLE since v2.108.0 — target_size is emitted, not gated.
        $op = $this->soleOp($this->recipe('a.jpg')->output('jpeg', ['encoding_mode' => 'target_size', 'target_size_bytes' => 50_000]));
        self::assertSame('target_size', $op['options']['encoding_mode'] ?? null);
        self::assertSame(50_000, $op['options']['target_size_bytes'] ?? null);
    }

    #[Test]
    public function encoding_mode_on_a_format_change_throws_option_not_on_route(): void
    {
        // encoding_mode is an optimiser (same_format) knob; a format-change routes
        // via convert, which does not honor it.
        try {
            $this->operations($this->recipe('a.png')->output('webp', ['encoding_mode' => 'quality']));
            self::fail('encoding_mode is not honored on a format-change route');
        } catch (GislConfigError $err) {
            self::assertSame('option_not_on_route', $err->reason);
        }
    }

    // --- output(): chroma_subsampling (v2.110.0 stable) + quality_preset (v2.148.0 stable) --

    #[Test]
    public function chroma_subsampling_honored_on_same_format_jpeg(): void
    {
        $op = $this->soleOp($this->recipe('a.jpg')->output('jpeg', ['chroma_subsampling' => '420']));
        self::assertSame('420', $op['options']['chroma_subsampling'] ?? null);
    }

    #[Test]
    public function chroma_subsampling_on_webp_throws_option_not_on_route(): void
    {
        // chroma_subsampling is jpeg-only (same_format).
        try {
            $this->operations($this->recipe('a.webp')->output('webp', ['chroma_subsampling' => '420']));
            self::fail('chroma_subsampling is jpeg-only');
        } catch (GislConfigError $err) {
            self::assertSame('option_not_on_route', $err->reason);
        }
    }

    #[Test]
    public function quality_preset_infers_encoding_mode_auto_quality(): void
    {
        // 86gAu5Tr: quality_preset depends_on encoding_mode=auto_quality; the SDK
        // infers it so the preset forms a valid request instead of a 422.
        $op = $this->soleOp($this->recipe('a.jpg')->output('jpeg', ['quality_preset' => 'good']));
        self::assertSame(
            ['output_format' => 'original', 'quality_preset' => 'good', 'encoding_mode' => 'auto_quality'],
            $op['options'],
        );
    }

    #[Test]
    public function quality_preset_with_explicit_non_auto_quality_mode_is_rejected(): void
    {
        // The contract depends_on requires encoding_mode=auto_quality with quality_preset.
        try {
            $this->operations($this->recipe('a.jpg')->output('jpeg', ['quality_preset' => 'good', 'encoding_mode' => 'quality']));
            self::fail('quality_preset + non-auto_quality encoding_mode must throw');
        } catch (GislConfigError $err) {
            self::assertSame('invalid_option_combination', $err->reason);
        }
    }

    #[Test]
    public function quality_preset_with_explicit_auto_quality_mode_is_allowed(): void
    {
        $op = $this->soleOp($this->recipe('a.jpg')->output('jpeg', ['quality_preset' => 'good', 'encoding_mode' => 'auto_quality']));
        self::assertSame('good', $op['options']['quality_preset'] ?? null);
        self::assertSame('auto_quality', $op['options']['encoding_mode'] ?? null);
    }

    /**
     * auto_quality forbids the mode-specific siblings whose depends_on names a
     * different encoding_mode; the SDK rejects them client-side rather than
     * shipping a payload the worker refuses as invalid_options (86gAu5Tr).
     *
     * @return iterable<string, array{string, mixed}>
     */
    public static function autoQualityIncompatibleSiblings(): iterable
    {
        yield 'quality' => ['quality', 80];
        yield 'lossless' => ['lossless', true];
        yield 'target_size_bytes' => ['target_size_bytes', 50_000];
    }

    #[Test]
    #[DataProvider('autoQualityIncompatibleSiblings')]
    public function quality_preset_plus_mode_specific_sibling_is_rejected(string $field, mixed $value): void
    {
        try {
            $this->operations($this->recipe('a.jpg')->output('jpeg', ['quality_preset' => 'good', $field => $value]));
            self::fail("quality_preset + {$field} must throw (auto_quality forbids it)");
        } catch (GislConfigError $err) {
            self::assertSame('invalid_option_combination', $err->reason);
            self::assertSame(['encoding_mode', $field], $err->conflictingFields);
        }
    }

    #[Test]
    public function explicit_auto_quality_plus_quality_is_rejected_without_quality_preset(): void
    {
        try {
            $this->operations($this->recipe('a.jpg')->output('jpeg', ['encoding_mode' => 'auto_quality', 'quality' => 80]));
            self::fail('encoding_mode auto_quality + quality must throw');
        } catch (GislConfigError $err) {
            self::assertSame('invalid_option_combination', $err->reason);
        }
    }

    #[Test]
    public function quality_preset_not_on_png_route_throws_option_not_on_route(): void
    {
        // quality_preset is honored only on same_format avif/jpeg/webp.
        try {
            $this->operations($this->recipe('a.png')->output('png', ['quality_preset' => 'good']));
            self::fail('quality_preset is avif/jpeg/webp-only');
        } catch (GislConfigError $err) {
            self::assertSame('option_not_on_route', $err->reason);
        }
    }

    // --- output(): general depends_on validation (ehHU08Hu) ------------------

    #[Test]
    public function target_size_bytes_without_target_size_mode_is_rejected(): void
    {
        // target_size_bytes depends_on encoding_mode=target_size; with no explicit
        // mode the default is 'quality', so it must be rejected pre-upload.
        try {
            $this->operations($this->recipe('a.jpg')->output('jpeg', ['target_size_bytes' => 50_000]));
            self::fail('target_size_bytes needs encoding_mode target_size');
        } catch (GislConfigError $err) {
            self::assertSame('invalid_option_combination', $err->reason);
            self::assertSame(['encoding_mode', 'target_size_bytes'], $err->conflictingFields);
        }
    }

    #[Test]
    public function quality_with_no_encoding_mode_is_allowed_default_quality(): void
    {
        // encoding_mode defaults to 'quality', so `quality` alone is VALID — the
        // general gate must NOT over-reject when the depended-on key is absent.
        $op = $this->soleOp($this->recipe('a.jpg')->output('jpeg', ['quality' => 80]));
        self::assertSame(80, $op['options']['quality'] ?? null);
        self::assertArrayNotHasKey('encoding_mode', $op['options']);
    }

    #[Test]
    public function fit_without_width_or_height_is_rejected(): void
    {
        // fit depends_on { width: set, height: set, logic: or } — at least one
        // dimension must be present.
        try {
            $this->operations($this->recipe('a.jpg')->output('jpeg', ['fit' => 'max']));
            self::fail('fit needs width or height');
        } catch (GislConfigError $err) {
            self::assertSame('invalid_option_combination', $err->reason);
            self::assertSame(['fit', 'width', 'height'], $err->conflictingFields);
        }
    }

    #[Test]
    public function fit_with_a_width_is_allowed(): void
    {
        $op = $this->soleOp($this->recipe('a.jpg')->output('jpeg', ['fit' => 'max', 'width' => 800]));
        self::assertSame('max', $op['options']['fit'] ?? null);
        self::assertSame(800, $op['options']['width'] ?? null);
    }

    #[Test]
    public function fit_with_a_null_width_is_still_rejected(): void
    {
        // null is NOT "set" — parity with TS (both reject a null dimension).
        try {
            $this->operations($this->recipe('a.jpg')->output('jpeg', ['fit' => 'max', 'width' => null]));
            self::fail('fit with a null width must reject');
        } catch (GislConfigError $err) {
            self::assertSame('invalid_option_combination', $err->reason);
            self::assertSame(['fit', 'width', 'height'], $err->conflictingFields);
        }
    }

    #[Test]
    public function fit_without_a_dimension_is_rejected_on_a_format_change_too(): void
    {
        // fit -> width|height is IDENTICAL in compress + convert, so it validates
        // on BOTH routes (the encoding_mode-family deps stay same_format-only).
        try {
            $this->operations($this->recipe('a.jpg')->output('webp', ['fit' => 'max']));
            self::fail('fit needs a dimension on a format_change too');
        } catch (GislConfigError $err) {
            self::assertSame('invalid_option_combination', $err->reason);
            self::assertSame(['fit', 'width', 'height'], $err->conflictingFields);
        }
    }

    #[Test]
    public function fit_with_a_width_is_allowed_on_a_format_change(): void
    {
        $op = $this->soleOp($this->recipe('a.jpg')->output('webp', ['fit' => 'max', 'width' => 800]));
        self::assertSame('max', $op['options']['fit'] ?? null);
        self::assertSame(800, $op['options']['width'] ?? null);
    }

    #[Test]
    public function a_null_dependent_option_is_dropped_not_shipped(): void
    {
        // target_size_bytes: null is dropped at build, so it never reaches the
        // wire and triggers no dependency error — full null parity with TS.
        $op = $this->soleOp($this->recipe('a.jpg')->output('jpeg', ['target_size_bytes' => null]));
        self::assertArrayNotHasKey('target_size_bytes', $op['options']);
        self::assertSame(['output_format' => 'original'], $op['options']);
    }

    // --- output(): color_profile (un-gated v2.128.0) + auto_orient (STABLE since v2.120.0) --

    #[Test]
    public function color_profile_un_gated_on_proven_raster_routes_v2_128_0(): void
    {
        // Un-gated v2.128.0: keep/strip honored on same_format jpeg/png/webp.
        // v2.137.0 adds same_format avif to the live key set.
        self::assertSame('keep', $this->soleOp($this->recipe('a.jpg')->output('jpeg', ['color_profile' => 'keep']))['options']['color_profile'] ?? null);
        self::assertSame('keep', $this->soleOp($this->recipe('a.webp')->output('webp', ['color_profile' => 'keep']))['options']['color_profile'] ?? null);
        self::assertSame('keep', $this->soleOp($this->recipe('a.avif')->output('avif', ['color_profile' => 'keep']))['options']['color_profile'] ?? null);
        // jpeg srgb is live (jpeg/png groups carry no srgb-planned entry).
        self::assertSame('srgb', $this->soleOp($this->recipe('a.jpg')->output('jpeg', ['color_profile' => 'srgb']))['options']['color_profile'] ?? null);
        // v2.134 added `srgb: planned` to the generic compress `image` group, through
        // which webp/gif/svg/tiff route — so webp srgb stays gated pre-upload. AVIF
        // routes through image_avif, where srgb flipped planned->stable in v2.148.
        try {
            $this->operations($this->recipe('a.webp')->output('webp', ['color_profile' => 'srgb']));
            self::fail('webp srgb is planned per-value');
        } catch (GislConfigError $err) {
            self::assertSame('feature_not_available', $err->reason);
        }
        self::assertSame('srgb', $this->soleOp($this->recipe('a.avif')->output('avif', ['color_profile' => 'srgb']))['options']['color_profile'] ?? null);
    }

    #[Test]
    public function auto_orient_honored_since_v2_120_0_both_routes(): void
    {
        // STABLE since v2.120.0 — emitted, not gated, on same_format AND format_change.
        self::assertSame(true, $this->soleOp($this->recipe('a.jpg')->output('jpeg', ['auto_orient' => true]))['options']['auto_orient'] ?? null);
        self::assertSame(true, $this->soleOp($this->recipe('a.png')->output('webp', ['auto_orient' => true]))['options']['auto_orient'] ?? null);
    }

    // --- output(): out-of-enum value gate (rtkzl9gr) ------------------------

    #[Test]
    public function metadata_keep_rejected_pre_upload_on_same_format_avif(): void
    {
        // avif metadata enum is ['strip','all'] — 'keep' is out-of-enum.
        try {
            $this->operations($this->recipe('a.avif')->output('avif', ['metadata' => 'keep']));
            self::fail("metadata 'keep' is not offered on avif");
        } catch (GislConfigError $err) {
            self::assertSame('invalid_option_value', $err->reason);
            self::assertSame(['metadata'], $err->conflictingFields);
        }
    }

    #[Test]
    public function metadata_keep_rejected_pre_upload_on_same_format_svg(): void
    {
        // svg routes through the generic `image` group for PLANNED gating, but
        // the enum gate must use the narrow image_svg enum — this pins the split.
        try {
            $this->operations($this->recipe('logo.svg')->output('svg', ['metadata' => 'keep']));
            self::fail("metadata 'keep' is not offered on svg");
        } catch (GislConfigError $err) {
            self::assertSame('invalid_option_value', $err->reason);
        }
    }

    #[Test]
    public function metadata_keep_still_honored_on_same_format_png(): void
    {
        // png routes through image_png whose metadata enum includes 'keep'.
        $op = $this->soleOp($this->recipe('a.png')->output('png', ['metadata' => 'keep']));
        self::assertSame('keep', $op['options']['metadata'] ?? null);
    }

    #[Test]
    public function metadata_all_deprecated_alias_still_passes_on_avif(): void
    {
        // 'all' is IN the avif enum (a deprecated alias) — deprecated is not invalid.
        $op = $this->soleOp($this->recipe('a.avif')->output('avif', ['metadata' => 'all']));
        self::assertSame('all', $op['options']['metadata'] ?? null);
    }

    #[Test]
    public function an_out_of_enum_value_on_any_gated_option_is_rejected(): void
    {
        // fit enum is ['max','crop','scale'] — 'bogus' is out-of-enum.
        try {
            $this->operations($this->recipe('a.jpg')->output('jpeg', ['fit' => 'bogus']));
            self::fail('an out-of-enum fit value must throw');
        } catch (GislConfigError $err) {
            self::assertSame('invalid_option_value', $err->reason);
        }
    }

    #[Test]
    public function auto_orient_on_an_svg_format_change_is_rejected_pre_upload(): void
    {
        // svg is vector: it cannot be auto-oriented, so the option is stripped
        // from the svg→raster route and caught locally, not 422-ed server-side.
        try {
            $this->operations($this->recipe('logo.svg')->output('png', ['auto_orient' => true]));
            self::fail('auto_orient is not honored on an svg format-change');
        } catch (GislConfigError $err) {
            self::assertSame('option_not_on_route', $err->reason);
        }
    }

    // --- output(): unrepresentable routes + svg (vector, no resize) ---------

    #[Test]
    public function converting_to_a_format_with_no_route_throws_unsupported_route(): void
    {
        // svg is not a format_change target (cannot transcode TO svg).
        try {
            $this->operations($this->recipe('photo.png')->output('svg'));
            self::fail('converting to svg must throw');
        } catch (GislConfigError $err) {
            self::assertSame('unsupported_route', $err->reason);
        }
    }

    #[Test]
    public function svg_input_has_no_resize_on_its_route_so_resize_throws_not_honored(): void
    {
        try {
            $this->operations($this->recipe('logo.svg')->output('svg')->resize(200, 200));
            self::fail('resize on an svg route must throw');
        } catch (GislConfigError $err) {
            self::assertSame('option_not_on_route', $err->reason);
            self::assertStringContainsString('not honored', $err->getMessage());
        }
    }

    #[Test]
    public function svg_input_quality_is_no_longer_honored_so_quality_throws_not_on_route(): void
    {
        try {
            $this->operations($this->recipe('logo.svg')->output('svg', ['quality' => 80]));
            self::fail('quality on an svg route must throw');
        } catch (GislConfigError $err) {
            self::assertSame('option_not_on_route', $err->reason);
        }
    }

    // --- output(): undetectable input (bare upload id) ----------------------

    #[Test]
    public function facade_managed_webp_on_an_upload_id_emits_the_compress_facade(): void
    {
        $op = $this->soleOp((new Recipe(FileInput::uploadId('upl_x')))->output('webp'));
        self::assertSame(['type' => 'compress', 'options' => ['output_format' => 'webp']], $op);
    }

    #[Test]
    public function non_facade_target_on_an_upload_id_throws_media_unknown(): void
    {
        try {
            $this->operations((new Recipe(FileInput::uploadId('upl_x')))->output('jpeg'));
            self::fail('a non-facade target on an upload id must throw');
        } catch (GislConfigError $err) {
            self::assertSame('media_unknown', $err->reason);
        }
    }

    #[Test]
    public function resize_on_an_upload_id_throws_media_unknown(): void
    {
        // resize needs a resolvable route; an undetectable input cannot route.
        try {
            $this->operations((new Recipe(FileInput::uploadId('upl_x')))->output('webp')->resize(800));
            self::fail('resize on an undetectable input must throw');
        } catch (GislConfigError $err) {
            self::assertSame('media_unknown', $err->reason);
        }
    }

    // --- output(): eager key validation (pre-upload, no client) --------------

    #[Test]
    public function an_unknown_option_key_throws_unknown_field_at_the_verb_call(): void
    {
        try {
            $this->recipe('a.jpg')->output('jpeg', ['bogus' => 1]);
            self::fail('an unknown output option must throw at the verb call');
        } catch (GislConfigError $err) {
            self::assertSame('unknown_field', $err->reason);
            self::assertSame(['bogus'], $err->conflictingFields);
        }
    }

    #[Test]
    public function a_bag_supplied_output_format_is_rejected_owned_by_the_positional_arg(): void
    {
        try {
            $this->recipe('a.jpg')->output('jpeg', ['output_format' => 'webp']);
            self::fail('a bag-supplied output_format must throw');
        } catch (GislConfigError $err) {
            self::assertSame('unknown_field', $err->reason);
            self::assertSame(['output_format'], $err->conflictingFields);
            self::assertStringContainsString('first argument', $err->getMessage());
        }
    }

    #[Test]
    public function a_bag_supplied_format_alias_is_rejected_owned_by_the_positional_arg(): void
    {
        try {
            $this->recipe('a.jpg')->output('jpeg', ['format' => 'webp']);
            self::fail('a bag-supplied format alias must throw');
        } catch (GislConfigError $err) {
            self::assertSame('unknown_field', $err->reason);
            self::assertSame(['format'], $err->conflictingFields);
        }
    }
}
