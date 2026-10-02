<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Conformance;

use Gisl\Sdk\Ergonomic\PresetResolver;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\FilesRecipe;
use Gisl\Sdk\FileFirst\MergedRecipe;
use Gisl\Sdk\FileFirst\Recipe;
use Gisl\Sdk\FileFirst\WatermarkedRecipe;
use Gisl\Sdk\GislErgonomicClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * YdxagJOI — the compress `$options` bag is typed by an array-shape docblock on all
 * five compress surfaces (op-first `GislErgonomicClient::compress()` and the four
 * file-first `compress()` verbs). PHP arm of the TS `compress-option-keys.test.ts`.
 *
 * A docblock is only as good as what pins it, so this suite ties the shape to the
 * resolver that actually enforces the keys at runtime:
 *  - all five shapes are IDENTICAL (one key set, not five drifting copies);
 *  - the key set EQUALS `optimize` + `presetOverrides` + every media's
 *    `KNOWN_WIRE_FIELDS` + the camelCase `WIRE_ALIASES` + video's derived `targetSize`
 *    — so the shape can neither narrow what the resolver accepts nor promise a key it
 *    refuses (the same derivation, from the mirrored tables, as the TS tuple);
 *  - behaviourally, every declared key is accepted for some media and a misspelled key
 *    is refused as `unknown_field` for every media, including through a file-first
 *    lowering.
 *
 * ⚠️ THE RUNTIME REFUSAL IS PHP'S KEY GATE, NOT THE SHAPE. Measured with PHPStan 2.2:
 * the shape rejects a wrong VALUE (`['codec' => 'h263']`) but NOT an unlisted key
 * (`['qualty' => 80]` passes, since an array shape admits extra keys). That is the same
 * for the convert / thumbnail / transform shapes, which is why they too have a runtime
 * guard. TypeScript's compile-time rejection has no PHP equivalent here.
 */
#[CoversClass(PresetResolver::class)]
final class CompressOptionsShapeTest extends TestCase
{
    private const SDK_ONLY_KEYS = ['optimize', 'presetOverrides'];

    /** @return array<string, array{class-string, string}> */
    public static function compressSurfaces(): array
    {
        return [
            'GislErgonomicClient::compress' => [GislErgonomicClient::class, 'compress'],
            'Recipe::compress' => [Recipe::class, 'compress'],
            'FilesRecipe::compress' => [FilesRecipe::class, 'compress'],
            'MergedRecipe::compress' => [MergedRecipe::class, 'compress'],
            'WatermarkedRecipe::compress' => [WatermarkedRecipe::class, 'compress'],
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

    /** @return list<string> */
    private static function expectedKeys(): array
    {
        /** @var array<string, string> $aliases */
        $aliases = (new \ReflectionClassConstant(PresetResolver::class, 'WIRE_ALIASES'))->getValue();
        $wireKeys = \array_values(\array_unique(\array_merge(...\array_values(PresetResolver::KNOWN_WIRE_FIELDS))));
        foreach ($aliases as $camel => $wire) {
            self::assertContains($wire, $wireKeys, "alias {$camel} -> {$wire} targets no media's wire field");
        }
        $expected = \array_values(\array_unique([...self::SDK_ONLY_KEYS, ...$wireKeys, ...\array_keys($aliases), 'targetSize']));
        \sort($expected);

        return $expected;
    }

    #[Test]
    #[DataProvider('compressSurfaces')]
    public function the_shape_key_set_equals_what_the_resolver_accepts(string $class, string $method): void
    {
        /** @var class-string $class */
        [, $keys] = self::optionsShape($class, $method);
        self::assertSame(\count($keys), \count(\array_unique($keys)), 'duplicate key in the shape');
        \sort($keys);
        self::assertSame(self::expectedKeys(), $keys);
    }

    #[Test]
    public function all_five_compress_surfaces_carry_the_identical_shape(): void
    {
        [$reference] = self::optionsShape(Recipe::class, 'compress');
        foreach (self::compressSurfaces() as $label => [$class, $method]) {
            [$shape] = self::optionsShape($class, $method);
            self::assertSame($reference, $shape, "{$label} drifted from Recipe::compress");
        }
    }

    /** @return iterable<string, array{string}> */
    public static function explicitKeys(): iterable
    {
        [, $keys] = self::optionsShape(Recipe::class, 'compress');
        foreach ($keys as $key) {
            if (!\in_array($key, self::SDK_ONLY_KEYS, true)) {
                yield $key => [$key];
            }
        }
    }

    #[Test]
    #[DataProvider('explicitKeys')]
    public function every_declared_explicit_key_is_accepted_for_some_media(string $key): void
    {
        $value = $key === 'targetSize' ? '50MB' : 1;
        $acceptedBy = [];
        foreach (\array_keys(PresetResolver::KNOWN_WIRE_FIELDS) as $media) {
            try {
                PresetResolver::resolveCompress($media, null, null, null, null, [$key => $value]);
                $acceptedBy[] = $media;
            } catch (GislConfigError $e) {
                if ($e->reason !== 'unknown_field') {
                    $acceptedBy[] = $media;
                }
            }
        }
        self::assertNotSame([], $acceptedBy, "no media accepts '{$key}'");
    }

    /** @return iterable<string, array{string}> */
    public static function presetMedia(): iterable
    {
        foreach (\array_keys(PresetResolver::KNOWN_WIRE_FIELDS) as $media) {
            yield $media => [$media];
        }
    }

    #[Test]
    #[DataProvider('presetMedia')]
    public function a_misspelled_key_is_refused_as_unknown_field(string $media): void
    {
        try {
            PresetResolver::resolveCompress($media, null, null, null, null, ['qualty' => 80]);
            self::fail("'qualty' must be refused for {$media}");
        } catch (GislConfigError $e) {
            self::assertSame('unknown_field', $e->reason);
            self::assertSame(['qualty'], $e->conflictingFields);
        }
    }

    #[Test]
    public function a_misspelled_key_is_refused_through_a_file_first_lowering(): void
    {
        $recipe = (new Recipe(FileInput::path('photo.jpg')))->compress(null, ['qualty' => 80]);
        try {
            $recipe->toWorkflowPayload('file_0001');
            self::fail("'qualty' must be refused before any upload");
        } catch (GislConfigError $e) {
            self::assertSame('unknown_field', $e->reason);
        }
    }
}
