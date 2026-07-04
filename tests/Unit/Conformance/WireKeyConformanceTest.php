<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Conformance;

use Gisl\Generated\Operations\ArchiveMetadata;
use Gisl\Generated\Operations\ConvertMetadata;
use Gisl\Generated\Operations\ImageWatermarkMetadata;
use Gisl\Generated\Operations\MergeMetadata;
use Gisl\Generated\Operations\OperationMetadata;
use Gisl\Generated\Operations\TextWatermarkMetadata;
use Gisl\Generated\Operations\ThumbnailMetadata;
use Gisl\Generated\Operations\TransformMetadata;
use Gisl\Generated\Operations\VideoWatermarkMetadata;
use Gisl\Sdk\Ergonomic\OptionValidation;
use Gisl\Sdk\Ergonomic\ArchiveFormat;
use Gisl\Sdk\Ergonomic\ArchiveRecipeOptions;
use Gisl\Sdk\Ergonomic\MergeOptions;
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
 *
 * NOTE: `compress` conformance moved OUT of this suite (card 0fNO60BX) — it is now
 * owned by {@see CodeBuilderConformanceTest}, driven from the contract-authored
 * `code-builder-metadata.json` `sdk_exposure` gate (a single, stronger ledger). This
 * suite covers convert/thumbnail/text_watermark/merge/archive + the eager validator.
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

    // --- transform ----------------------------------------------------------

    #[Test]
    public function transform_passthrough_keys_conform_to_the_contract(): void
    {
        $contract = $this->operationOptionKeys(TransformMetadata::instance());
        // transform is open passthrough (rotate/flip) — callers supply contract keys directly.
        $payload = (new Recipe(FileInput::path('photo.jpg')))
            ->transform(['rotate' => 90, 'flip' => 'horizontal'])
            ->toWorkflowPayload(self::FILE_ID);
        $this->assertKeysConform('transform', $this->optionKeysOf($payload, 'transform'), $contract);
    }

    /**
     * Non-tautological key pin (codex T4 r2): PHP has no hand-maintained
     * `VERB_OPTION_KEYS` tuple like TS, so pin the transform contract key set to the
     * hand-expected `{flip, rotate}` — a future contract key add/remove fails CI here.
     */
    #[Test]
    public function transform_exposes_exactly_rotate_and_flip(): void
    {
        $keys = \array_keys($this->operationOptionKeys(TransformMetadata::instance()));
        \sort($keys);
        self::assertSame(['flip', 'rotate'], $keys, 'transform contract option keys drifted from {rotate, flip}');
    }

    /**
     * Availability drift tripwire (karen Gap B / codex T4 r2): transform is exposed AHEAD
     * of the worker — the op AND every option are `availability: planned` today. If a
     * future re-vendor flips it live, this fails, forcing a re-review of the passthrough
     * "planned -> server 422 until Lambdas ship" contract. Mirrors the TS tripwire.
     */
    #[Test]
    public function transform_is_still_availability_planned(): void
    {
        // Op-level `availability` is the reliable, cross-language-consistent signal (per-option
        // availability is null; the PHP generator also leaves mime_group availability null while
        // TS emits it — so op-level is the parity-safe pin). A live op omits this field, so when a
        // re-vendor flips transform live this fails, forcing a re-review of the passthrough gating.
        self::assertSame('planned', TransformMetadata::instance()->availability);
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
        yield 'transform' => ['transform', OptionValidation::operationOptionKeys(TransformMetadata::instance())];
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
