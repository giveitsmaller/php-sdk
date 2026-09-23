<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\ApiSurface;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use SplFileInfo;
use UnitEnum;

/**
 * Discovers a PSR-4 tree's public surface by reflection, one row per symbol.
 *
 * PHP has no export list, so the surface has to be DISCOVERED, and discovery
 * is the risky half: reflection describes a class you hand it and says nothing
 * about the one you forgot. So the walk starts from the FILES; a file that
 * yields no class/interface/enum/trait, or declares a SECOND one besides the
 * one its path names, is an error rather than a silent omission (DFtpDIl7).
 * ⚠️ Namespace-level functions and constants are NOT checked: PSR-4 cannot
 * autoload them, and src/ declares none.
 *
 * ⚠️ WHAT A ROW RECORDS, AND WHAT IT DOES NOT. A row records a symbol's NAME,
 * its kind and modifiers, a class's FULL parent chain and interfaces, and a constant's or
 * enum case's VALUE. It does NOT record method SIGNATURES (parameter names,
 * types, defaults, return types): a new required parameter passes this test.
 * That is `YebCTMuY`. Do not read a green run here as "no breaking change".
 */
final class ApiSurface
{
    /**
     * `@internal` as a docblock TAG: at the start of a docblock line, or right
     * after the opening `/**` of a one-line docblock. Prose that merely
     * mentions `@internal` (Gisl.php does, in backticks) is not an exclusion.
     */
    private const INTERNAL_TAG = '~(^\s*\*|/\*\*)\s*@internal\b~m';

    /**
     * @return list<string> sorted rows
     */
    public static function rows(string $sourceDir, string $namespacePrefix): array
    {
        $root = realpath($sourceDir);
        if ($root === false) {
            throw new RuntimeException("discovery root {$sourceDir} does not exist");
        }

        $rows = [];
        $expected = [];
        foreach (self::phpFiles($root) as $relativePath) {
            $className = $namespacePrefix . str_replace(['/', '.php'], ['\\', ''], $relativePath);
            // Autoload ONCE (class_exists), then ask the other kinds without
            // re-triggering the autoloader, which would include the file again.
            if (!class_exists($className) && !interface_exists($className, false)
                && !enum_exists($className, false) && !trait_exists($className, false)) {
                throw new RuntimeException(
                    "{$relativePath} declares no class, interface, enum or trait named {$className}; "
                    . 'a source file that yields no symbol would be silently unaudited',
                );
            }
            $expected[strtolower($className)] = true;
            array_push($rows, ...self::rowsForClass(new ReflectionClass($className), $root));
        }

        // A file that ALSO declares a second symbol yielded one row set and
        // hid the other. Anything loaded from under the root that is not the
        // symbol its path names is a stowaway.
        foreach ([...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()] as $declared) {
            $file = (new ReflectionClass($declared))->getFileName();
            if ($file !== false && str_starts_with($file, $root . '/') && !isset($expected[strtolower($declared)])) {
                throw new RuntimeException(
                    "{$declared} is declared in " . substr($file, strlen($root) + 1)
                    . ', which is not its PSR-4 file; a second symbol in a file would be silently unaudited',
                );
            }
        }

        sort($rows, SORT_STRING);

        return $rows;
    }

    /**
     * @param ReflectionClass<object> $class
     * @return list<string>
     */
    private static function rowsForClass(ReflectionClass $class, string $root): array
    {
        $name = $class->getName();
        if (self::isInternal($class->getDocComment())) {
            // Class-level @internal excludes THIS class only. Recorded, so the
            // file is still accounted for and un-marking it shows up as a diff.
            return ["internal {$name}"];
        }

        $rows = [self::header($class)];

        foreach ($class->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
            if (!self::isOwn($class, $constant->getDeclaringClass(), $root) || self::isInternal($constant->getDocComment())) {
                continue;
            }
            $rows[] = ($class->isEnum() && $constant->isEnumCase()
                ? 'case ' . $name . '::' . $constant->getName() . self::caseValue($constant)
                : ($constant->isFinal() && !$class->isInterface() ? 'final ' : '')
                    . 'const ' . $name . '::' . $constant->getName() . ' = ' . self::render($constant->getValue()));
        }

        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (!self::isOwn($class, $property->getDeclaringClass(), $root) || self::isInternal($property->getDocComment())) {
                continue;
            }
            $modifiers = ($property->isStatic() ? 'static ' : '') . ($property->isReadOnly() ? 'readonly ' : '');
            // Type and default are part of the contract a consumer writes to.
            $type = $property->hasType() ? ' : ' . $property->getType() : '';
            $default = $property->hasDefaultValue() ? ' = ' . self::render($property->getDefaultValue()) : '';
            $rows[] = "{$modifiers}property {$name}::\${$property->getName()}{$type}{$default}";
        }

        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // isInternal(): engine-supplied (an enum's cases()/from()/tryFrom()),
            // not something this package declared.
            if (!self::isOwn($class, $method->getDeclaringClass(), $root) || $method->isInternal()
                || self::isInternal($method->getDocComment())) {
                continue;
            }
            $modifiers = ($method->isFinal() && !$class->isFinal() ? 'final ' : '')
                . ($method->isAbstract() && !$class->isInterface() ? 'abstract ' : '')
                . ($method->isStatic() ? 'static ' : '');
            $rows[] = "{$modifiers}method {$name}::{$method->getName()}()";
        }

        return $rows;
    }

    /**
     * A member is recorded on $class when $class declares it, OR when it is
     * inherited from a class-level `@internal` class UNDER THE WALK ROOT: that
     * base has no rows of its own, so its public members would otherwise be
     * callable on the public subclass and recorded nowhere. Members of a parent
     * from outside the root (\RuntimeException, or a vendor class that happens
     * to be tagged @internal) are not rows; the `extends` chain in the header is.
     *
     * @param ReflectionClass<object> $class
     * @param ReflectionClass<object> $declaring
     */
    private static function isOwn(ReflectionClass $class, ReflectionClass $declaring, string $root): bool
    {
        if ($declaring->getName() === $class->getName()) {
            return true;
        }
        $file = $declaring->getFileName();

        return $file !== false && str_starts_with($file, $root . '/')
            && self::isInternal($declaring->getDocComment());
    }

    /**
     * @param ReflectionClass<object> $class
     */
    private static function header(ReflectionClass $class): string
    {
        if ($class->isEnum()) {
            $backing = (new ReflectionEnum($class->getName()))->getBackingType();
            $kind = $backing === null ? 'enum' : "enum:{$backing}";
        } else {
            $kind = match (true) {
                $class->isInterface() => 'interface',
                $class->isTrait() => 'trait',
                $class->isAbstract() => 'abstract class',
                $class->isFinal() => 'final class',
                default => 'class',
            };
        }

        // The FULL chain, not just the immediate parent: a change above an
        // @internal intermediate (which has no header of its own) still moves
        // what every `catch (Ancestor $e)` catches.
        $header = "{$kind} {$class->getName()}";
        $ancestors = [];
        for ($parent = $class->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            $ancestors[] = $parent->getName();
        }
        if ($ancestors !== []) {
            $header .= ' extends ' . implode(' < ', $ancestors);
        }
        $interfaces = array_values(array_filter(
            $class->getInterfaceNames(),
            // Engine-added to every enum; not something this package chose.
            static fn (string $interface): bool => !in_array($interface, ['UnitEnum', 'BackedEnum'], true),
        ));
        sort($interfaces, SORT_STRING);
        if ($interfaces !== []) {
            $header .= ($class->isInterface() ? ' extends ' : ' implements ') . implode(', ', $interfaces);
        }
        $traits = $class->getTraitNames();
        sort($traits, SORT_STRING);
        if ($traits !== []) {
            $header .= ' uses ' . implode(', ', $traits);
        }

        return $header;
    }

    private static function caseValue(ReflectionClassConstant $constant): string
    {
        $case = $constant->getValue();

        return $case instanceof \BackedEnum ? ' = ' . self::render($case->value) : '';
    }

    private static function render(mixed $value): string
    {
        if ($value instanceof UnitEnum) {
            return $value::class . '::' . $value->name;
        }
        // PROSE IS NOT API. A `description` string anywhere inside a constant's
        // value is dropped before rendering: v2.208.0 reworded two error
        // descriptions and otherwise moved the 5 KB ERROR_CODES row for nothing
        // a caller can see. Everything else is kept - `retryable`, `httpStatus`,
        // `sdkClass`, category membership and preset values ARE behaviour, and a
        // keys-only rendering hid them (codex 455456cf9c42).
        if (is_array($value)) {
            $value = self::withoutProse($value);
        }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return $json === false ? str_replace("\n", ' ', var_export($value, true)) : $json;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function withoutProse(array $value): array
    {
        $kept = [];
        foreach ($value as $key => $item) {
            if ($key === 'description' && is_string($item)) {
                continue;
            }
            $kept[$key] = is_array($item) ? self::withoutProse($item) : $item;
        }

        return $kept;
    }

    private static function isInternal(string|false $docComment): bool
    {
        return $docComment !== false && preg_match(self::INTERNAL_TAG, $docComment) === 1;
    }

    /**
     * @return list<string> paths relative to $root, '/'-separated, sorted
     */
    private static function phpFiles(string $root): array
    {
        $files = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        if ($files === []) {
            throw new RuntimeException("no PHP files under {$root}; the discovery root is wrong");
        }
        sort($files, SORT_STRING);

        return $files;
    }
}
