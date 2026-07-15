<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\FileFirst;

use Gisl\Sdk\Ergonomic\MergeOptions;
use Gisl\Sdk\Ergonomic\PresetResolver;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\FilesRecipe;
use Gisl\Sdk\FileFirst\MergedRecipe;
use Gisl\Sdk\FileFirst\Recipe;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 0rb1QlUC — the ergonomic file-first CHAIN methods now accept an optional
 * per-op options bag (`array`), mirroring the op-first surface. This suite
 * pins the lowering of those options across {@see Recipe}, {@see FilesRecipe}
 * (fan-out), and {@see MergedRecipe} (post-combine), proving each surface
 * threads explicit options to the wire and that compress precedence matches
 * the shared op-first resolver. Mirrors the TS
 * `file-first-chain-options.test.ts`. Network-free.
 */
final class RecipeChainOptionsTest extends TestCase
{
    private const FILE_ID = 'file_0001';

    private function recipe(string $path): Recipe
    {
        return new Recipe(FileInput::path($path));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function operations(Recipe $recipe): array
    {
        $wire = $recipe->toWorkflowPayload(self::FILE_ID)->toWire();
        self::assertIsArray($wire['jobs']);
        self::assertCount(1, $wire['jobs']);
        $job = $wire['jobs'][0];
        self::assertIsArray($job);
        self::assertIsArray($job['operations']);

        /** @var list<array<string, mixed>> $operations */
        $operations = $job['operations'];

        return $operations;
    }

    /**
     * @param 'image'|'audio'|'video'|'document_office'|'document_odf'|'document_epub' $media
     * @param array<string, mixed> $explicit
     * @param array<string, mixed>|null $presetOverrides
     *
     * @return array<string, mixed>
     */
    private function expectedCompressWire(
        string $media,
        OptimizeFor $optimize,
        array $explicit = [],
        ?array $presetOverrides = null,
    ): array {
        return PresetResolver::resolveCompress(
            media: $media,
            presetDefaults: null,
            scopedDefaults: null,
            presetOverrides: $presetOverrides,
            optimize: $optimize,
            explicitOptions: $explicit,
        )['wireOptions'];
    }

    // --- 1. Explicit options reach the wire ---------------------------------

    #[Test]
    public function convert_lowers_shorthand_to_output_format_and_merges_the_bag(): void
    {
        // Image convert legitimately carries two passthrough keys (quality + background)
        // — video convert is only { output_format, crf? } since v2.81.0.
        $ops = $this->operations($this->recipe('photo.jpg')->convert('webp', ['quality' => 90, 'background' => '#ffffff']));
        // The shorthand $format lowers to the `output_format` wire key (contract:
        // convert.yaml) and is spread LAST (authoritative). Assert membership
        // order-independently so this does not couple to insertion order.
        self::assertCount(1, $ops);
        self::assertSame('convert', $ops[0]['type']);
        self::assertEqualsCanonicalizing(
            ['output_format' => 'webp', 'quality' => 90, 'background' => '#ffffff'],
            $ops[0]['options'],
        );
    }

    #[Test]
    public function convert_carries_image_resize_keys_to_the_wire(): void
    {
        // convert.image is the resize engine since v2.103.0: width/height/fit are
        // first-class convert options (stable, honored_on format_change).
        $ops = $this->operations($this->recipe('photo.png')->convert('jpeg', ['width' => 800, 'height' => 600, 'fit' => 'max', 'quality' => 85]));
        self::assertCount(1, $ops);
        self::assertSame('convert', $ops[0]['type']);
        self::assertEqualsCanonicalizing(
            ['output_format' => 'jpeg', 'width' => 800, 'height' => 600, 'fit' => 'max', 'quality' => 85],
            $ops[0]['options'],
        );
    }

    #[Test]
    public function convert_carries_the_planned_image_keys_to_the_wire(): void
    {
        // convert.image gained `metadata` (v2.106.0) + `color_profile`/`auto_orient`
        // (v2.112.0) — all PLANNED on convert; convert() is a raw passthrough, so the
        // keys reach the wire verbatim (the API gates the planned values).
        $ops = $this->operations($this->recipe('photo.png')->convert('jpeg', ['metadata' => 'strip', 'color_profile' => 'srgb', 'auto_orient' => true]));
        self::assertCount(1, $ops);
        self::assertSame('convert', $ops[0]['type']);
        // assertEquals (not Canonicalizing): assoc-array compare is order-independent,
        // and the `auto_orient` boolean would break Canonicalizing's value sort.
        self::assertEquals(
            ['output_format' => 'jpeg', 'metadata' => 'strip', 'color_profile' => 'srgb', 'auto_orient' => true],
            $ops[0]['options'],
        );
    }

    #[Test]
    public function thumbnail_carries_all_defined_keys(): void
    {
        // Both dims are required now; the extra keys (fit/format) still pass through.
        $ops = $this->operations(
            $this->recipe('photo.jpg')->thumbnail(['width' => 200, 'height' => 150, 'fit' => 'cover', 'format' => 'webp']),
        );
        self::assertSame(
            [['type' => 'thumbnail', 'options' => ['width' => 200, 'height' => 150, 'fit' => 'cover', 'format' => 'webp']]],
            $ops,
        );
    }

    #[Test]
    public function thumbnail_drops_a_null_value_on_an_optional_key(): void
    {
        // Both required dims are present; a null OPTIONAL key (format) is dropped.
        // The both-required gate forbids dropping a dimension via null — so the
        // null-drop is exercised on an optional key. PHP drops `null` (TS drops
        // `undefined`); the lowered wire stays byte-identical across languages.
        $ops = $this->operations(
            $this->recipe('photo.jpg')->thumbnail(['width' => 200, 'height' => 150, 'format' => null]),
        );
        self::assertSame([['type' => 'thumbnail', 'options' => ['width' => 200, 'height' => 150]]], $ops);
        self::assertArrayNotHasKey('format', $ops[0]['options']);
    }

    #[Test]
    public function text_watermark_merges_the_options_bag_after_text(): void
    {
        $ops = $this->operations(
            $this->recipe('photo.jpg')->textWatermark('hi', ['anchor' => 'bottom_right', 'opacity' => 0.5]),
        );
        // The explicit $text is spread LAST (authoritative); assert membership
        // order-independently rather than coupling to insertion order.
        self::assertCount(1, $ops);
        self::assertSame('text_watermark', $ops[0]['type']);
        self::assertEqualsCanonicalizing(
            ['text' => 'hi', 'anchor' => 'bottom_right', 'opacity' => 0.5],
            $ops[0]['options'],
        );
    }

    // --- 1b. Positional-owned bag keys are REJECTED (Dhje3Faq) ---------------
    // These verbs OWN their output via a positional argument; supplying the same
    // key in the options bag now THROWS at the verb call (was silently
    // overridden/dropped before). Eager + pre-upload — the throw fires
    // synchronously at the chain method, no network.

    #[Test]
    public function convert_rejects_an_output_format_bag_key(): void
    {
        try {
            $this->recipe('clip.mov')->convert('mp4', ['output_format' => 'webm']);
            self::fail('convert() with an output_format bag key must throw');
        } catch (GislConfigError $err) {
            self::assertSame('unknown_field', $err->reason);
            self::assertSame(['output_format'], $err->conflictingFields);
        }
    }

    #[Test]
    public function convert_rejects_a_legacy_format_bag_key(): void
    {
        try {
            $this->recipe('clip.mov')->convert('mp4', ['format' => 'legacy', 'crf' => 23]);
            self::fail('convert() with a stray format bag key must throw');
        } catch (GislConfigError $err) {
            self::assertSame('unknown_field', $err->reason);
            self::assertSame(['format'], $err->conflictingFields);
        }
    }

    #[Test]
    public function text_watermark_rejects_a_text_bag_key(): void
    {
        try {
            $this->recipe('photo.jpg')->textWatermark('real', ['text' => 'fake']);
            self::fail('textWatermark() with a text bag key must throw');
        } catch (GislConfigError $err) {
            self::assertSame('unknown_field', $err->reason);
            self::assertSame(['text'], $err->conflictingFields);
        }
    }

    #[Test]
    public function merged_convert_rejects_an_output_format_bag_key(): void
    {
        // MergedRecipe arm — the duplicated post-combine convert body validates too.
        $merged = new MergedRecipe(
            [FileInput::path('a.mp4'), FileInput::path('b.mp4')],
            new MergeOptions(mediaKind: 'video'),
        );
        try {
            $merged->convert('mp4', ['output_format' => 'webm']);
            self::fail('MergedRecipe::convert() with an output_format bag key must throw');
        } catch (GislConfigError $err) {
            self::assertSame('unknown_field', $err->reason);
            self::assertSame(['output_format'], $err->conflictingFields);
        }
    }

    // --- 2. compress precedence mirrors the op-first resolver ----------------

    #[Test]
    public function compress_explicit_option_overrides_the_preset(): void
    {
        $expected = $this->expectedCompressWire('image', OptimizeFor::Balanced, explicit: ['quality' => 55]);

        $ops = $this->operations($this->recipe('photo.jpg')->compress(OptimizeFor::Balanced, ['quality' => 55]));
        self::assertSame('compress', $ops[0]['type']);
        self::assertSame($expected, $ops[0]['options']);
        self::assertSame(55, $ops[0]['options']['quality'], 'explicit quality:55 wins over the Balanced preset quality');
    }

    #[Test]
    public function compress_preset_overrides_route_through_the_override_layer(): void
    {
        $overrides = ['quality' => 42];
        $expected = $this->expectedCompressWire('image', OptimizeFor::Balanced, presetOverrides: $overrides);

        $ops = $this->operations(
            $this->recipe('photo.jpg')->compress(OptimizeFor::Balanced, ['presetOverrides' => $overrides]),
        );
        self::assertSame($expected, $ops[0]['options']);
        self::assertSame(42, $ops[0]['options']['quality'], 'the override landed, not the shipped preset quality');
        self::assertArrayNotHasKey('presetOverrides', $ops[0]['options'], 'presetOverrides is a layer, not a wire key');
    }

    #[Test]
    public function compress_explicit_optimize_param_wins_over_an_optimize_key_in_the_bag(): void
    {
        // The Size param must beat optimize:Balanced inside the bag.
        $expected = $this->expectedCompressWire('image', OptimizeFor::Size);

        $ops = $this->operations(
            $this->recipe('photo.jpg')->compress(OptimizeFor::Size, ['optimize' => OptimizeFor::Balanced]),
        );
        self::assertSame($expected, $ops[0]['options']);
        self::assertArrayNotHasKey('optimize', $ops[0]['options'], 'optimize is consumed as a layer, never a wire key');
    }

    // --- 2b. Codex regression guards (chain-options PHP parity bugs) ---------

    #[Test]
    public function compress_omitted_param_preserves_a_bag_supplied_optimize(): void
    {
        // Codex bug 1: when the shorthand $optimize param is OMITTED but the bag
        // carries `optimize`, the bag value must be PRESERVED and resolved — it
        // was previously nulled, skipping preset resolution entirely. The
        // bag-only form MUST lower identically to the shorthand form.
        $viaBag = $this->operations(
            $this->recipe('photo.jpg')->compress(null, ['optimize' => OptimizeFor::Balanced]),
        );
        $viaShorthand = $this->operations($this->recipe('photo.jpg')->compress(OptimizeFor::Balanced));

        self::assertSame($viaShorthand[0]['options'], $viaBag[0]['options'], 'bag optimize lowers identically to the shorthand');
        // A preset field IS present (resolution ran), not skipped — the Balanced
        // image cell carries quality (see ff_lowering_single_compress.yaml).
        self::assertArrayHasKey('quality', $viaBag[0]['options'], 'the bag-supplied preset was resolved, not skipped');
        self::assertSame(80, $viaBag[0]['options']['quality']);
    }

    #[Test]
    public function compress_shorthand_param_still_wins_over_a_bag_optimize(): void
    {
        // The fix for bug 1 must NOT regress the precedence: when BOTH the
        // shorthand param and a bag `optimize` are set, the PARAM wins (Size),
        // not the bag (Balanced).
        $expectedSize = $this->expectedCompressWire('image', OptimizeFor::Size);
        $expectedBalanced = $this->expectedCompressWire('image', OptimizeFor::Balanced);

        $ops = $this->operations(
            $this->recipe('photo.jpg')->compress(OptimizeFor::Size, ['optimize' => OptimizeFor::Balanced]),
        );
        self::assertSame($expectedSize, $ops[0]['options'], 'the Size param wins');
        self::assertNotSame($expectedBalanced, $ops[0]['options'], 'the Balanced bag value did NOT win');
    }

    #[Test]
    public function compress_bag_supplied_invalid_optimize_throws_invalid_optimize(): void
    {
        // PHP parity for the codex r3 TS gap: a bag-supplied invalid optimize
        // (param omitted) is rejected with `invalid_optimize`. In PHP this fires
        // in coerceOptimize at the compress() CALL (the bag value flows through
        // the same coercion as the shorthand), not at lowering — but the typed
        // error + reason match the TS lowering-time guard.
        $this->expectException(GislConfigError::class);
        try {
            $this->recipe('photo.jpg')->compress(null, ['optimize' => 'Bogus']);
        } catch (GislConfigError $err) {
            self::assertSame('invalid_optimize', $err->reason);
            throw $err;
        }
    }

    #[Test]
    public function compress_valid_object_preset_overrides_resolves_without_throwing(): void
    {
        // Sanity parity: a valid override (quality:40) resolves and reaches the
        // resolver — only null / scalar / array are rejected.
        $expected = $this->expectedCompressWire('image', OptimizeFor::Balanced, presetOverrides: ['quality' => 40]);

        $ops = $this->operations(
            $this->recipe('photo.jpg')->compress(OptimizeFor::Balanced, ['presetOverrides' => ['quality' => 40]]),
        );
        self::assertSame($expected, $ops[0]['options']);
        self::assertSame(40, $ops[0]['options']['quality'], 'the override landed, not the Balanced preset default (80)');
    }

    #[Test]
    public function compress_scalar_preset_overrides_throws_invalid_preset_overrides(): void
    {
        // Codex bug 2: file-first lowerCompressOptions now routes presetOverrides
        // through OperationBuilder::normalisePresetOverrides(), which THROWS for a
        // scalar (non-array / non-object) presetOverrides — previously it was
        // silently nulled. The throw fires at lowering time.
        $recipe = $this->recipe('photo.jpg')->compress(OptimizeFor::Balanced, ['presetOverrides' => 'not-an-array']);
        try {
            $recipe->toWorkflowPayload(self::FILE_ID);
            self::fail('a scalar presetOverrides must throw');
        } catch (GislConfigError $err) {
            self::assertSame('invalid_preset_overrides', $err->reason);
        }
    }

    #[Test]
    public function merged_compress_omitted_param_preserves_a_bag_supplied_optimize(): void
    {
        // Codex bug 1, MergedRecipe arm: merge().compress(null, ['optimize' => ...])
        // must resolve the bag-supplied preset on the merge job's post-compress
        // op (not skip it). Lowers identically to the shorthand form.
        $expected = $this->expectedCompressWire('video', OptimizeFor::Balanced);

        $payload = (new MergedRecipe(
            [FileInput::path('a.mp4'), FileInput::path('b.mp4')],
            new MergeOptions(mediaKind: 'video'),
        ))
            ->compress(null, ['optimize' => OptimizeFor::Balanced])
            ->toWorkflowPayload(['f0', 'f1'], null);

        $mergeJob = $payload->jobs[2]; // 2 src jobs + the merge job
        self::assertSame('merge', $mergeJob->id);
        self::assertCount(2, $mergeJob->operations);
        self::assertSame('compress', $mergeJob->operations[1]->type);
        $compressOptions = $mergeJob->operations[1]->options;
        self::assertIsArray($compressOptions);
        self::assertSame($expected, $compressOptions, 'the bag-supplied Balanced preset was resolved on the merge job');
        self::assertNotEmpty($compressOptions, 'preset resolution ran — options are not empty/skipped');
    }

    // --- 3. Media-undefined passthrough -------------------------------------

    #[Test]
    public function compress_without_optimize_on_media_unknown_passes_options_through_verbatim(): void
    {
        // A bare upload-id has no inferable media → no preset resolution. With NO
        // optimize, the explicit bag passes through verbatim (no throw).
        $ops = $this->operations(
            (new Recipe(FileInput::uploadId('uploaded-xyz')))->compress(null, ['crf' => 23]),
        );
        self::assertSame([['type' => 'compress', 'options' => ['crf' => 23]]], $ops);
    }

    #[Test]
    public function compress_with_optimize_on_media_unknown_still_throws_media_unknown(): void
    {
        $recipe = (new Recipe(FileInput::uploadId('uploaded-xyz')))->compress(OptimizeFor::Balanced, ['crf' => 23]);
        try {
            $recipe->toWorkflowPayload(self::FILE_ID);
            self::fail('compress(optimize) on a media-unknown input must throw');
        } catch (GislConfigError $err) {
            self::assertSame('media_unknown', $err->reason);
        }
    }

    #[Test]
    public function compress_on_pdf_fails_fast_pdf_compress_removed(): void
    {
        // PDF is a DETECTABLE media (document_pdf) but no longer compressible
        // (contracts v2.166.0 dropped PDF lossy compress). Unlike a truly
        // media-unknown input, a PDF fails fast with a specific, actionable
        // message even WITHOUT optimize — mirrors the TS builder fail-fast test.
        $recipe = (new Recipe(FileInput::path('report.pdf')))->compress(null, ['crf' => 23]);
        try {
            $recipe->toWorkflowPayload(self::FILE_ID);
            self::fail('compress() on a PDF must fail fast (PDF compression removed)');
        } catch (GislConfigError $err) {
            self::assertSame('unsupported_media', $err->reason);
            self::assertStringContainsString(
                'PDF compression was removed at contracts v2.166.0',
                $err->getMessage(),
            );
        }
    }

    #[Test]
    public function compress_with_preset_overrides_on_media_unknown_throws_media_unknown(): void
    {
        // Codex r2 bug 2: presetOverrides override a RESOLVED preset; with no
        // inferable media there is no preset to override, so it now FAILS FAST
        // (media_unknown) instead of silently dropping the override. Same input
        // as the optimize throw above, presetOverrides instead of optimize.
        $recipe = (new Recipe(FileInput::uploadId('uploaded-xyz')))
            ->compress(null, ['presetOverrides' => ['quality' => 50]]);
        try {
            $recipe->toWorkflowPayload(self::FILE_ID);
            self::fail('compress(presetOverrides) on a media-unknown input must throw');
        } catch (GislConfigError $err) {
            self::assertSame('media_unknown', $err->reason);
        }
    }

    // --- 4. Minimal forms unchanged (regression guard) ----------------------

    #[Test]
    public function compress_balanced_minimal_form_matches_the_resolver(): void
    {
        $expected = $this->expectedCompressWire('image', OptimizeFor::Balanced);
        self::assertSame($expected, $this->operations($this->recipe('photo.jpg')->compress(OptimizeFor::Balanced))[0]['options']);
    }

    #[Test]
    public function bare_compress_on_an_extensionless_path_emits_no_options_key(): void
    {
        $ops = $this->operations($this->recipe('photo')->compress());
        self::assertSame([['type' => 'compress']], $ops);
        self::assertArrayNotHasKey('options', $ops[0]);
    }

    #[Test]
    public function convert_with_no_bag_carries_only_output_format(): void
    {
        self::assertSame(
            [['type' => 'convert', 'options' => ['output_format' => 'png']]],
            $this->operations($this->recipe('clip.mov')->convert('png')),
        );
    }

    #[Test]
    public function thumbnail_with_no_extra_keys_is_unchanged(): void
    {
        self::assertSame(
            [['type' => 'thumbnail', 'options' => ['width' => 100, 'height' => 100]]],
            $this->operations($this->recipe('photo.jpg')->thumbnail(['width' => 100, 'height' => 100])),
        );
    }

    #[Test]
    public function text_watermark_with_no_bag_carries_only_the_text(): void
    {
        self::assertSame(
            [['type' => 'text_watermark', 'options' => ['text' => 'x']]],
            $this->operations($this->recipe('photo.jpg')->textWatermark('x')),
        );
    }

    // --- 5. FilesRecipe (fan-out) + MergedRecipe carry options --------------

    #[Test]
    public function files_convert_carries_the_option_into_every_job(): void
    {
        $jobs = (new FilesRecipe([FileInput::path('a.mov'), FileInput::path('b.mov')]))
            ->convert('mp4', ['crf' => 23])
            ->toWorkflowPayload(['f0', 'f1'])
            ->toWire()['jobs'];

        self::assertIsArray($jobs);
        self::assertCount(2, $jobs);
        foreach ($jobs as $job) {
            self::assertIsArray($job);
            self::assertIsArray($job['operations']);
            self::assertCount(1, $job['operations']);
            self::assertSame('convert', $job['operations'][0]['type']);
            // $format is spread LAST (authoritative); assert order-independently.
            self::assertEqualsCanonicalizing(
                ['output_format' => 'mp4', 'crf' => 23],
                $job['operations'][0]['options'],
            );
        }
    }

    #[Test]
    public function files_compress_explicit_override_reaches_every_job(): void
    {
        $expected = $this->expectedCompressWire('image', OptimizeFor::Balanced, explicit: ['quality' => 55]);

        $jobs = (new FilesRecipe([FileInput::path('a.jpg'), FileInput::path('b.jpg')]))
            ->compress(OptimizeFor::Balanced, ['quality' => 55])
            ->toWorkflowPayload(['f0', 'f1'])
            ->toWire()['jobs'];

        self::assertIsArray($jobs);
        foreach ($jobs as $job) {
            self::assertIsArray($job);
            self::assertIsArray($job['operations']);
            self::assertSame(['type' => 'compress', 'options' => $expected], $job['operations'][0]);
            self::assertSame(55, $job['operations'][0]['options']['quality']);
        }
    }

    #[Test]
    public function files_thumbnail_carries_extra_keys_into_every_job(): void
    {
        $jobs = (new FilesRecipe([FileInput::path('a.jpg'), FileInput::path('b.jpg')]))
            ->thumbnail(['width' => 200, 'height' => 150, 'fit' => 'cover'])
            ->toWorkflowPayload(['f0', 'f1'])
            ->toWire()['jobs'];

        self::assertIsArray($jobs);
        foreach ($jobs as $job) {
            self::assertIsArray($job);
            self::assertSame(
                [['type' => 'thumbnail', 'options' => ['width' => 200, 'height' => 150, 'fit' => 'cover']]],
                $job['operations'],
            );
        }
    }

    #[Test]
    public function merged_compress_carries_crf_onto_the_merge_job(): void
    {
        // Merged media is video, so use a video-valid knob (crf), not image quality.
        $expected = $this->expectedCompressWire('video', OptimizeFor::Balanced, explicit: ['crf' => 28]);

        $payload = (new MergedRecipe(
            [FileInput::path('a.mp4'), FileInput::path('b.mp4')],
            new MergeOptions(mediaKind: 'video'),
        ))
            ->compress(OptimizeFor::Balanced, ['crf' => 28])
            ->toWorkflowPayload(['f0', 'f1'], null);

        $mergeJob = $payload->jobs[2]; // 2 src jobs + the merge job
        self::assertSame('merge', $mergeJob->id);
        self::assertCount(2, $mergeJob->operations);
        self::assertSame('merge', $mergeJob->operations[0]->type);
        self::assertSame('compress', $mergeJob->operations[1]->type);
        self::assertEqualsCanonicalizing($expected, $mergeJob->operations[1]->options);
        $compressOptions = $mergeJob->operations[1]->options;
        self::assertIsArray($compressOptions);
        self::assertSame(28, $compressOptions['crf']);
    }

    #[Test]
    public function merged_convert_carries_the_bag_onto_the_merge_job(): void
    {
        $payload = (new MergedRecipe(
            [FileInput::path('a.mp4'), FileInput::path('b.mp4')],
            new MergeOptions(mediaKind: 'video'),
        ))
            ->convert('webm', ['crf' => 28])
            ->toWorkflowPayload(['f0', 'f1'], null);

        $mergeJob = $payload->jobs[2];
        self::assertSame('convert', $mergeJob->operations[1]->type);
        // $format is spread LAST (authoritative); assert order-independently.
        self::assertEqualsCanonicalizing(['output_format' => 'webm', 'crf' => 28], $mergeJob->operations[1]->options);
    }

    #[Test]
    public function merged_thumbnail_carries_extra_keys_onto_the_merge_job(): void
    {
        $payload = (new MergedRecipe(
            [FileInput::path('a.mp4'), FileInput::path('b.mp4')],
            new MergeOptions(mediaKind: 'video'),
        ))
            ->thumbnail(['width' => 320, 'height' => 240, 'format' => 'jpeg'])
            ->toWorkflowPayload(['f0', 'f1'], null);

        $mergeJob = $payload->jobs[2];
        self::assertSame('thumbnail', $mergeJob->operations[1]->type);
        self::assertSame(['width' => 320, 'height' => 240, 'format' => 'jpeg'], $mergeJob->operations[1]->options);
    }

    // --- chain media inference from prior step output (56N4chXY / N8eESzQN) ------
    // A chained compress() resolves its preset against the media a preceding
    // convert() produced, not the original input. The fold is context-aware: a
    // video->ogg convert keeps video (an OGG video container; `ogg` otherwise lands
    // in the audio extension list and would mis-resolve to audio). Mirrors the TS
    // suite in file-first-chain-options.test.ts.

    /**
     * @return array<string, mixed>
     */
    private function compressOptions(Recipe $recipe): array
    {
        foreach ($this->operations($recipe) as $op) {
            if (($op['type'] ?? null) === 'compress') {
                $opts = $op['options'] ?? [];
                self::assertIsArray($opts);

                return $opts;
            }
        }
        self::fail('expected a compress op in the lowered payload');
    }

    #[Test]
    public function chain_mp3_convert_flac_compress_resolves_lossless_drops_bitrate(): void
    {
        $opts = $this->compressOptions(
            $this->recipe('song.mp3')->convert('flac')->compress(OptimizeFor::Size),
        );
        self::assertArrayNotHasKey('bitrate', $opts); // lossless flac: bitrate dropped (worker 422s it)
        self::assertSame(44100, $opts['sample_rate']);
        self::assertTrue($opts['normalize']);
    }

    #[Test]
    public function chain_mp3_convert_aac_compress_stays_lossy_keeps_bitrate(): void
    {
        $opts = $this->compressOptions(
            $this->recipe('song.mp3')->convert('aac')->compress(OptimizeFor::Size),
        );
        self::assertSame(96, $opts['bitrate']);
    }

    #[Test]
    public function chain_video_convert_ogg_compress_stays_video_not_audio(): void
    {
        $opts = $this->compressOptions(
            $this->recipe('clip.mp4')->convert('ogg')->compress(OptimizeFor::Size),
        );
        self::assertSame(30, $opts['crf']); // video Size preset (the ogg guard)
        self::assertSame('crf', $opts['encoding_mode']);
        self::assertArrayNotHasKey('bitrate', $opts);
    }

    #[Test]
    public function chain_video_convert_gif_compress_resolves_image(): void
    {
        $opts = $this->compressOptions(
            $this->recipe('clip.mp4')->convert('gif')->compress(OptimizeFor::Size),
        );
        self::assertSame(65, $opts['quality']); // image Size preset
        self::assertArrayNotHasKey('crf', $opts);
    }

    #[Test]
    public function chain_no_convert_resolves_against_original_input(): void
    {
        $opts = $this->compressOptions(
            $this->recipe('song.mp3')->compress(OptimizeFor::Size),
        );
        self::assertSame(96, $opts['bitrate']); // mp3 lossy — original behaviour
    }

    #[Test]
    public function merge_then_convert_flac_then_compress_resolves_lossless(): void
    {
        $payload = (new MergedRecipe(
            [FileInput::path('a.mp3'), FileInput::path('b.mp3')],
            new MergeOptions(mediaKind: 'audio'),
        ))
            ->convert('flac')
            ->compress(OptimizeFor::Size)
            ->toWorkflowPayload(['f0', 'f1'], null);

        $mergeJob = $payload->jobs[count($payload->jobs) - 1];
        $compress = null;
        foreach ($mergeJob->operations as $op) {
            if ($op->type === 'compress') {
                $compress = $op;
                break;
            }
        }
        self::assertNotNull($compress);
        self::assertIsArray($compress->options);
        self::assertArrayNotHasKey('bitrate', $compress->options); // post-merge flac output
    }

    // Multi-convert: the MOST RECENT preceding convert wins (the fold loop runs >once).
    #[Test]
    public function chain_mp3_flac_then_aac_compress_most_recent_aac_wins_keeps_bitrate(): void
    {
        $opts = $this->compressOptions(
            $this->recipe('song.mp3')->convert('flac')->convert('aac')->compress(OptimizeFor::Size),
        );
        self::assertSame(96, $opts['bitrate']);
    }

    #[Test]
    public function chain_mp3_aac_then_flac_compress_most_recent_flac_wins_drops_bitrate(): void
    {
        $opts = $this->compressOptions(
            $this->recipe('song.mp3')->convert('aac')->convert('flac')->compress(OptimizeFor::Size),
        );
        self::assertArrayNotHasKey('bitrate', $opts);
    }

    #[Test]
    public function chain_mp3_convert_wav_compress_is_lossless_drops_bitrate(): void
    {
        $opts = $this->compressOptions(
            $this->recipe('song.mp3')->convert('wav')->compress(OptimizeFor::Size),
        );
        self::assertArrayNotHasKey('bitrate', $opts);
        self::assertSame(44100, $opts['sample_rate']);
    }
}
