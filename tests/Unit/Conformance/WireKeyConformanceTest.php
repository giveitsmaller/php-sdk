<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Conformance;

use Gisl\Generated\Operations\ArchiveMetadata;
use Gisl\Generated\Operations\CompressMetadata;
use Gisl\Generated\Operations\ConvertMetadata;
use Gisl\Generated\Operations\ImageWatermarkMetadata;
use Gisl\Generated\Operations\MergeMetadata;
use Gisl\Generated\Operations\OperationMetadata;
use Gisl\Generated\Operations\TextWatermarkMetadata;
use Gisl\Generated\Operations\ThumbnailMetadata;
use Gisl\Generated\Operations\VideoWatermarkMetadata;
use Gisl\Sdk\Ergonomic\OptionValidation;
use Gisl\Sdk\Ergonomic\ArchiveFormat;
use Gisl\Sdk\Ergonomic\ArchiveRecipeOptions;
use Gisl\Sdk\Ergonomic\MergeOptions;
use Gisl\Sdk\Ergonomic\PresetResolver;
use Gisl\Sdk\FileFirst\ArchivedRecipe;
use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\MergedRecipe;
use Gisl\Sdk\FileFirst\Recipe;
use Gisl\Sdk\OperationDef;
use Gisl\Sdk\WorkflowCreatePayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Wire-key conformance guard (card y2qOUp90) — PHP arm; mirrors the TS
 * `wire-key-conformance.test.ts`.
 *
 * The convert `format`→`output_format` bug (FE-caught 2026-06-14, fixed in
 * PR #204/#205) slipped through because the ergonomic builders' lowered wire
 * keys were never systematically validated against the contract operation
 * schemas — only compress went through a contract-checked resolver.
 *
 * This suite turns "we hope the keys match" into a CI gate: for every
 * ergonomic op it collects the wire option keys the SDK can emit and asserts
 * each one is a real contract option key, read from the generated, in-repo
 * {@see OperationMetadata} sidecars (regenerated from the contract; their
 * `options` keys are the verbatim contract wire keys).
 */
final class WireKeyConformanceTest extends TestCase
{
    private const FILE_ID = 'file_0001';

    /**
     * OPERATION-LEVEL contract option keys (valid in `OperationDef::$options`):
     * the union of every mime group's `options` plus `direct_options` for
     * media-agnostic ops (archive has no mime_groups — its keys live only in
     * `direct_options`). Deliberately EXCLUDES `per_input_options`: those are
     * valid only on a merge input's `per_input_options`, never at operation
     * level, so folding them in would let a misplaced per-input key pass.
     *
     * @return array<string, true>
     */
    private function operationOptionKeys(OperationMetadata $metadata): array
    {
        $keys = [];
        foreach ($metadata->mime_groups as $group) {
            foreach (array_keys($group->options) as $k) {
                $keys[$k] = true;
            }
        }
        foreach (array_keys($metadata->direct_options) as $k) {
            $keys[$k] = true;
        }

        return $keys;
    }

    /**
     * Operation-level option keys for ONE mime group (media-precise). Used for
     * merge, whose option sets differ per media kind — validating against the
     * specific media group catches a wrong-media or per-input-only key emitted
     * at merge level.
     *
     * @return array<string, true>
     */
    private function mediaGroupOptionKeys(OperationMetadata $metadata, string $kind): array
    {
        self::assertArrayHasKey($kind, $metadata->mime_groups, "metadata has a '{$kind}' mime group");

        $keys = [];
        foreach (array_keys($metadata->mime_groups[$kind]->options) as $k) {
            $keys[$k] = true;
        }

        return $keys;
    }

    /**
     * The contract mime_group keys that make up the image FAMILY: the single
     * `image` group plus every `image_*` (image_jpeg / image_png / image_avif).
     * The SDK's one format-agnostic `image` media maps to this whole family.
     *
     * @return list<string>
     */
    private function imageFamilyGroups(OperationMetadata $metadata): array
    {
        return array_values(array_filter(
            array_keys($metadata->mime_groups),
            static fn (string $g): bool => $g === 'image' || str_starts_with($g, 'image_'),
        ));
    }

    /**
     * The contract option surface for a single SDK media: the UNION of every
     * image-family group for `image`, else the one same-named mime group.
     * mediaGroupOptionKeys asserts the group exists → a renamed/dropped
     * PresetMedia<->mime_group mapping fails loudly rather than silently skipping.
     *
     * @return array<string, true>
     */
    private function contractOptionKeysForMedia(string $media): array
    {
        if ($media === 'image') {
            $keys = [];
            foreach ($this->imageFamilyGroups(CompressMetadata::instance()) as $group) {
                foreach (array_keys($this->mediaGroupOptionKeys(CompressMetadata::instance(), $group)) as $k) {
                    $keys[$k] = true;
                }
            }

            return $keys;
        }

        return $this->mediaGroupOptionKeys(CompressMetadata::instance(), $media);
    }

    /**
     * @param list<string>        $emitted
     * @param array<string, true> $contract
     */
    private function assertKeysConform(string $opType, array $emitted, array $contract): void
    {
        $stray = array_values(array_filter($emitted, static fn (string $k): bool => !isset($contract[$k])));
        $allowed = array_keys($contract);
        sort($allowed);
        self::assertSame(
            [],
            $stray,
            \sprintf(
                '%s: emitted wire option key(s) %s are not in the contract option set %s',
                $opType,
                \json_encode($stray),
                \json_encode($allowed),
            ),
        );
    }

    /**
     * The option keys of the op named `$type` in the LAST job of a payload
     * (single-input chains have one job; merge/archive append the op job last).
     *
     * @return list<string>
     */
    private function optionKeysOf(WorkflowCreatePayload $payload, string $type): array
    {
        $job = $payload->jobs[count($payload->jobs) - 1];
        $match = null;
        foreach ($job->operations as $op) {
            \assert($op instanceof OperationDef);
            if ($op->type === $type) {
                $match = $op;
                break;
            }
        }
        self::assertNotNull($match, "expected a '{$type}' op in the lowered payload");

        return array_keys($match->options ?? []);
    }

    // --- compress -----------------------------------------------------------

    /**
     * Pin KNOWN_WIRE_FIELDS to the contract PER MEDIA (not union-wide) so a key
     * parked under the WRONG media (e.g. a PDF `grayscale` listed under
     * document_office) is caught too — the previous union check let any such key
     * through as long as some media owned it. Mirrors the TS per-media forward check.
     */
    #[Test]
    public function every_known_wire_field_is_a_real_compress_contract_option_for_its_media(): void
    {
        foreach (PresetResolver::KNOWN_WIRE_FIELDS as $media => $fields) {
            $this->assertKeysConform(
                "compress[{$media}]",
                array_values($fields),
                $this->contractOptionKeysForMedia($media),
            );
        }
    }

    /**
     * Contract compress options the ergonomic resolver deliberately does NOT
     * expose. `output_format` on compress is the API-side "compress + change
     * format" facade surface (contracts VcPeRWdD / ADR-0021) — canonicalized to
     * a `convert` op server-side. Changing format is a `convert()` concern in
     * the ergonomic SDK, never an ergonomic `compress()` option, so compress's
     * `output_format` is omitted for every media REGARDLESS of its availability:
     *   - audio: ALL values flipped `stable` in contracts v2.78.0 (RTokti20) —
     *     now live, but still omitted here (format-change goes through `convert`).
     *   - video: non-`original` values are `per_value_availability: planned`
     *     (facade unbuilt for video) — omitted; exposing a planned value would
     *     let the resolver emit a field the worker can't honour.
     * (Image keeps `output_format` in KNOWN_WIRE_FIELDS — its preset emits the
     * stable `original` value — so it is NOT listed here.)
     *
     * Image format-specific knobs (contracts v2.80.0 honesty pass): compress.image
     * is now a 4-group family — image (webp/gif/svg/tiff), image_jpeg, image_png,
     * image_avif. The ergonomic resolver models image-compress with ONE
     * format-agnostic `image` media that emits only the options common to every
     * image input format (quality / metadata / output_format). The per-format
     * advanced knobs are NOT emitted by the format-agnostic path and are omitted
     * until per-input-format ergonomic options ship (tracked follow-up):
     *   - progressive        (image_jpeg only)
     *   - optimization_level (image_png only)
     *   - avif_speed         (image_avif only)
     *
     * Document `quality` (contracts v2.83.0 document-compress honesty pass): a
     * stable per-document-group quality knob the ergonomic document-compress
     * preset path does not expose yet (the document preset DTOs carry
     * profile/grayscale/strip_* but not `quality`) — omitted until
     * a document-quality ergonomic option ships (tracked follow-up).
     *
     * document_pdf `image_dpi` (contracts v2.96.0 Acrobat-PDF realignment
     * Lw1LseYr): the PDF preset DTO now carries only {profile, grayscale}.
     * `image_dpi` is a STABLE, worker-honored PER-CALL knob (not a preset cell) —
     * omitted until a per-call PDF-DPI ergonomic option ships (tracked follow-up).
     * The PDF `colorspace` / `pages` / `flatten_forms` are `planned` and live in
     * PLANNED_OMISSIONS below (drift-guarded), NOT here.
     *
     * @var array<string, list<string>>
     */
    private const INTENTIONALLY_OMITTED = [
        // image Output-facade knobs (contracts v2.97.0 tewB37Jg → v2.110.0): compress.image*
        // carries width/height/fit (Resize-inside-Output), lossless, encoding_mode +
        // target_size_bytes (target-size, STABLE since v2.108.0), chroma_subsampling (jpeg,
        // stable v2.110.0), and quality_preset (stable, v2.148.0). These are the image OUTPUT
        // facade surface — exposed/gated by the ergonomic output()/resize() verbs
        // (RecipeOutputTest), NOT the preset compress() verb. Their availability + route
        // gating is pinned by ImageOutputRouteConformanceTest (the projection honored/planned),
        // so they are omitted from compress() here.
        'image' => [
            'progressive', 'optimization_level', 'avif_speed',
            'width', 'height', 'fit', 'lossless',
            'encoding_mode', 'target_size_bytes', 'chroma_subsampling', 'quality_preset',
            'color_profile', 'auto_orient',
        ],
        'audio' => ['output_format'],
        'video' => ['output_format'],
        'document_pdf' => ['quality', 'image_dpi'],
        'document_office' => ['quality'],
        'document_odf' => ['quality'],
        'document_epub' => ['quality'],
    ];

    /**
     * PLANNED_OMISSIONS: contract compress options omitted SPECIFICALLY BECAUSE the
     * contract marks them `availability: 'planned'` (advertised-ahead, not yet read
     * by the worker, FE-hidden). Kept separate from INTENTIONALLY_OMITTED (stable-
     * but-deliberately-unexposed) so the drift-guard below can assert these are
     * STILL planned: if a future regen flips one to stable, the assertion fails and
     * forces a deliberate "expose it or reclassify it" decision instead of silently
     * leaving a now-live option unreachable. Mirrors the TS PLANNED_OMISSIONS.
     *
     * @var array<string, list<string>>
     */
    private const PLANNED_OMISSIONS = [
        'document_pdf' => ['colorspace', 'pages', 'flatten_forms'],
    ];

    /**
     * Reverse direction (7dUpPmDZ): the forward check only catches a contract key
     * REMOVED/renamed out from under KNOWN_WIRE_FIELDS. It does NOT catch a contract
     * key ADDED that KNOWN_WIRE_FIELDS lacks — the ergonomic resolver would throw
     * `unknown_field` on a field the wire accepts, silently lagging the contract.
     * Pin the reverse PER MEDIA so a new compress option fails CI until it is either
     * exposed (added to KNOWN_WIRE_FIELDS) or documented as intentionally omitted.
     */
    #[Test]
    public function every_compress_contract_option_per_media_is_known_or_documented_omission(): void
    {
        foreach (PresetResolver::KNOWN_WIRE_FIELDS as $media => $fields) {
            $contractForMedia = $this->contractOptionKeysForMedia($media);
            $allowed = [];
            foreach ($fields as $field) {
                $allowed[$field] = true;
            }
            foreach (self::INTENTIONALLY_OMITTED[$media] ?? [] as $omitted) {
                $allowed[$omitted] = true;
            }
            foreach (self::PLANNED_OMISSIONS[$media] ?? [] as $omitted) {
                $allowed[$omitted] = true;
            }
            $this->assertKeysConform("compress[{$media}]", array_keys($contractForMedia), $allowed);
        }
    }

    /**
     * Drift-guard for PLANNED_OMISSIONS (closes the blind spot codex flagged): an
     * option is allowed in the reverse check above purely because we asserted it is
     * `planned`. Pin that to the generated contract — if any is no longer
     * `availability: 'planned'` (i.e. it went live), this fails so the omission is
     * re-evaluated rather than silently masking a now-supported option. Mirrors TS.
     */
    #[Test]
    public function every_planned_omission_is_still_availability_planned(): void
    {
        foreach (self::PLANNED_OMISSIONS as $media => $opts) {
            $groups = CompressMetadata::instance()->mime_groups;
            self::assertArrayHasKey($media, $groups, "metadata has a '{$media}' mime group");
            foreach ($opts as $opt) {
                self::assertArrayHasKey($opt, $groups[$media]->options, "compress[{$media}].{$opt} exists in the contract");
                self::assertSame(
                    'planned',
                    $groups[$media]->options[$opt]->availability,
                    "compress[{$media}].{$opt} is listed in PLANNED_OMISSIONS but is no longer "
                    . "availability:'planned' — it likely went live; expose it in KNOWN_WIRE_FIELDS "
                    . 'or move it to INTENTIONALLY_OMITTED.',
                );
            }
        }
    }

    /**
     * Whole-media-group coverage (closes the blind spot one level up): the per-media
     * reverse check iterates KNOWN_WIRE_FIELDS keys, so a contract mime_group ABSENT
     * from KNOWN_WIRE_FIELDS would be skipped silently — the resolver would
     * unknown_field-throw on every option for that media while CI stays green.
     */
    #[Test]
    public function every_compress_contract_mime_group_is_covered_by_known_wire_fields(): void
    {
        $known = PresetResolver::KNOWN_WIRE_FIELDS;
        // Fold the image-family groups (image + image_*) into the SDK's single
        // `image` media: a group is covered if it has a same-named KNOWN_WIRE_FIELDS
        // entry, OR it is an image-family group and the SDK has an `image` entry.
        $uncovered = array_values(array_filter(
            array_keys(CompressMetadata::instance()->mime_groups),
            static fn (string $m): bool => !isset($known[$m])
                && !(isset($known['image']) && ($m === 'image' || str_starts_with($m, 'image_'))),
        ));
        self::assertSame(
            [],
            $uncovered,
            \sprintf(
                'contract compress mime_group(s) %s have no KNOWN_WIRE_FIELDS entry — the per-media '
                . 'reverse check silently skips them. Add the media to KNOWN_WIRE_FIELDS.',
                \json_encode($uncovered),
            ),
        );
    }

    // --- convert ------------------------------------------------------------

    #[Test]
    public function convert_lowers_the_format_shorthand_to_output_format_never_format(): void
    {
        $contract = $this->operationOptionKeys(ConvertMetadata::instance());
        $payload = (new Recipe(FileInput::path('photo.png')))
            ->convert('webp', ['quality' => 80, 'background' => '#ffffff'])
            ->toWorkflowPayload(self::FILE_ID);
        $keys = $this->optionKeysOf($payload, 'convert');

        self::assertContains('output_format', $keys);
        // Regression pin for the 06-14 bug: the ergonomic `format` must never reach the wire.
        self::assertNotContains('format', $keys);
        $this->assertKeysConform('convert', $keys, $contract);
    }

    #[Test]
    public function post_merge_convert_also_lowers_to_output_format(): void
    {
        $contract = $this->operationOptionKeys(ConvertMetadata::instance());
        $payload = (new MergedRecipe(
            [FileInput::path('a.mp4'), FileInput::path('b.mp4')],
            new MergeOptions(mediaKind: 'video'),
        ))->convert('webm')->toWorkflowPayload(['f0', 'f1'], null);
        $keys = $this->optionKeysOf($payload, 'convert');

        self::assertContains('output_format', $keys);
        self::assertNotContains('format', $keys);
        $this->assertKeysConform('convert', $keys, $contract);
    }

    // --- thumbnail ----------------------------------------------------------

    #[Test]
    public function thumbnail_passthrough_keys_conform_to_the_contract(): void
    {
        $contract = $this->operationOptionKeys(ThumbnailMetadata::instance());
        // thumbnail is open passthrough — callers supply contract keys directly.
        $payload = (new Recipe(FileInput::path('photo.png')))
            ->thumbnail(['width' => 320, 'height' => 240, 'fit' => 'crop', 'format' => 'png'])
            ->toWorkflowPayload(self::FILE_ID);
        $this->assertKeysConform('thumbnail', $this->optionKeysOf($payload, 'thumbnail'), $contract);
    }

    // --- text_watermark -----------------------------------------------------

    #[Test]
    public function text_watermark_injected_text_and_passthrough_keys_conform(): void
    {
        $contract = $this->operationOptionKeys(TextWatermarkMetadata::instance());
        $payload = (new Recipe(FileInput::path('photo.png')))
            ->textWatermark('(c) Acme', ['font_size' => 48, 'anchor' => 'bottom_right'])
            ->toWorkflowPayload(self::FILE_ID);
        $keys = $this->optionKeysOf($payload, 'text_watermark');

        self::assertContains('text', $keys); // SDK injects the positional text as the literal `text` wire key
        $this->assertKeysConform('text_watermark', $keys, $contract);
    }

    // --- merge --------------------------------------------------------------

    /**
     * @return iterable<string, array{MergeOptions}>
     */
    public static function mergeOptionsProvider(): iterable
    {
        // Fully-populated per media kind so every branch of wireMergeOptions fires.
        yield 'video' => [new MergeOptions(
            transition: 'crossfade',
            crossfadeDuration: 1.0,
            normalizeAudio: true,
            reEncodeMode: 'always',
            codec: 'h264',
            crf: 23,
            preset: 'medium',
            targetResolution: '1920x1080',
            targetSize: '10MB',
            output: 'video',
            mediaKind: 'video',
        )];
        yield 'audio' => [new MergeOptions(
            transition: 'crossfade',
            crossfadeDuration: 1.0,
            gapDuration: 0.5,
            normalizeAudio: true,
            output: 'audio',
            mediaKind: 'audio',
        )];
        yield 'image' => [new MergeOptions(
            transition: 'fade',
            transitionDuration: 0.5,
            fps: 30.0,
            durationPerImage: 3.0,
            delay: 500,
            loopCount: 0,
            output: 'video',
            videoFormat: 'mp4',
            mediaKind: 'image',
        )];
    }

    #[Test]
    #[DataProvider('mergeOptionsProvider')]
    public function merge_emitted_keys_conform_to_the_contract(MergeOptions $options): void
    {
        // Media-precise: validate against the specific media group's options so a
        // wrong-media or per-input-only key at merge level is rejected.
        $kind = $options->mediaKind;
        self::assertNotNull($kind, 'each merge fixture pins mediaKind');
        $contract = $this->mediaGroupOptionKeys(MergeMetadata::instance(), $kind);
        $payload = (new MergedRecipe([FileInput::path('a'), FileInput::path('b')], $options))
            ->toWorkflowPayload(['f0', 'f1'], null);
        $this->assertKeysConform("merge:{$kind}", $this->optionKeysOf($payload, 'merge'), $contract);
    }

    #[Test]
    public function merge_does_not_leak_sdk_only_options_to_the_wire(): void
    {
        $payload = (new MergedRecipe(
            [FileInput::path('a'), FileInput::path('b')],
            new MergeOptions(transition: 'crossfade', mediaKind: 'video', allowUnusedAssets: true),
        ))->toWorkflowPayload(['f0', 'f1'], null);
        $keys = $this->optionKeysOf($payload, 'merge');

        self::assertNotContains('mediaKind', $keys);
        self::assertNotContains('allowUnusedAssets', $keys);
    }

    /**
     * MERGE_INTENTIONALLY_OMITTED: op-level contract merge options the SDK
     * deliberately does NOT surface as a standalone MergeOptions field. Empty
     * today — every op-level key is reachable via a builder field (`output_type`
     * via output/outputType; `encoding_mode`+`target_size_bytes` via targetSize).
     * A FUTURE new contract merge option fails the reverse check below until it is
     * either exposed on MergeOptions (+ wireMergeOptions) or documented here.
     * Mirrors the TS `MERGE_INTENTIONALLY_OMITTED`.
     *
     * @var array<string, list<string>>
     */
    private const MERGE_INTENTIONALLY_OMITTED = [
        'video' => [],
        'audio' => [],
        'image' => [],
    ];

    /**
     * Reverse direction (9u5aS8tU): the forward check only catches an emitted key
     * that is NOT in the contract. It does NOT catch a contract operation-level
     * merge option the SDK FAILS to expose — the drift that lets a new merge
     * option (e.g. delay/re_encode_mode/target_resolution) silently go unreachable
     * while CI stays green. For each media kind, every op-level contract merge
     * option (`mime_groups[kind]->options` — per-input options excluded because we
     * read `->options` only) must be EMITTED by the maximal fixture OR listed in
     * MERGE_INTENTIONALLY_OMITTED. Mirrors the TS reverse merge check.
     */
    #[Test]
    #[DataProvider('mergeOptionsProvider')]
    public function every_op_level_merge_contract_option_is_exposed_or_documented_omission(MergeOptions $options): void
    {
        $kind = $options->mediaKind;
        self::assertNotNull($kind, 'each merge fixture pins mediaKind');
        $contract = $this->mediaGroupOptionKeys(MergeMetadata::instance(), $kind);
        $payload = (new MergedRecipe([FileInput::path('a'), FileInput::path('b')], $options))
            ->toWorkflowPayload(['f0', 'f1'], null);

        $emitted = [];
        foreach ($this->optionKeysOf($payload, 'merge') as $k) {
            $emitted[$k] = true;
        }
        foreach (self::MERGE_INTENTIONALLY_OMITTED[$kind] ?? [] as $omitted) {
            $emitted[$omitted] = true;
        }

        $unexposed = array_values(array_filter(
            array_keys($contract),
            static fn (string $k): bool => !isset($emitted[$k]),
        ));
        self::assertSame(
            [],
            $unexposed,
            \sprintf(
                'merge:%s: contract op-level option(s) %s are neither emitted by wireMergeOptions nor in '
                . "MERGE_INTENTIONALLY_OMITTED['%s'] — a new merge option would be silently unreachable. "
                . 'Expose it on MergeOptions (+ wireMergeOptions) or document the omission.',
                $kind,
                \json_encode($unexposed),
                $kind,
            ),
        );
    }

    // --- archive ------------------------------------------------------------

    #[Test]
    public function archive_emitted_keys_conform_to_the_contract(): void
    {
        $contract = $this->operationOptionKeys(ArchiveMetadata::instance());
        $payload = (new ArchivedRecipe(
            [FileInput::path('a.png'), FileInput::path('b.pdf')],
            new ArchiveRecipeOptions(format: ArchiveFormat::Zip, folderStructure: 'by_job'),
        ))->toWorkflowPayload(['f0', 'f1'], null);
        $this->assertKeysConform('archive', $this->optionKeysOf($payload, 'archive'), $contract);
    }

    // --- eager option-key validator conformance (card Dhje3Faq) -------------

    /**
     * The eager verb-option validator's allowed-key set per verb must equal the
     * op-wide contract option set, so a typo is rejected pre-upload but every real
     * contract key passes. `watermark` = image_watermark ∪ video_watermark (the
     * base media may be undetectable at the `.watermark()` call). Mirrors the TS
     * `option-key validation conformance` suite.
     *
     * @return iterable<string, array{0: string, 1: array<string, true>}>
     */
    public static function validatedVerbProvider(): iterable
    {
        $watermark = OptionValidation::operationOptionKeys(ImageWatermarkMetadata::instance())
            + OptionValidation::operationOptionKeys(VideoWatermarkMetadata::instance());

        yield 'convert' => ['convert', OptionValidation::operationOptionKeys(ConvertMetadata::instance())];
        yield 'thumbnail' => ['thumbnail', OptionValidation::operationOptionKeys(ThumbnailMetadata::instance())];
        yield 'textWatermark' => ['textWatermark', OptionValidation::operationOptionKeys(TextWatermarkMetadata::instance())];
        yield 'watermark' => ['watermark', $watermark];
    }

    /**
     * @param array<string, true> $expectedContract
     */
    #[Test]
    #[DataProvider('validatedVerbProvider')]
    public function validator_allowed_key_set_equals_the_contract_option_set(string $verb, array $expectedContract): void
    {
        $allowed = array_keys(OptionValidation::allowedKeysFor($verb));
        $expected = array_keys($expectedContract);
        sort($allowed);
        sort($expected);
        self::assertSame($expected, $allowed, "validator allowed-key set for '{$verb}' must equal the contract option set");
    }
}
