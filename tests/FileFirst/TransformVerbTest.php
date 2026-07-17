<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\FileFirst;

use Gisl\Sdk\Ergonomic\MergeOptions;
use Gisl\Sdk\Ergonomic\OperationBuilder;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\FilesRecipe;
use Gisl\Sdk\FileFirst\MergedRecipe;
use Gisl\Sdk\FileFirst\Recipe;
use Gisl\Sdk\Tests\Unit\Ergonomic\GislErgonomicClientFactoryTestHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `transform` passthrough verb (rotate/flip) — LOWERING + validation across every
 * PHP ergonomic surface (Recipe / FilesRecipe / MergedRecipe / WatermarkedRecipe +
 * the single-op {@see \Gisl\Sdk\GislErgonomicClient::transform} builder). Mirrors
 * the TS `transform-verb.test.ts`: the SDK forwards the option bag verbatim
 * (unknown keys rejected pre-upload via {@see \Gisl\Sdk\Ergonomic\OptionValidation},
 * no positional-owned key, no required dimensions) and does NOT gate on
 * media/availability — the op is `availability: planned`, so a no-op or a
 * per-media-unsupported combo (e.g. `flip` on a PDF) is a SERVER 422, never a
 * client-side reject. These tests pin the wire shape only.
 *
 * NOTE: an EMPTY option bag lowers to a bare `{type: 'transform'}` (no `options`
 * key). PHP drops null OPTIONAL values then the shared lowering omits an empty
 * options object, so PHP (null → absent) and TS (undefined → absent) serialise
 * byte-identically. That is the actual source behaviour asserted below.
 */
final class TransformVerbTest extends TestCase
{
    private const FILE_ID = 'file_0001';

    /**
     * Lower a single-input Recipe and return its one job's operations (as the
     * `toWire()` array shape — `{type, options?}` per op).
     *
     * @return list<array<string, mixed>>
     */
    private function recipeOps(Recipe $recipe): array
    {
        /** @var list<array<string, mixed>> $jobs */
        $jobs = $recipe->toWorkflowPayload(self::FILE_ID)->toWire()['jobs'];
        /** @var list<array<string, mixed>> $ops */
        $ops = $jobs[0]['operations'];

        return $ops;
    }

    /**
     * The operations[] of the LAST job of a lowered payload (merge/watermark
     * append their post-ops to their trailing job).
     *
     * @param array<string, mixed> $wire
     * @return list<array<string, mixed>>
     */
    private function lastJobOps(array $wire): array
    {
        /** @var list<array<string, mixed>> $jobs */
        $jobs = $wire['jobs'];
        /** @var list<array<string, mixed>> $ops */
        $ops = $jobs[count($jobs) - 1]['operations'];

        return $ops;
    }

    /**
     * Find the single lowered `transform` op (fails the test if absent).
     *
     * @param list<array<string, mixed>> $ops
     * @return array<string, mixed>
     */
    private function transformOp(array $ops): array
    {
        foreach ($ops as $op) {
            if (($op['type'] ?? null) === 'transform') {
                return $op;
            }
        }
        self::fail('expected a transform op in the lowered payload');
    }

    // --- Recipe (single input) ----------------------------------------------

    #[Test]
    public function recipe_lowers_both_options_passed_through(): void
    {
        $ops = $this->recipeOps(
            (new Recipe(FileInput::path('photo.png')))->transform(['rotate' => 90, 'flip' => 'horizontal']),
        );
        self::assertSame(
            ['type' => 'transform', 'options' => ['rotate' => 90, 'flip' => 'horizontal']],
            $this->transformOp($ops),
        );
    }

    #[Test]
    public function recipe_drops_an_absent_option(): void
    {
        // transform(['rotate' => 90]) — the absent flip never reaches the wire.
        $ops = $this->recipeOps(
            (new Recipe(FileInput::path('photo.png')))->transform(['rotate' => 90]),
        );
        self::assertSame(
            ['type' => 'transform', 'options' => ['rotate' => 90]],
            $this->transformOp($ops),
        );
    }

    #[Test]
    public function recipe_drops_an_explicit_null_option(): void
    {
        // The real PHP null-drop: an explicit null OPTIONAL is stripped before
        // lowering (byte-identical to the TS undefined-drop).
        $ops = $this->recipeOps(
            (new Recipe(FileInput::path('photo.png')))->transform(['rotate' => 90, 'flip' => null]),
        );
        self::assertSame(
            ['type' => 'transform', 'options' => ['rotate' => 90]],
            $this->transformOp($ops),
        );
    }

    #[Test]
    public function recipe_empty_bag_lowers_to_a_bare_transform_op_with_no_options_key(): void
    {
        // transform([]) — the shared lowering omits an empty options object
        // entirely; a no-op (rotate:0 + flip:none) is a SERVER 422, not a
        // client-side reject.
        $ops = $this->recipeOps(
            (new Recipe(FileInput::path('photo.png')))->transform([]),
        );
        self::assertSame(['type' => 'transform'], $this->transformOp($ops));
    }

    // --- FilesRecipe (homogeneous fan-out) ----------------------------------

    #[Test]
    public function files_recipe_emits_the_same_transform_op_into_every_per_file_job(): void
    {
        $wire = (new FilesRecipe([FileInput::path('a.jpg'), FileInput::path('b.jpg')]))
            ->transform(['flip' => 'vertical'])
            ->toWorkflowPayload(['f0', 'f1'])
            ->toWire();

        /** @var list<array<string, mixed>> $jobs */
        $jobs = $wire['jobs'];
        self::assertCount(2, $jobs);
        foreach ($jobs as $job) {
            /** @var list<array<string, mixed>> $ops */
            $ops = $job['operations'];
            self::assertSame(
                ['type' => 'transform', 'options' => ['flip' => 'vertical']],
                $this->transformOp($ops),
            );
        }
    }

    // --- MergedRecipe (post-merge) ------------------------------------------

    #[Test]
    public function merged_recipe_lowers_transform_into_a_downstream_post_job(): void
    {
        $wire = (new MergedRecipe(
            [FileInput::path('a.mp4'), FileInput::path('b.mp4')],
            new MergeOptions(mediaKind: 'video'),
        ))
            ->transform(['rotate' => 180])
            ->toWorkflowPayload(['f0', 'f1'], null)
            ->toWire();

        // `merge` is sole_op: alone in the `merge` job; the transform post-step
        // lowers into a downstream `post` job (now the last job).
        $ops = $this->lastJobOps($wire);
        self::assertSame(['transform'], array_column($ops, 'type'));
        self::assertSame(
            ['type' => 'transform', 'options' => ['rotate' => 180]],
            $this->transformOp($ops),
        );
    }

    // --- WatermarkedRecipe (post-watermark) ---------------------------------

    #[Test]
    public function watermarked_recipe_lowers_transform_into_a_downstream_post_job(): void
    {
        $wire = (new Recipe(FileInput::path('photo.jpg')))
            ->watermark(new Recipe(FileInput::path('logo.png')))
            ->transform(['rotate' => 90])
            ->toWorkflowPayload(['base', 'ovl'])
            ->toWire();

        // `image_watermark` is sole_op: alone in the `watermark` job; the
        // transform post-step lowers into a downstream `post` job (last job).
        $ops = $this->lastJobOps($wire);
        self::assertSame(['transform'], array_column($ops, 'type'));
        self::assertSame(
            ['type' => 'transform', 'options' => ['rotate' => 90]],
            $this->transformOp($ops),
        );
    }

    // --- client single-op builder -------------------------------------------

    #[Test]
    public function client_transform_builds_an_operation_builder_for_a_valid_bag(): void
    {
        // The factory client's transport throws on any I/O — transform() is a
        // pure builder factory, so it must not touch the wire.
        $client = GislErgonomicClientFactoryTestHelper::client();

        self::assertInstanceOf(OperationBuilder::class, $client->transform('photo.jpg', ['rotate' => 90]));
        // An empty bag is valid too (a no-op is a SERVER 422, not a client reject).
        self::assertInstanceOf(OperationBuilder::class, $client->transform('photo.jpg', []));
    }

    #[Test]
    public function client_transform_rejects_an_unknown_option_key_pre_upload(): void
    {
        $client = GislErgonomicClientFactoryTestHelper::client();
        try {
            $client->transform('photo.jpg', ['bogus' => 1]);
            self::fail('an unknown transform key must throw pre-upload');
        } catch (GislConfigError $err) {
            self::assertSame('unknown_field', $err->reason);
            self::assertSame(['bogus'], $err->conflictingFields);
            self::assertStringContainsString("unknown option 'bogus'", $err->getMessage());
        }
    }
}
