<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Generated\OpenApi\Model\WorkflowArchiveResponse;
use Gisl\Generated\OpenApi\Model\WorkflowRestoreResponse;
use Gisl\Generated\OpenApi\ObjectSerializer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for W728EfzY — the generated PHP models could not decode an
 * archive or restore response AT ALL.
 *
 * The spec declared `archived: {type: boolean, enum: [true]}`. openapi-generator
 * emitted the allowed value as the STRING `'true'` and validated it with a
 * string comparison; PHP casts boolean `true` to `'1'` and `false` to `''`, so
 * hydrating a real JSON boolean threw `InvalidArgumentException` on EVERY
 * success response. Contracts removed the single-value enums at source in
 * v2.192.0.
 *
 * ⚠️ THIS TEST EXERCISES THE GENERATED MODEL DIRECTLY, ON PURPOSE. The defect
 * had shipped to Packagist months earlier and nobody hit it because NOTHING IN
 * THE HAND-WRITTEN SDK CALLED THESE ENDPOINTS — the model was compiled,
 * type-checked and published, and never once EXECUTED. PHPStan cannot see it:
 * the failure is a runtime allowed-values comparison, not a type error. Going
 * through a client wrapper would test the wrapper; this tests the thing that
 * was broken.
 *
 * Written against the DEFECT and confirmed FAILING on contracts v2.191.0 before
 * the fix was vendored, so a pass here is a before/after, not an assertion that
 * has only ever been green.
 */
#[CoversNothing]
final class GeneratedArchiveRestoreHydrationTest extends TestCase
{
    public function testArchiveResponseHydratesFromARealBooleanTrue(): void
    {
        $model = ObjectSerializer::deserialize(
            (object) [
                'workflow_id' => '01936fb2-0000-7000-8000-000000000001',
                'status' => 'completed',
                'archived' => true,
                'archived_at' => '2026-08-12T14:00:00Z',
            ],
            WorkflowArchiveResponse::class,
        );

        self::assertInstanceOf(WorkflowArchiveResponse::class, $model);
        self::assertTrue($model->getArchived());
        self::assertSame('01936fb2-0000-7000-8000-000000000001', $model->getWorkflowId());
    }

    public function testRestoreResponseHydratesFromARealBooleanFalse(): void
    {
        $model = ObjectSerializer::deserialize(
            (object) [
                'workflow_id' => '01936fb2-0000-7000-8000-000000000002',
                'status' => 'completed',
                'archived' => false,
            ],
            WorkflowRestoreResponse::class,
        );

        self::assertInstanceOf(WorkflowRestoreResponse::class, $model);
        self::assertFalse($model->getArchived());
    }

    /**
     * The models must also report themselves VALID. `listInvalidProperties()`
     * carried its own copy of the string comparison, so a model could
     * hydrate and still claim to be invalid — a second, quieter failure mode
     * than the throw, and the one that would have survived a narrower fix.
     */
    public function testHydratedModelsReportNoInvalidProperties(): void
    {
        $archive = ObjectSerializer::deserialize(
            (object) [
                'workflow_id' => '01936fb2-0000-7000-8000-000000000003',
                'status' => 'completed',
                'archived' => true,
                'archived_at' => '2026-08-12T14:00:00Z',
            ],
            WorkflowArchiveResponse::class,
        );

        self::assertSame([], $archive->listInvalidProperties());
    }
}
