<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Conformance;

use Gisl\Generated\Operations\SplitMetadata;
use Gisl\Sdk\Ergonomic\OptionValidation;
use Gisl\Sdk\FileFirst\Recipe;
use Gisl\Sdk\GislErgonomicClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DflFw0j1 — the split `$options` bag is typed by an array-shape docblock on both
 * split surfaces (`Recipe::split()` and the single-op `GislErgonomicClient::split()`).
 * PHP arm of the TS `Equal<keyof SplitOptions, SPLIT_OPTION_KEYS>` pin plus the
 * wire-key conformance `verbs` arm.
 *
 * A docblock is only as good as what pins it, so this suite ties the shape to the
 * contract metadata the runtime key gate reads ({@see OptionValidation}):
 *  - both shapes are IDENTICAL (one key set, not two drifting copies);
 *  - the key set EQUALS `operationOptionKeys(SplitMetadata)`, so the shape can
 *    neither narrow what the validator accepts nor promise a key it refuses;
 *  - every value type is a scalar / string literal, never a generated enum class
 *    (the generated split enums are per mime group, so no one class fits the flat
 *    bag; mirrors the TS SplitOptions string literals).
 *
 * As with {@see CompressOptionsShapeTest}: an array shape admits extra keys under
 * PHPStan, so an unlisted key is refused by the runtime gate (`unknown_field`), not
 * by the shape.
 */
final class SplitOptionsShapeTest extends TestCase
{
    /** @return array<string, array{class-string, string}> */
    public static function splitSurfaces(): array
    {
        return [
            'Recipe::split' => [Recipe::class, 'split'],
            'GislErgonomicClient::split' => [GislErgonomicClient::class, 'split'],
        ];
    }

    /**
     * The `array{...} $options` shape of a method's docblock, as [shape text, keys].
     *
     * @param class-string $class
     *
     * @return array{string, list<string>}
     */
    private static function optionsShape(string $class, string $method): array
    {
        $doc = (string) (new \ReflectionMethod($class, $method))->getDocComment();
        $matched = \preg_match('/@param array\{\n(.*?)\n\s*\*\s*\} \$options/s', $doc, $m);
        self::assertSame(1, $matched, "{$class}::{$method} has no `@param array{...} \$options` shape");
        \preg_match_all('/^\s*\*\s+(\w+)\?:/m', $m[1], $keys);

        return [$m[1], $keys[1]];
    }

    #[Test]
    #[DataProvider('splitSurfaces')]
    public function the_shape_key_set_equals_the_split_contract_keys(string $class, string $method): void
    {
        /** @var class-string $class */
        [, $keys] = self::optionsShape($class, $method);
        self::assertSame(\count($keys), \count(\array_unique($keys)), 'duplicate key in the shape');
        \sort($keys);
        $expected = \array_keys(OptionValidation::operationOptionKeys(SplitMetadata::instance()));
        \sort($expected);
        self::assertSame($expected, $keys);
    }

    #[Test]
    public function both_split_surfaces_carry_the_identical_shape(): void
    {
        [$reference] = self::optionsShape(Recipe::class, 'split');
        foreach (self::splitSurfaces() as $label => [$class, $method]) {
            [$shape] = self::optionsShape($class, $method);
            self::assertSame($reference, $shape, "{$label} drifted from Recipe::split");
        }
    }

    #[Test]
    #[DataProvider('splitSurfaces')]
    public function the_shape_uses_no_class_types(string $class, string $method): void
    {
        /** @var class-string $class */
        [$shape] = self::optionsShape($class, $method);
        self::assertStringNotContainsString('\\', $shape, 'split option values are string literals / scalars, not enum classes');
    }
}
