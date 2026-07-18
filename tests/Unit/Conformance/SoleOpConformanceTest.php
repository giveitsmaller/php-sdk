<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Conformance;

use Gisl\Generated\Operations\CompressMetadata;
use Gisl\Sdk\FileFirst\RunResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * sole_op conformance guard (IQc01rj0).
 *
 * The single-input {@see \Gisl\Sdk\FileFirst\Recipe::toWorkflowPayload} split
 * reads a hand SDK set ({@see RunResult::SOLE_OP_TYPES}) rather than the raw
 * `operation-capabilities.json` sidecar at runtime, so the split stays offline +
 * deterministic. This suite PINS that set to the shipped
 * `operation-capabilities.json` `operations.<op>.sole_op` — a contract regen that
 * flips an op's `sole_op` fails HERE. PHP arm of the TS `sole-op-conformance.test.ts`.
 *
 * The projection is located via the generated metadata class (operations/src/*.php
 * -> dirname x3 = generated/php root), matching ImageOutputRouteConformanceTest.
 */
#[CoversClass(RunResult::class)]
final class SoleOpConformanceTest extends TestCase
{
    public function test_sole_op_types_mirror_the_operation_capabilities_projection(): void
    {
        $opFile = (new \ReflectionClass(CompressMetadata::class))->getFileName();
        $root = \dirname((string) $opFile, 3);
        $path = $root . '/operation-capabilities/operation-capabilities.json';
        $json = \json_decode((string) \file_get_contents($path), true);
        self::assertIsArray($json, 'operation-capabilities.json must decode to an array');
        self::assertIsArray($json['operations'] ?? null);

        $contractSoleOps = [];
        foreach ($json['operations'] as $op => $caps) {
            if (\is_array($caps) && ($caps['sole_op'] ?? null) === true) {
                $contractSoleOps[] = (string) $op;
            }
        }
        \sort($contractSoleOps);

        $actual = RunResult::SOLE_OP_TYPES;
        \sort($actual);

        self::assertSame($contractSoleOps, $actual, 'SOLE_OP_TYPES drifted from operation-capabilities.json');
    }
}
