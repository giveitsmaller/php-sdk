<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Generated\OpenApi\Model\CapabilityCondition;
use Gisl\Generated\OpenApi\Model\OperationsSchemaResponse;
use Gisl\Generated\OpenApi\ObjectSerializer;
use Gisl\Generated\Operations\CompressMetadata;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * F9UUicuO, PHP arm. The TypeScript generator dropped every `{field, in}`
 * capability condition and every snake_case `produces` form to `{}`. PHP
 * flattens a `oneOf` into one class keyed by WIRE name, so it was not expected
 * to share the defect; this pins that it does not, by hydrating the SHIPPED
 * operation-capabilities sidecar exactly as `getSchema()` does and serialising
 * it back.
 */
#[CoversNothing]
final class CapabilityConditionHydrationTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function shippedCapabilities(): array
    {
        $opFile = (new \ReflectionClass(CompressMetadata::class))->getFileName();
        $path = \dirname((string) $opFile, 3) . '/operation-capabilities/operation-capabilities.json';
        $json = \json_decode((string) \file_get_contents($path), true);
        self::assertIsArray($json);
        self::assertIsArray($json['operations'] ?? null);
        /** @var array<string, mixed> $operations */
        $operations = $json['operations'];
        return $operations;
    }

    private static function hydrate(mixed $capabilities): OperationsSchemaResponse
    {
        $schema = ObjectSerializer::deserialize([
            'schema_version' => '2.0.0',
            'capabilities_version' => '1',
            'generated_at' => '2026-10-07T00:00:00Z',
            'operations' => [],
            'capabilities' => $capabilities,
        ], OperationsSchemaResponse::class, []);
        self::assertInstanceOf(OperationsSchemaResponse::class, $schema);
        return $schema;
    }

    public function test_positive_control_the_sidecar_carries_in_leaves_and_snake_case_produces(): void
    {
        $encoded = (string) \json_encode(self::shippedCapabilities());
        self::assertStringContainsString('"in":[', $encoded);
        self::assertStringContainsString('"output_container"', $encoded);
        self::assertStringContainsString('"same_as_input"', $encoded);
        self::assertStringContainsString('"from_option"', $encoded);
        self::assertStringNotContainsString('"equals":null', $encoded);
    }

    public function test_every_shipped_capability_round_trips_with_nothing_lost(): void
    {
        $capabilities = self::shippedCapabilities();
        $schema = self::hydrate($capabilities);

        $reencoded = \json_decode(
            (string) \json_encode(ObjectSerializer::sanitizeForSerialization($schema->getCapabilities())),
            true,
        );
        self::assertIsArray($reencoded);
        // Re-serialising emits `equals: null` on EVERY condition node: the generated
        // deserialiser calls setEquals(null) for an absent nullable property, which
        // marks it set-to-null. That is a serialisation artefact, not a hydration
        // loss (getEquals() is null either way and the contract never sends a null
        // `equals`, asserted by the positive control), so it is stripped here.
        self::assertEquals($capabilities, self::withoutNullEquals($reencoded));
    }

    private static function withoutNullEquals(mixed $node): mixed
    {
        if (!\is_array($node)) {
            return $node;
        }
        if (\array_key_exists('equals', $node) && $node['equals'] === null) {
            unset($node['equals']);
        }
        return \array_map(self::withoutNullEquals(...), $node);
    }

    public function test_the_frontend_reported_in_leaf_keeps_its_values(): void
    {
        $schema = self::hydrate([
            'compress' => [
                'option_conflicts' => [[
                    'constraint_id' => 'c',
                    'message' => 'm',
                    'wire_code' => 'invalid_options',
                    'when' => ['all' => [['field' => 'compress.codec', 'in' => ['h265', 'vp9', 'av1']]]],
                ]],
                'produces' => ['same_as_input' => true],
            ],
        ]);
        $compress = $schema->getCapabilities()['compress'] ?? null;
        self::assertNotNull($compress);
        $when = ($compress->getOptionConflicts() ?? [])[0]->getWhen();
        $leaf = ($when->getAll() ?? [])[0] ?? null;
        self::assertInstanceOf(CapabilityCondition::class, $leaf);
        self::assertSame('compress.codec', $leaf->getField());
        self::assertSame(['h265', 'vp9', 'av1'], $leaf->getIn());
        self::assertTrue($compress->getProduces()?->getSameAsInput());
    }
}
