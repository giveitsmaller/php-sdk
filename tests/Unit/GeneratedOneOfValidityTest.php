<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Generated\OpenApi\Model\CapabilityProduces;
use Gisl\Generated\OpenApi\Model\CreateBillingCheckoutSession422Response;
use Gisl\Generated\OpenApi\Model\WorkflowSource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * HMvRivg8 — a generated model flattened from a `oneOf` accepts a real value of
 * EACH branch, and still refuses a value that completes no branch.
 *
 * openapi-generator null-checked every branch's required fields at once, so
 * valid() rejected every real value; scripts/generate.py now relaxes the fields
 * only some branches require, null-guards their other checks (a bare
 * `count(null)` is a TypeError in PHP 8), and adds an at-least-one-branch check.
 */
#[CoversNothing]
final class GeneratedOneOfValidityTest extends TestCase
{
    private const FILE_ID = '01936fb1-7bb3-7000-8000-000000000010';

    /** @return iterable<string, array{object, bool}> */
    public static function values(): iterable
    {
        // Request side: WorkflowSource (upload | job_output | external_import | connection).
        // The generated constructor overwrites the `type` discriminator with the
        // class name (a separate generator quirk, card filed alongside), so the
        // value is set after construction.
        yield 'source: upload branch' => [self::source('upload', ['file_id' => self::FILE_ID]), true];
        yield 'source: job_output branch' => [self::source('job_output', ['from' => 'op_1']), true];
        yield 'source: type only completes no branch' => [self::source('upload', []), false];
        // `from` completes job_output's fields, but type=upload is not that branch.
        yield 'source: upload type with job_output fields' => [self::source('upload', ['from' => 'op_1']), false];

        // A response whose branches differ by a constrained array (`violations`, minItems 1).
        yield '422: plain error branch, no violations' => [
            new CreateBillingCheckoutSession422Response(['success' => false, 'error' => 'UNPROCESSABLE_ENTITY']),
            true,
        ];
        yield '422: violations branch' => [
            new CreateBillingCheckoutSession422Response([
                'success' => false,
                'error' => 'UNPROCESSABLE_ENTITY',
                'error_type' => 'feature_not_available',
                'violations' => [['field' => 'plan', 'message' => 'x']],
            ]),
            true,
        ];

        // Every branch requires a DIFFERENT single property.
        yield 'produces: same_as_input branch' => [new CapabilityProduces(['same_as_input' => true]), true];
        yield 'produces: fixed branch' => [new CapabilityProduces(['fixed' => 'image/png']), true];
        yield 'produces: empty completes no branch' => [new CapabilityProduces([]), false];
    }

    /** @param array<string, mixed> $fields */
    private static function source(string $type, array $fields): WorkflowSource
    {
        $source = new WorkflowSource($fields);
        $source->setType($type);
        return $source;
    }

    #[DataProvider('values')]
    public function testValidityFollowsTheBranches(object $model, bool $expected): void
    {
        \assert(\method_exists($model, 'valid') && \method_exists($model, 'listInvalidProperties'));
        self::assertSame($expected, $model->valid(), \implode('; ', $model->listInvalidProperties()));
    }

    public function testAValueCompletingNoBranchSaysWhichFieldsWouldComplete(): void
    {
        $problems = (new CapabilityProduces([]))->listInvalidProperties();
        self::assertContains('matches no oneOf branch: needs all of {same_as_input} or {from_option} or {fixed}', $problems);
    }
}
