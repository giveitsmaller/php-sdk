<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Conformance;

use Gisl\Generated\Operations\CompressMetadata;
use Gisl\Sdk\Ergonomic\ImageOutputRoutes;
use Gisl\Sdk\Ergonomic\OptionValidation;
use Gisl\Sdk\Errors\GislConfigError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Output-route conformance guard (card YNLrGhNo).
 *
 * The Output gate reads a hand SDK table ({@see ImageOutputRoutes::IMAGE_OUTPUT_ROUTES})
 * rather than the raw projection at runtime, so the gate stays offline +
 * deterministic (mirrors the watermark-capability gate). This suite PINS that
 * table to the generated `accepted-options/image-output-routes.json` projection —
 * a contract regen that changes a route's source_op / honored / planned options,
 * the facade-managed outputs, the area cap, or the mime tokens fails HERE. PHP arm
 * of the TS `output-route-conformance.test.ts`.
 *
 * The PHP test/check container ships only `generated/php/`, located here via the
 * contracts package install (vendor/giveitsmaller/contracts → generated/php). The
 * projection lives at `<generated-php-root>/accepted-options/image-output-routes.json`,
 * resolved by reflecting a generated metadata class (operations/src/*.php → dirname x3).
 */
#[CoversClass(ImageOutputRoutes::class)]
final class ImageOutputRouteConformanceTest extends TestCase
{
    /** @var array<string, mixed> The `media.image` block of the projection. */
    private static array $image = [];

    /** @var array<string, mixed> `operations.compress.mime_groups` of availability.json. */
    private static array $compressGroups = [];

    /** @var array<string, mixed> `operations.convert.mime_groups` of availability.json. */
    private static array $convertGroups = [];

    public static function setUpBeforeClass(): void
    {
        // operations/src/CompressMetadata.php -> dirname x3 = generated/php root.
        $opFile = (new \ReflectionClass(CompressMetadata::class))->getFileName();
        $root = \dirname((string) $opFile, 3);
        $path = $root . '/accepted-options/image-output-routes.json';
        $json = \json_decode((string) \file_get_contents($path), true);
        self::assertIsArray($json, 'image-output-routes.json must decode to an array');
        self::assertIsArray($json['media'] ?? null);
        self::assertIsArray($json['media']['image'] ?? null);
        self::$image = $json['media']['image'];

        $availPath = $root . '/availability/availability.json';
        $avail = \json_decode((string) \file_get_contents($availPath), true);
        self::assertIsArray($avail, 'availability.json must decode to an array');
        $groups = $avail['operations']['compress']['mime_groups'] ?? null;
        self::assertIsArray($groups, 'availability.json compress mime_groups must be an array');
        self::$compressGroups = $groups;

        $convertGroups = $avail['operations']['convert']['mime_groups'] ?? null;
        self::assertIsArray($convertGroups, 'availability.json convert mime_groups must be an array');
        self::$convertGroups = $convertGroups;
    }

    // --- top-level facade constants -----------------------------------------

    public function test_facade_managed_outputs_mirror_the_projection(): void
    {
        $expected = self::$image['facade_managed_outputs'];
        self::assertIsArray($expected);
        \sort($expected);
        $actual = ImageOutputRoutes::FACADE_MANAGED_OUTPUTS;
        \sort($actual);
        self::assertSame($expected, $actual);
    }

    public function test_area_cap_mirrors_the_projection(): void
    {
        self::assertSame(self::$image['max_output_pixels'], ImageOutputRoutes::MAX_OUTPUT_PIXELS);
    }

    public function test_every_projection_mime_token_resolves_via_token_for_mime(): void
    {
        $tokens = self::$image['mime_tokens'];
        self::assertIsArray($tokens);
        foreach ($tokens as $mime => $token) {
            self::assertSame($token, ImageOutputRoutes::tokenForMime((string) $mime), "mime token drifted for {$mime}");
        }
    }

    // --- per-route, per-format cells ----------------------------------------

    /**
     * @param 'same_format'|'format_change' $route
     */
    #[DataProvider('routeProvider')]
    public function test_route_covers_exactly_the_projection_formats(string $route, string $expectedSourceOp): void
    {
        $cells = self::$image[$route];
        self::assertIsArray($cells);
        $expected = \array_keys($cells);
        \sort($expected);
        $actual = \array_keys(ImageOutputRoutes::IMAGE_OUTPUT_ROUTES[$route]);
        \sort($actual);
        self::assertSame($expected, $actual, "{$route} format coverage drifted from the projection");
    }

    /**
     * source_op is the uniform derivation (same_format→compress,
     * format_change→convert); the SDK derives it rather than storing it, so pin
     * that the projection still agrees on every cell.
     *
     * @param 'same_format'|'format_change' $route
     */
    #[DataProvider('routeProvider')]
    public function test_route_source_op_is_uniform(string $route, string $expectedSourceOp): void
    {
        $cells = self::$image[$route];
        self::assertIsArray($cells);
        foreach ($cells as $fmt => $cell) {
            self::assertIsArray($cell);
            self::assertSame($expectedSourceOp, $cell['source_op'] ?? null, "{$route}.{$fmt} source_op drifted");
        }
    }

    /**
     * @param 'same_format'|'format_change' $route
     */
    #[DataProvider('routeProvider')]
    public function test_route_honored_options_match(string $route, string $expectedSourceOp): void
    {
        $cells = self::$image[$route];
        self::assertIsArray($cells);
        foreach ($cells as $fmt => $cell) {
            self::assertIsArray($cell);
            $expected = $cell['honored_options'];
            self::assertIsArray($expected);
            \sort($expected);
            $actual = ImageOutputRoutes::IMAGE_OUTPUT_ROUTES[$route][$fmt]['honored'];
            \sort($actual);
            self::assertSame($expected, $actual, "{$route}.{$fmt} honored options drifted");
        }
    }

    /**
     * @param 'same_format'|'format_change' $route
     */
    #[DataProvider('routeProvider')]
    public function test_route_planned_options_match(string $route, string $expectedSourceOp): void
    {
        $cells = self::$image[$route];
        self::assertIsArray($cells);
        foreach ($cells as $fmt => $cell) {
            self::assertIsArray($cell);
            $expected = $cell['planned_options'];
            self::assertIsArray($expected);
            \sort($expected);
            $actual = ImageOutputRoutes::IMAGE_OUTPUT_ROUTES[$route][$fmt]['planned'];
            \sort($actual);
            self::assertSame($expected, $actual, "{$route}.{$fmt} planned options drifted");
        }
    }

    /**
     * @return iterable<string, array{0: 'same_format'|'format_change', 1: string}>
     */
    public static function routeProvider(): iterable
    {
        yield 'same_format' => ['same_format', 'compress'];
        yield 'format_change' => ['format_change', 'convert'];
    }

    // --- output verb allowlist conformance ----------------------------------

    /**
     * The UNION of every image route's honored+planned option keys (the full
     * contract surface output() can emit, incl. `output_format`). Mirrors the TS
     * `projectionUnionAll()`.
     *
     * @return list<string>
     */
    private static function projectionUnionAll(): array
    {
        $keys = [];
        foreach (['same_format', 'format_change'] as $route) {
            $cells = self::$image[$route];
            self::assertIsArray($cells);
            foreach ($cells as $cell) {
                self::assertIsArray($cell);
                foreach ([...$cell['honored_options'], ...$cell['planned_options']] as $k) {
                    $keys[(string) $k] = true;
                }
            }
        }
        $union = \array_keys($keys);
        \sort($union);

        return $union;
    }

    public function test_runtime_validator_allowed_keys_equal_the_full_projection_union(): void
    {
        // Like convert, the runtime allowlist INCLUDES the positional-owned
        // output_format (in every route's honored set; rejected first by the
        // POSITIONAL_OWNED guard, not the allowed-key check). Pins the PHP
        // allowlist == the full projection union, matching TS
        // `new Set([...VERB_OPTION_KEYS.output, 'output_format'])`.
        $actual = \array_keys(OptionValidation::allowedKeysFor('output'));
        \sort($actual);
        self::assertSame(self::projectionUnionAll(), $actual);
    }

    public function test_every_user_supplyable_bag_key_is_the_projection_union_minus_output_format(): void
    {
        // The user-supplyable bag keys (the TS OUTPUT_OPTION_KEYS / VERB_OPTION_KEYS.output)
        // are the projection union MINUS the positional-owned output_format. PHP exposes
        // no public bag-key list, so pin it behaviourally: every union key except
        // output_format must be ACCEPTED by validateVerbOptions('output', …), and
        // output_format must be REJECTED (positional-owned). This proves the SDK's
        // private OUTPUT_OPTION_KEYS list equals the projection union minus output_format
        // independent of how the runtime allowlist is composed.
        foreach (self::projectionUnionAll() as $key) {
            if ($key === 'output_format') {
                try {
                    OptionValidation::validateVerbOptions('output', [$key => 'webp']);
                    self::fail('output_format must be rejected as positional-owned');
                } catch (GislConfigError $err) {
                    self::assertSame('unknown_field', $err->reason, 'output_format rejection reason');
                    self::assertSame([$key], $err->conflictingFields);
                }
                continue;
            }
            // A representative value per key type; the validator only checks the KEY.
            $value = \in_array($key, ImageOutputRoutes::RESIZE_KEYS, true) ? 100 : 'x';
            OptionValidation::validateVerbOptions('output', [$key => $value]);
            self::addToAssertionCount(1);
        }
    }

    // --- enum-membership table conformance (rtkzl9gr) -----------------------

    public function test_compress_option_values_cover_exactly_the_image_groups(): void
    {
        $imageGroups = \array_values(\array_filter(
            \array_keys(self::$compressGroups),
            static fn (string $g): bool => $g === 'image' || \str_starts_with($g, 'image_'),
        ));
        \sort($imageGroups);
        $actual = \array_keys(ImageOutputRoutes::COMPRESS_OPTION_VALUES);
        \sort($actual);
        self::assertSame($imageGroups, $actual, 'COMPRESS_OPTION_VALUES image-group coverage drifted from availability.json');
    }

    public function test_compress_option_values_mirror_availability_enum_members(): void
    {
        foreach (ImageOutputRoutes::COMPRESS_OPTION_VALUES as $group => $options) {
            $groupData = self::$compressGroups[$group] ?? null;
            self::assertIsArray($groupData, "availability.json missing compress group {$group}");
            self::assertIsArray($groupData['options'] ?? null);

            // Collect availability's enum options (name -> members). The runtime
            // gate uses STRICT string membership, so pin that the contract keeps
            // these members as strings — a string→number contract change (which
            // the gate would then reject) surfaces HERE, not silently via a cast.
            $enumOpts = [];
            foreach ($groupData['options'] as $optName => $optDef) {
                if (\is_array($optDef) && ($optDef['type'] ?? null) === 'enum') {
                    $values = \is_array($optDef['values'] ?? null) ? $optDef['values'] : [];
                    foreach ($values as $v) {
                        self::assertIsString($v, "{$group}.{$optName} enum member must be a string (strict gate)");
                    }
                    $enumOpts[(string) $optName] = \array_values($values);
                }
            }

            // Every enum option must be mirrored, and vice versa.
            $expectedKeys = \array_keys($enumOpts);
            \sort($expectedKeys);
            $actualKeys = \array_keys($options);
            \sort($actualKeys);
            self::assertSame($expectedKeys, $actualKeys, "{$group} enum-option coverage drifted");

            foreach ($enumOpts as $opt => $expectedValues) {
                \sort($expectedValues);
                $actualValues = $options[$opt];
                \sort($actualValues);
                self::assertSame($expectedValues, $actualValues, "{$group}.{$opt} enum members drifted");
            }
        }
    }

    // --- general depends_on conformance (ehHU08Hu) --------------------------

    /**
     * Normalise an availability `depends_on` to the flat-table rule shape.
     *
     * @param array<string, mixed> $dep
     *
     * @return array{requiresKey: string, requiresValue: string}|array{requiresAnyOf: list<string>}
     */
    private static function normaliseDependsOn(array $dep): array
    {
        // FAIL CLOSED on any shape the flat model can't represent (multi-key AND,
        // array / set-membership values, a `logic` other than `or`) — a contract
        // regen that introduces an unsupported form fails HERE, not silently.
        if (\array_key_exists('logic', $dep)) {
            self::assertSame('or', $dep['logic']);
            $keys = [];
            foreach ($dep as $k => $v) {
                if ($k === 'logic') {
                    continue;
                }
                self::assertSame('set', $v, "unsupported condition value for '{$k}'");
                $keys[] = (string) $k;
            }
            \sort($keys);

            return ['requiresAnyOf' => $keys];
        }
        self::assertCount(1, $dep, 'unsupported multi-key depends_on');
        $key = (string) \array_key_first($dep);
        self::assertIsString($dep[$key], "unsupported non-scalar depends_on value for '{$key}'");

        return ['requiresKey' => $key, 'requiresValue' => $dep[$key]];
    }

    /**
     * Every image-group option → depends_on, asserting the SAME option carries the
     * same depends_on in every group (which justifies the FLAT hand table).
     *
     * @return array<string, array{requiresKey: string, requiresValue: string}|array{requiresAnyOf: list<string>}>
     */
    private static function contractDependsOn(): array
    {
        $rules = [];
        foreach (self::$compressGroups as $group => $groupData) {
            if (($group !== 'image' && !\str_starts_with((string) $group, 'image_')) || !\is_array($groupData['options'] ?? null)) {
                continue;
            }
            foreach ($groupData['options'] as $opt => $optDef) {
                if (!\is_array($optDef) || !\is_array($optDef['depends_on'] ?? null)) {
                    continue;
                }
                $rule = self::normaliseDependsOn($optDef['depends_on']);
                if (isset($rules[$opt])) {
                    self::assertSame($rules[$opt], $rule, "depends_on for '{$opt}' differs across image groups");
                }
                $rules[(string) $opt] = $rule;
            }
        }

        return $rules;
    }

    public function test_output_option_depends_on_covers_exactly_the_image_options_with_depends_on(): void
    {
        $expected = \array_keys(self::contractDependsOn());
        \sort($expected);
        $actual = \array_keys(ImageOutputRoutes::OUTPUT_OPTION_DEPENDS_ON);
        \sort($actual);
        self::assertSame($expected, $actual, 'OUTPUT_OPTION_DEPENDS_ON coverage drifted from availability.json');
    }

    public function test_output_option_depends_on_matches_availability(): void
    {
        foreach (self::contractDependsOn() as $opt => $rule) {
            $hand = ImageOutputRoutes::OUTPUT_OPTION_DEPENDS_ON[$opt] ?? null;
            self::assertIsArray($hand, "OUTPUT_OPTION_DEPENDS_ON missing '{$opt}'");
            if (isset($hand['requiresAnyOf'])) {
                $keys = $hand['requiresAnyOf'];
                \sort($keys);
                $hand = ['requiresAnyOf' => $keys];
            }
            self::assertSame($rule, $hand, "depends_on for '{$opt}' drifted");
        }
    }

    /**
     * Convert (`format_change`) depends_on conformance — card L2Ay7Uak.
     *
     * L2Ay7Uak proposed a SECOND hand table of convert depends_on rules validated on
     * the format_change route, since dependsOnViolation() deliberately skips scalar
     * deps there. A runtime table would be dead code: every convert image depends_on
     * is keyed on `output_format`, and the per-target `honored_options` projection the
     * lowering already enforces IS that constraint materialised — output('gif', ['quality' => 80])
     * is rejected by the honored gate before dependsOnViolation() is reached.
     *
     * That equivalence was the load-bearing claim and nothing pinned it. This does,
     * and FAILS CLOSED on a dependency shape the honored projection cannot encode
     * (a key other than output_format), which WOULD need the runtime gate.
     *
     * Mirrors the TS `convert format_change depends_on is subsumed by the honored
     * projection` suite.
     */
    public function test_convert_depends_on_is_subsumed_by_the_honored_projection(): void
    {
        $formatChange = self::$image['format_change'];
        self::assertIsArray($formatChange);
        $targets = \array_keys($formatChange);

        $checked = 0;
        foreach (self::$convertGroups as $group => $groupData) {
            if (($group !== 'image' && !\str_starts_with((string) $group, 'image_')) || !\is_array($groupData['options'] ?? null)) {
                continue;
            }
            foreach ($groupData['options'] as $option => $def) {
                if (!\is_array($def) || !\is_array($def['depends_on'] ?? null)) {
                    continue;
                }
                $dep = $def['depends_on'];
                // `logic: or` set-conditions (fit -> width|height) are media-agnostic,
                // already validated on BOTH routes, and are not output_format rules.
                if (isset($dep['logic'])) {
                    self::assertSame('or', $dep['logic'], "{$group}.{$option}: only 'or' set-logic is modelled");
                    $conditions = [];
                    foreach ($dep as $k => $v) {
                        if ($k === 'logic') {
                            continue;
                        }
                        self::assertSame('set', $v, "{$group}.{$option}: set-condition '{$k}'");
                        $conditions[] = (string) $k;
                    }
                    // A set-condition is NOT an output_format rule, so the honored
                    // projection does not encode it — it is gated at runtime by
                    // OUTPUT_OPTION_DEPENDS_ON on BOTH routes. Skipping it without
                    // checking would let a convert-side set-condition drift pass
                    // conformance while the runtime gate stayed stale (codex).
                    $handRule = ImageOutputRoutes::OUTPUT_OPTION_DEPENDS_ON[(string) $option] ?? null;
                    self::assertIsArray(
                        $handRule,
                        "convert {$group}.{$option} carries a set-condition depends_on, which the honored "
                            . 'projection cannot encode, but OUTPUT_OPTION_DEPENDS_ON has no rule for it — '
                            . 'the format_change route would be ungated',
                    );
                    $handAnyOf = $handRule['requiresAnyOf'] ?? null;
                    self::assertIsArray($handAnyOf, "convert {$group}.{$option}: runtime rule must be a set-condition");
                    $handAnyOf = \array_map(strval(...), $handAnyOf);
                    \sort($handAnyOf);
                    \sort($conditions);
                    self::assertSame(
                        $conditions,
                        $handAnyOf,
                        "convert {$group}.{$option}: runtime rule must be the same set-condition as the contract",
                    );
                    ++$checked;
                    continue;
                }
                self::assertCount(1, $dep, "{$group}.{$option}: only single-key depends_on is modelled");
                $key = (string) \array_key_first($dep);
                self::assertSame(
                    'output_format',
                    $key,
                    "{$group}.{$option}: depends_on '{$key}' is not keyed on output_format, so the "
                        . 'honored projection does not encode it — this needs a runtime gate on the '
                        . 'format_change route, not just this conformance check',
                );
                $value = $dep[$key];
                $allowed = \is_array($value) ? \array_map(strval(...), $value) : [(string) $value];

                foreach ($targets as $target) {
                    $cell = $formatChange[$target];
                    self::assertIsArray($cell);
                    $honoredOptions = $cell['honored_options'];
                    self::assertIsArray($honoredOptions);
                    $honored = \in_array((string) $option, \array_map(strval(...), $honoredOptions), true);
                    self::assertSame(
                        \in_array((string) $target, $allowed, true),
                        $honored,
                        "convert {$group}.{$option} depends_on output_format " . \json_encode($allowed)
                            . ", but the format_change '{$target}' route "
                            . ($honored ? 'HONORS' : 'does not honor')
                            . ' it — the honored gate and the contract dependency disagree',
                    );
                }
                ++$checked;
            }
        }

        // Guard a vacuous pass: if the convert catalogue ever loses every
        // output_format dependency, that is itself a contract change worth seeing.
        self::assertGreaterThan(0, $checked, 'no convert output_format depends_on found to check');
    }

    /**
     * The check above is driven BY the contract, so an option that LOSES its
     * depends_on is simply not visited — and the flat runtime rule, pinned to the
     * COMPRESS groups, would keep rejecting a request convert now considers legal
     * (an over-rejection, pre-upload). Drive this one from the RUNTIME table
     * instead: every convert image group defining an option that carries a
     * set-condition rule must still declare that same condition (codex).
     */
    public function test_convert_still_declares_the_set_conditions_the_runtime_rule_enforces(): void
    {
        foreach (ImageOutputRoutes::OUTPUT_OPTION_DEPENDS_ON as $option => $rule) {
            $requiresAnyOf = $rule['requiresAnyOf'] ?? null;
            if (!\is_array($requiresAnyOf)) {
                continue;
            }
            $expected = \array_map(strval(...), $requiresAnyOf);
            \sort($expected);

            foreach (self::$convertGroups as $group => $groupData) {
                if (($group !== 'image' && !\str_starts_with((string) $group, 'image_')) || !\is_array($groupData['options'] ?? null)) {
                    continue;
                }
                $def = $groupData['options'][$option] ?? null;
                if (!\is_array($def)) {
                    continue;
                }
                $dep = $def['depends_on'] ?? null;
                self::assertIsArray(
                    $dep,
                    "OUTPUT_OPTION_DEPENDS_ON gates '{$option}' on " . \json_encode($expected) . ' for BOTH routes, '
                        . "but convert group '{$group}' no longer declares a depends_on for it — the format_change "
                        . 'route would reject a request the contract now allows',
                );
                self::assertSame('or', $dep['logic'] ?? null, "{$group}.{$option}: expected an 'or' set-condition");
                $conditions = \array_map(strval(...), \array_keys(\array_diff_key($dep, ['logic' => true])));
                \sort($conditions);
                self::assertSame($expected, $conditions, "{$group}.{$option}: set-condition drifted from the runtime rule");
            }
        }
    }

    public function test_encoding_mode_default_matches_availability(): void
    {
        // The general gate reads this default when encoding_mode is absent.
        foreach (self::$compressGroups as $group => $groupData) {
            if (($group !== 'image' && !\str_starts_with((string) $group, 'image_')) || !\is_array($groupData['options'] ?? null)) {
                continue;
            }
            $mode = $groupData['options']['encoding_mode'] ?? null;
            if (!\is_array($mode)) {
                continue;
            }
            self::assertSame(
                ImageOutputRoutes::DEPENDS_ON_KEY_DEFAULTS['encoding_mode'],
                $mode['default'] ?? null,
                "encoding_mode default drifted for {$group}",
            );
        }
    }

    // --- group-mapping reachability pin (SB1wmTJz) ---------------------------

    /**
     * The pin that stops a FOURTH visit to this defect.
     *
     * `rtkzl9gr` fixed this class for the enum-membership gate and left the sibling
     * per-value gate reading a hand-written token list whose trailing comment
     * ("// webp / gif / svg / tiff") was true when written and silently became false
     * once image_svg and image_webp were added to the metadata. SVG inputs therefore
     * missed the one marker that mattered for them, on the preset gate AND on output().
     *
     * So this does NOT pin a token list — a token list is exactly what went stale. It
     * asserts the PROPERTY, driven from the metadata's real groups: every planned
     * per-value marker on a concrete image_<token> group must be reachable through
     * isPlannedValue for that token. Add a group to CompressMetadata and forget the
     * mapping, and this fails. It exercises the shared function, so it covers EVERY
     * gate that consults it, not just the one being fixed today.
     */
    public function test_every_concrete_group_marker_is_reachable_for_its_token(): void
    {
        $checked = 0;
        foreach (CompressMetadata::instance()->mime_groups as $groupName => $group) {
            if (!\str_starts_with((string) $groupName, 'image_')) {
                continue;
            }
            $token = \substr((string) $groupName, \strlen('image_'));
            foreach ($group->options as $optionKey => $option) {
                foreach ($option->per_value_availability as $value => $entry) {
                    if ($entry->availability !== 'planned') {
                        continue;
                    }
                    ++$checked;
                    self::assertTrue(
                        ImageOutputRoutes::isPlannedValue($token, (string) $optionKey, $value),
                        "planned marker {$groupName}.{$optionKey}={$value} is unreachable for token "
                        . "'{$token}' — the group mapping has gone stale again",
                    );
                }
            }
        }

        // Positive control: if the metadata ever stops carrying concrete-group markers
        // the loop above becomes a no-op and would pass while proving nothing.
        self::assertGreaterThan(0, $checked, 'no concrete-group planned markers found — pin is vacuous');
    }

    /**
     * The historical verdicts must be UNCHANGED — the widening is purely additive.
     * webp + color_profile 'srgb' stays GATED (RecipeOutputTest pins it) and jpeg srgb
     * stays LIVE. If a future change flips either, it is a behaviour change and must be
     * argued, not absorbed.
     */
    public function test_widening_is_additive_historical_verdicts_hold(): void
    {
        self::assertTrue(ImageOutputRoutes::isPlannedValue('webp', 'color_profile', 'srgb'));
        self::assertFalse(ImageOutputRoutes::isPlannedValue('jpeg', 'color_profile', 'srgb'));
        // …and the marker this ticket exists for is now reachable.
        self::assertTrue(ImageOutputRoutes::isPlannedValue('svg', 'output_format', 'original'));
    }
}
