<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Conformance;

use Gisl\Generated\Operations\CompressMetadata;
use Gisl\Sdk\Ergonomic\MergeOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `per_class_availability` overlay pin (card `zJN6XIi5`). PHP arm of the TS
 * `per-class-availability-conformance.test.ts`.
 *
 * The contract can now scope an option's availability to a PROCESSING CLASS,
 * not just to a mime group. That is what lets the API refuse a long-form merge
 * carrying a target size at CREATE, with an honest 422, instead of the job
 * dying mid-execution — which is what happens on the published SDKs today.
 *
 * ⚠️ **THIS PIN DOES NOT GATE ANYTHING CLIENT-SIDE, DELIBERATELY.** Routing to
 * long-form is decided SERVER-SIDE at create-plan time from summed input
 * duration. The SDK cannot know which path a merge will take, and the ticket
 * explicitly forbids a duration heuristic — it would be wrong at the boundary
 * and would diverge from the server resolver the moment either changed. If the
 * gate cannot be exact it belongs server-side.
 *
 * What this suite protects is narrower and still worth having: **the SDK's
 * user-facing documentation makes a factual claim about this overlay.** The
 * MERGE `$targetSize` docblocks tell callers target-size is unavailable on the
 * long-form path. If contracts ever flips `long_form_re_encode` to available —
 * Phase 3 two-pass target-size is a real roadmap item — those docblocks become
 * lies and nothing else here would notice.
 *
 * ⚠️ **COMPRESS IS A DIFFERENT AND WORSE CASE, PINNED SEPARATELY BELOW.** The
 * overlay covers `merge` ONLY — compress has NO `per_class_availability` at
 * all, so two of the four `$targetSize` docblocks describe a limitation the
 * contract does not express anywhere.
 */
#[CoversClass(MergeOptions::class)]
final class PerClassAvailabilityConformanceTest extends TestCase
{
    private const LONG_FORM_CLASS = 'long_form_re_encode';

    /** @var array<string, mixed> `operations.merge.mime_groups.video.options`. */
    private static array $mergeVideoOptions = [];

    public static function setUpBeforeClass(): void
    {
        // operations/src/CompressMetadata.php -> dirname x3 = generated/php root.
        $opFile = (new \ReflectionClass(CompressMetadata::class))->getFileName();
        $root = \dirname((string) $opFile, 3);
        $avail = \json_decode((string) \file_get_contents($root . '/availability/availability.json'), true);
        self::assertIsArray($avail, 'availability.json must decode to an array');

        $options = $avail['operations']['merge']['mime_groups']['video']['options'] ?? null;
        self::assertIsArray(
            $options,
            'merge.mime_groups.video.options is missing — every assertion in this class indexes '
            . 'into it and would pass VACUOUSLY against an empty map, which is exactly what a '
            . 'renamed operation or mime group produces.',
        );
        self::$mergeVideoOptions = $options;
    }

    #[Test]
    public function the_merge_video_option_set_is_readable_at_all(): void
    {
        // Positive control, asserted rather than assumed.
        self::assertNotSame([], self::$mergeVideoOptions);
        self::assertArrayHasKey('encoding_mode', self::$mergeVideoOptions);
    }

    #[Test]
    public function encoding_mode_is_scoped_to_the_long_form_class_as_planned(): void
    {
        // Rejected by PRESENCE on the long-form path, so the WHOLE option
        // carries the overlay — not merely the target_size value.
        $perClass = self::$mergeVideoOptions['encoding_mode']['per_class_availability'] ?? null;
        self::assertIsArray($perClass);
        self::assertSame('planned', $perClass[self::LONG_FORM_CLASS]['availability'] ?? null);
    }

    #[Test]
    public function the_target_size_value_is_scoped_to_the_long_form_class_as_planned(): void
    {
        $perValue = self::$mergeVideoOptions['encoding_mode']['per_value_availability'] ?? [];
        $target = $perValue['target_size']['per_class_availability'][self::LONG_FORM_CLASS] ?? null;
        self::assertIsArray($target);
        self::assertSame('planned', $target['availability'] ?? null);
    }

    #[Test]
    public function normalize_audio_is_scoped_to_the_long_form_class_as_planned(): void
    {
        $perClass = self::$mergeVideoOptions['normalize_audio']['per_class_availability'] ?? null;
        self::assertIsArray($perClass);
        self::assertSame('planned', $perClass[self::LONG_FORM_CLASS]['availability'] ?? null);
    }

    #[Test]
    public function compress_has_no_per_class_overlay_which_is_the_other_half_of_the_claim(): void
    {
        // codex f8b43b658063 caught that this suite promised more than it
        // covered. Their suggested location was wrong (operation-capabilities
        // has zero long-form nodes) and their conclusion was right, and the
        // truth is worse: COMPRESS HAS NO per_class_availability AT ALL.
        //
        // Pinning the ABSENCE means the day contracts extends the overlay to
        // compress, this fires and the compress docblocks get updated with the
        // merge ones — rather than the overlay landing and nothing noticing,
        // which is the exact failure this card was filed against.
        $opFile = (new \ReflectionClass(CompressMetadata::class))->getFileName();
        $root = \dirname((string) $opFile, 3);
        $avail = \json_decode((string) \file_get_contents($root . '/availability/availability.json'), true);
        self::assertIsArray($avail);

        $compressVideo = $avail['operations']['compress']['mime_groups']['video']['options'] ?? null;
        self::assertIsArray($compressVideo, 'compress video options missing — positive control');
        self::assertArrayHasKey('encoding_mode', $compressVideo);

        self::assertArrayNotHasKey(
            'per_class_availability',
            $compressVideo['encoding_mode'],
            'compress gained a per-class overlay — pin it, and update the compress targetSize docblocks',
        );
    }

    #[Test]
    public function when_this_goes_red_the_shipped_target_size_docblocks_become_lies(): void
    {
        // The tripwire, stated as a test rather than a comment somebody has to
        // read. Every targetSize declaration in BOTH SDKs tells callers this is
        // unavailable on long inputs. The day Phase 3 two-pass target-size
        // ships and contracts flips this to `stable`, this fails — and the
        // correct response is to REWRITE THOSE DOCBLOCKS, not relax this line.
        //
        // The MERGE declarations, which is what this assertion covers:
        //   packages/php/src/Ergonomic/MergeOptions.php  ($targetSize)
        //   packages/typescript/src/merge.ts             (MergeOptions.targetSize)
        // The compress pair is covered by the compress-has-no-overlay test above.
        $perClass = self::$mergeVideoOptions['encoding_mode']['per_class_availability'] ?? [];

        self::assertSame(
            'planned',
            $perClass[self::LONG_FORM_CLASS]['availability'] ?? null,
            'long-form target-size became available — update the four targetSize docblocks in both SDKs',
        );
    }
}
