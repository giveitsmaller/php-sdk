<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Conformance;

use Gisl\Generated\Operations\CompressMetadata;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Ergonomic\PresetResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Planned-option emission guard (card 5Eksm9s7). PHP arm of the TS
 * `preset-planned-conformance.test.ts`.
 *
 * Published PHP 0.15.0 shipped preset cells for the three document media whose keys
 * are `availability: planned` in the contract we ship. The API rejects a planned
 * option when the KEY IS PRESENT and ignores it when absent
 * (CreateWorkflowCommandHandler::recordPlannedFromMap — a materialized default
 * deliberately does not trigger it), so every document compress with an `optimizeFor`
 * was a 422 `feature_not_available` at create, from a value the caller never typed.
 *
 * The seven keys were the instance; THIS SUITE IS THE DELIVERABLE. It fails closed on
 * the next option contracts flips to `planned`, in both directions: the hand table
 * must be a faithful projection of availability.json, AND no shipped preset cell may
 * emit a planned option at any media x any level — the latter asserted against the
 * resolver's real output and read straight from the sidecar, so it would have caught
 * this bug with an EMPTY table.
 */
#[CoversClass(PresetResolver::class)]
final class PresetPlannedConformanceTest extends TestCase
{
    /** @var array<string, mixed> `operations.compress.mime_groups` of availability.json. */
    private static array $compressGroups = [];

    public static function setUpBeforeClass(): void
    {
        // operations/src/CompressMetadata.php -> dirname x3 = generated/php root.
        $opFile = (new \ReflectionClass(CompressMetadata::class))->getFileName();
        $root = \dirname((string) $opFile, 3);
        $avail = \json_decode((string) \file_get_contents($root . '/availability/availability.json'), true);
        self::assertIsArray($avail, 'availability.json must decode to an array');
        $groups = $avail['operations']['compress']['mime_groups'] ?? null;
        self::assertIsArray($groups, 'availability.json compress mime_groups must be an array');
        self::$compressGroups = $groups;
    }

    /**
     * Planned option keys for a compress mime-group, read straight from the sidecar.
     *
     * @return list<string>
     */
    private static function plannedKeysFromContract(string $group): array
    {
        $options = self::$compressGroups[$group]['options'] ?? [];
        self::assertIsArray($options);
        $planned = [];
        foreach ($options as $key => $definition) {
            if (\is_array($definition) && ($definition['availability'] ?? null) === 'planned') {
                $planned[] = (string) $key;
            }
        }
        \sort($planned);

        return $planned;
    }

    /** @return iterable<string, array{string}> */
    public static function presetMediaProvider(): iterable
    {
        foreach (\array_keys(PresetResolver::KNOWN_WIRE_FIELDS) as $media) {
            yield (string) $media => [(string) $media];
        }
    }

    /** @return iterable<string, array{string, OptimizeFor}> */
    public static function mediaLevelProvider(): iterable
    {
        foreach (\array_keys(PresetResolver::KNOWN_WIRE_FIELDS) as $media) {
            foreach ([OptimizeFor::Size, OptimizeFor::Balanced, OptimizeFor::Quality] as $level) {
                yield $media . ' / ' . $level->value => [(string) $media, $level];
            }
        }
    }

    // --- 1. the hand table is a faithful projection --------------------------

    public function test_table_covers_exactly_the_preset_media(): void
    {
        $table = \array_keys(PresetResolver::PLANNED_COMPRESS_OPTIONS);
        $media = \array_keys(PresetResolver::KNOWN_WIRE_FIELDS);
        \sort($table);
        \sort($media);
        self::assertSame($media, $table, 'a new preset medium must not be able to skip the gate');
    }

    #[DataProvider('presetMediaProvider')]
    public function test_hand_table_matches_the_contract(string $media): void
    {
        // Every preset medium must be a real compress mime-group — otherwise the
        // lookup silently yields [] and the whole gate passes vacuously.
        self::assertArrayHasKey($media, self::$compressGroups);

        $fromTable = PresetResolver::PLANNED_COMPRESS_OPTIONS[$media];
        \sort($fromTable);
        self::assertSame(self::plannedKeysFromContract($media), $fromTable);
    }

    public function test_positive_control_the_contract_marks_options_planned(): void
    {
        // Guards against the suite passing because availability.json was restructured
        // and every lookup now yields an empty set — the uniform-and-clean result that
        // would make every assertion above meaningless.
        $total = 0;
        foreach (\array_keys(PresetResolver::KNOWN_WIRE_FIELDS) as $media) {
            $total += \count(self::plannedKeysFromContract((string) $media));
        }
        self::assertGreaterThan(0, $total);
    }

    // --- 2. no shipped preset cell emits a planned option --------------------

    #[DataProvider('mediaLevelProvider')]
    public function test_shipped_presets_never_emit_a_planned_option(string $media, OptimizeFor $level): void
    {
        $resolved = PresetResolver::resolveCompress($media, null, null, null, $level, []);
        $planned = self::plannedKeysFromContract($media);

        $onWire = \array_values(\array_intersect(\array_keys($resolved['wireOptions']), $planned));
        self::assertSame([], $onWire, "{$media}/{$level->value} put a planned option on the wire");

        // The introspection surface must agree with the wire — a caller reading
        // resolvedOptions must not be told we sent something we dropped.
        $applied = \array_values(\array_intersect(\array_keys($resolved['resolvedOptions']->applied), $planned));
        self::assertSame([], $applied);
    }

    public function test_positive_control_a_surviving_cell_still_emits_keys(): void
    {
        // Guards "emits nothing" being read as "emits nothing planned". Uses `video`
        // BECAUSE its cell is untouched by the drop — if this goes empty the drop has
        // over-reached. It deliberately does NOT speak for the document media, which
        // the next test covers and which behave differently.
        $resolved = PresetResolver::resolveCompress('video', null, null, null, OptimizeFor::Size, []);
        self::assertNotSame([], $resolved['wireOptions']);
    }

    /** @return iterable<string, array{string}> */
    public static function documentMediaProvider(): iterable
    {
        foreach (['document_office', 'document_odf', 'document_epub'] as $media) {
            yield $media => [$media];
        }
    }

    #[DataProvider('documentMediaProvider')]
    public function test_known_consequence_document_presets_are_now_a_noop(string $media): void
    {
        // Pinning a real product consequence rather than hiding it: every key these
        // three cells shipped was `planned`, so after the drop all three levels
        // resolve to an EMPTY payload and `optimizeFor` no longer distinguishes Size
        // from Quality for documents. Strictly better than the 422 it replaces, and
        // not nothing: the contract exposes a stable `quality` on these groups that
        // the SDK's document preset DTOs have never carried.
        //
        // Choosing a quality-per-level for documents is a PRODUCT call and would move
        // the generated preset table (and PRESET_VERSION / presetConfigHash), so it is
        // deliberately NOT made here. Raised with the hub; this test fails the moment
        // someone gives these cells a value, which is the point.
        foreach ([OptimizeFor::Size, OptimizeFor::Balanced, OptimizeFor::Quality] as $level) {
            $resolved = PresetResolver::resolveCompress($media, null, null, null, $level, []);
            self::assertSame([], $resolved['wireOptions'], "{$media}/{$level->value}");
        }
    }

    // --- 2b. planned per-VALUE gating on the resolved preset media -----------

    /**
     * Option-level `availability` is not the only way a key can be unavailable: a
     * stable option can carry a `planned` VALUE (`per_value_availability`). A shipped
     * preset emitting such a value is the same defect wearing a different hat.
     *
     * LIMIT, stated so this is not read as more coverage than it is: this checks the
     * PRESET MEDIA's group only. It does NOT check the concrete mime group the server
     * resolves from the actual file — `compress.image_svg` marks
     * `output_format: 'original'` PLANNED and our image preset emits exactly that, so
     * an SVG input is still a live 422. The resolver cannot gate it today: it receives
     * `media: 'image'` and never learns the input format. Filed as SB1wmTJz rather than
     * smuggled into this fix.
     */
    #[DataProvider('mediaLevelProvider')]
    public function test_shipped_presets_never_emit_a_planned_value(string $media, OptimizeFor $level): void
    {
        $resolved = PresetResolver::resolveCompress($media, null, null, null, $level, []);
        $options = self::$compressGroups[$media]['options'] ?? [];
        self::assertIsArray($options);

        $offenders = [];
        foreach ($resolved['wireOptions'] as $key => $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $perValue = $options[$key]['per_value_availability'] ?? [];
            if (\is_array($perValue) && ($perValue[(string) $value]['availability'] ?? null) === 'planned') {
                $offenders[] = $key . '=' . (string) $value;
            }
        }

        self::assertSame([], $offenders, "{$media}/{$level->value} put a planned VALUE on the wire");
    }

    public function test_positive_control_the_contract_marks_some_values_planned(): void
    {
        $found = false;
        foreach (self::$compressGroups as $group) {
            foreach ($group['options'] ?? [] as $option) {
                foreach ($option['per_value_availability'] ?? [] as $entry) {
                    if (($entry['availability'] ?? null) === 'planned') {
                        $found = true;
                    }
                }
            }
        }
        self::assertTrue($found, 'no per-value planned tags found — the gate would pass vacuously');
    }

    // --- 3. a caller-supplied planned option is NOT swallowed ----------------

    public function test_explicit_planned_option_is_kept(): void
    {
        // Drop only what WE put there. An explicit request for a planned option is
        // left for the server to refuse honestly — a silent no-op would be worse than
        // the 422, and would be us committing the defect while fixing it.
        $resolved = PresetResolver::resolveCompress(
            'document_office',
            null,
            null,
            null,
            OptimizeFor::Size,
            ['stripMacros' => true],
        );

        self::assertTrue($resolved['wireOptions']['strip_macros']);
        self::assertContains('strip_macros', $resolved['resolvedOptions']->sources->explicit);
        // …while the sdkDefault-sourced siblings are still dropped.
        self::assertArrayNotHasKey('strip_hidden_data', $resolved['wireOptions']);
        self::assertArrayNotHasKey('strip_unused_fonts', $resolved['wireOptions']);
    }

    public function test_per_call_override_planned_option_is_kept(): void
    {
        $resolved = PresetResolver::resolveCompress(
            'document_epub',
            null,
            null,
            ['fontSubsetting' => false],
            OptimizeFor::Size,
            [],
        );

        self::assertFalse($resolved['wireOptions']['font_subsetting']);
        self::assertContains('font_subsetting', $resolved['resolvedOptions']->sources->callPresetOverride);
        self::assertArrayNotHasKey('strip_unused_css', $resolved['wireOptions']);
    }
}
