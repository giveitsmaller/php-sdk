<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Conformance;

use Gisl\Generated\Operations\CompressMetadata;
use Gisl\Sdk\Ergonomic\ImageOutputRoutes;
use Gisl\Sdk\Ergonomic\PresetResolver;
use PHPUnit\Framework\TestCase;

/**
 * Code-builder compress conformance gate (card 0fNO60BX — folds Y4Fsl3Sk). PHP arm
 * of the TS `code-builder-conformance.test.ts` — keep in lockstep.
 *
 * The hand-maintained compress `KNOWN_WIRE_FIELDS` allowlists only prevented BOGUS
 * fields — they never PROVED every contract compress option the contract intends the
 * SDK to expose is reachable via some ergonomic verb (the Y4Fsl3Sk drift class). This
 * suite closes that, driven from the contract-authored `code-builder-metadata.json`
 * sidecar (the SDK+FE shared `sdk_exposure` gate) rather than the SDK's own guess.
 *
 * It is the SINGLE source of truth for compress option classification (the compress
 * block of `WireKeyConformanceTest` was removed in this ticket).
 *
 * The sidecar ships at `<generated-php-root>/code-builder/code-builder-metadata.json`,
 * resolved by reflecting a generated metadata class (operations/src/*.php → dirname x3),
 * exactly like the availability + image-output-routes conformance tests.
 *
 * SCOPE: option-KEY level, WIRE keys (snake_case) only. Two axes are OUT OF SCOPE:
 *   - Enum VALUE-level exposure (`value_exposure` / `per_value_availability`) — owned
 *     by the per-value machinery ({@see ImageOutputRoutes::isPlannedValue}).
 *   - SURFACE-key -> wire resolution: the metadata declares a camelCase `surface_key`
 *     (what a frontend snippet types, e.g. `trimStart`); this gate does NOT assert the
 *     SDK's alias map (`PresetResolver` WIRE_ALIASES) translates every `surface_key` to
 *     its wire key. Distinct conformance axis (SDK<->metadata naming), tracked in the
 *     follow-up ticket 3v4SKGvG (WIRE_ALIASES misses a few camel keys today).
 */
final class CodeBuilderConformanceTest extends TestCase
{
    /** @var array<string, mixed> The decoded code-builder-metadata.json. */
    private static array $metadata = [];

    /**
     * CROSS_VERB_ROUTING: compress-catalog options surfaced by a DIFFERENT verb.
     * Self-verified below against the real verb surfaces so the map cannot lie.
     *
     * @var array<string, array<string, 'output'|'convert'>>
     */
    private const CROSS_VERB_ROUTING = [
        'image' => [
            'width' => 'output', 'height' => 'output', 'fit' => 'output',
            'lossless' => 'output', 'encoding_mode' => 'output', 'target_size_bytes' => 'output',
            'chroma_subsampling' => 'output', 'quality_preset' => 'output',
            'color_profile' => 'output', 'auto_orient' => 'output',
            'progressive' => 'output', 'optimization_level' => 'output', 'avif_speed' => 'output',
        ],
        'audio' => ['output_format' => 'convert'],
        'video' => ['output_format' => 'convert'],
    ];

    /**
     * DEFERRED_EXPOSURE: expose+contract compress options reachable by NO verb today
     * (product-scope — tracked in the follow-up ticket). Drift-guarded below.
     *
     * @var array<string, list<string>>
     */
    private const DEFERRED_EXPOSURE = [
        'document_office' => ['quality'],
        'document_odf' => ['quality'],
        'document_epub' => ['quality'],
    ];

    /**
     * PRE_EXPOSED: keys KNOWN_WIRE_FIELDS allows AHEAD of the contract (contract marks
     * them `coming_soon`). Documented + drift-guarded (must stay `coming_soon`).
     *
     * @var array<string, list<string>>
     */
    private const PRE_EXPOSED = [
        'document_office' => ['strip_macros', 'strip_hidden_data', 'strip_unused_fonts'],
        'document_odf' => ['strip_metadata', 'strip_unused_styles'],
        'document_epub' => ['font_subsetting', 'strip_unused_css'],
    ];

    /**
     * The contract's own availability → sdk_exposure derivation
     * (build-code-builder-metadata.py). Absent availability ⇒ stable ⇒ expose.
     *
     * @var array<string, string>
     */
    private const EXPOSURE_BY_AVAILABILITY = [
        'stable' => 'expose', 'beta' => 'expose',
        'experimental' => 'expose_optin',
        'planned' => 'coming_soon',
        'deprecated' => 'deprecated',
    ];

    public static function setUpBeforeClass(): void
    {
        // operations/src/CompressMetadata.php -> dirname x3 = generated/php root.
        $opFile = (new \ReflectionClass(CompressMetadata::class))->getFileName();
        $root = \dirname((string) $opFile, 3);
        $path = $root . '/code-builder/code-builder-metadata.json';
        $json = \json_decode((string) \file_get_contents($path), true);
        self::assertIsArray($json, 'code-builder-metadata.json must decode to an array');
        self::assertIsArray($json['operations'] ?? null);
        self::$metadata = $json;
    }

    // --- helpers -------------------------------------------------------------

    /** @return array<string, array<string, mixed>> compress media_groups. */
    private static function compressGroups(): array
    {
        /** @var array<string, array<string, mixed>> $groups */
        $groups = self::$metadata['operations']['compress']['media_groups'];
        return $groups;
    }

    /** The SDK folds the six `image*` contract groups into ONE `image` media. */
    private static function sdkMediaFor(string $group): string
    {
        return \str_starts_with($group, 'image') ? 'image' : $group;
    }

    /** @param array<string, mixed> $opt */
    private static function isInScope(array $opt): bool
    {
        return ($opt['origin'] ?? null) === 'contract' && ($opt['sdk_exposure'] ?? null) === 'expose';
    }

    /** @return list<string> */
    private static function knownFor(string $media): array
    {
        return PresetResolver::KNOWN_WIRE_FIELDS[$media] ?? [];
    }

    /** @return 'native'|'routing'|'deferred'|'uncovered' */
    private static function classify(string $group, string $key): string
    {
        $media = self::sdkMediaFor($group);
        if (\in_array($key, self::knownFor($media), true)) {
            return 'native';
        }
        if (isset(self::CROSS_VERB_ROUTING[$media][$key])) {
            return 'routing';
        }
        if (\in_array($key, self::DEFERRED_EXPOSURE[$media] ?? [], true)) {
            return 'deferred';
        }
        return 'uncovered';
    }

    /**
     * Wire keys reachable for a contract group via EVERY applicable ergonomic surface.
     *
     * @return array<string, true>
     */
    private static function mediaReachableKeys(string $group): array
    {
        $media = self::sdkMediaFor($group);
        $reachable = [];
        foreach (self::knownFor($media) as $k) {
            $reachable[$k] = true;
        }
        if ($media === 'image') {
            /** @var list<string> $mimes */
            $mimes = self::compressGroups()[$group]['mimes'] ?? [];
            foreach ($mimes as $mime) {
                $token = ImageOutputRoutes::tokenForMime($mime);
                $cell = $token !== null ? (ImageOutputRoutes::IMAGE_OUTPUT_ROUTES['same_format'][$token] ?? null) : null;
                if ($cell !== null) {
                    foreach ($cell['honored'] as $k) {
                        $reachable[$k] = true;
                    }
                }
            }
            $reachable['output_format'] = true; // convert()
        } elseif ($media === 'audio' || $media === 'video') {
            $reachable['output_format'] = true; // convert()
        }
        return $reachable;
    }

    /**
     * Every compress `[group, key, opt]` whose option is in-scope (expose+contract).
     *
     * @return list<array{group: string, key: string, opt: array<string, mixed>}>
     */
    private static function inScopeEntries(): array
    {
        $out = [];
        foreach (self::compressGroups() as $group => $mg) {
            /** @var array<string, array<string, mixed>> $options */
            $options = $mg['options'] ?? [];
            foreach ($options as $key => $opt) {
                if (self::isInScope($opt)) {
                    $out[] = ['group' => $group, 'key' => $key, 'opt' => $opt];
                }
            }
        }
        return $out;
    }

    // --- assertions (mirror the TS `it()` blocks) ----------------------------

    public function test_availability_maps_to_sdk_exposure_and_no_expose_optin_exists(): void
    {
        $exposeOptin = 0;
        foreach (self::compressGroups() as $mg) {
            /** @var array<string, array<string, mixed>> $options */
            $options = $mg['options'] ?? [];
            foreach ($options as $key => $opt) {
                $availability = \is_string($opt['availability'] ?? null) ? $opt['availability'] : 'stable';
                $expected = self::EXPOSURE_BY_AVAILABILITY[$availability] ?? null;
                self::assertNotNull($expected, "unknown availability '{$availability}' for compress option '{$key}'");
                self::assertSame(
                    $expected,
                    $opt['sdk_exposure'] ?? null,
                    "compress '{$key}': availability '{$availability}' should derive sdk_exposure '{$expected}'",
                );
                if (($opt['sdk_exposure'] ?? null) === 'expose_optin') {
                    $exposeOptin++;
                }
            }
        }
        self::assertSame(0, $exposeOptin, 'expose_optin compress options exist — extend the gate');
    }

    public function test_every_in_scope_option_is_classified_into_exactly_one_bucket(): void
    {
        $uncovered = [];
        foreach (self::inScopeEntries() as $e) {
            if (self::classify($e['group'], $e['key']) === 'uncovered') {
                $uncovered[] = "{$e['group']}.{$e['key']}";
            }
        }
        self::assertSame(
            [],
            $uncovered,
            'expose+contract compress option(s) reachable by no SDK bucket: ' . \json_encode($uncovered)
                . '. Expose them, route them, or add to DEFERRED_EXPOSURE.',
        );
    }

    public function test_buckets_are_pairwise_disjoint_per_media(): void
    {
        $medias = [];
        foreach (\array_keys(self::compressGroups()) as $group) {
            $medias[self::sdkMediaFor($group)] = true;
        }
        foreach (\array_keys($medias) as $media) {
            $native = self::knownFor($media);
            $routing = \array_keys(self::CROSS_VERB_ROUTING[$media] ?? []);
            $deferred = self::DEFERRED_EXPOSURE[$media] ?? [];
            $overlaps = [];
            foreach ($native as $k) {
                if (\in_array($k, $routing, true) || \in_array($k, $deferred, true)) {
                    $overlaps[] = "{$media}.{$k}";
                }
            }
            foreach ($routing as $k) {
                if (\in_array($k, $deferred, true)) {
                    $overlaps[] = "{$media}.{$k}";
                }
            }
            self::assertSame([], $overlaps, "bucket overlap for media '{$media}': " . \json_encode($overlaps));
        }
    }

    public function test_every_cross_verb_routing_entry_is_a_real_expose_contract_option(): void
    {
        // Mirrors the DEFERRED/PRE_EXPOSED guards: the honored-check only covers IN-SCOPE
        // routing keys, so a stale/typo'd entry whose key regressed out of expose would sit
        // unvalidated. Pin every routing entry to a real expose+contract compress option.
        $inScopeByMedia = [];
        foreach (self::inScopeEntries() as $e) {
            $media = self::sdkMediaFor($e['group']);
            $inScopeByMedia[$media][$e['key']] = true;
        }
        foreach (self::CROSS_VERB_ROUTING as $media => $routes) {
            foreach (\array_keys($routes) as $key) {
                self::assertArrayHasKey(
                    $key,
                    $inScopeByMedia[$media] ?? [],
                    "CROSS_VERB_ROUTING['{$media}']['{$key}'] is not an expose+contract compress option "
                        . "for '{$media}' — a stale/typo'd routing entry the honored-check silently skips.",
                );
            }
        }
    }

    public function test_cross_verb_routed_keys_are_honored_by_their_target_verb(): void
    {
        foreach (self::inScopeEntries() as $e) {
            if (self::classify($e['group'], $e['key']) !== 'routing') {
                continue;
            }
            $media = self::sdkMediaFor($e['group']);
            $verb = self::CROSS_VERB_ROUTING[$media][$e['key']];
            if ($verb === 'output') {
                // Honored on EVERY format token the raw group covers (base image = gif+tiff).
                /** @var list<string> $mimes */
                $mimes = self::compressGroups()[$e['group']]['mimes'] ?? [];
                foreach ($mimes as $mime) {
                    $token = ImageOutputRoutes::tokenForMime($mime);
                    self::assertNotNull($token, "unknown image mime '{$mime}' in group '{$e['group']}'");
                    $cell = ImageOutputRoutes::IMAGE_OUTPUT_ROUTES['same_format'][$token] ?? null;
                    self::assertNotNull($cell, "no same_format route for token '{$token}'");
                    self::assertContains(
                        $e['key'],
                        $cell['honored'],
                        "{$e['group']}.{$e['key']} routes to output() but is not honored on the '{$token}' route",
                    );
                }
            } else {
                // convert(): media-scoped — the convert op must expose this key for THIS media group.
                $convertGroups = self::$metadata['operations']['convert']['media_groups'] ?? [];
                self::assertArrayHasKey($e['group'], $convertGroups, "convert has no '{$e['group']}' media group");
                /** @var array<string, mixed> $convertOptions */
                $convertOptions = $convertGroups[$e['group']]['options'] ?? [];
                self::assertArrayHasKey(
                    $e['key'],
                    $convertOptions,
                    "{$e['group']}.{$e['key']} routes to convert() but convert.{$e['group']} does not expose it",
                );
            }
        }
    }

    public function test_deferred_keys_are_reachable_by_no_ergonomic_surface(): void
    {
        foreach (self::DEFERRED_EXPOSURE as $media => $keys) {
            $group = null;
            foreach (\array_keys(self::compressGroups()) as $g) {
                if (self::sdkMediaFor($g) === $media) {
                    $group = $g;
                    break;
                }
            }
            self::assertNotNull($group, "no compress contract group maps to SDK media '{$media}'");
            $reachable = self::mediaReachableKeys($group);
            foreach ($keys as $key) {
                self::assertArrayNotHasKey(
                    $key,
                    $reachable,
                    "deferred '{$media}.{$key}' IS reachable via an ergonomic surface — reclassify it.",
                );
                /** @var array<string, mixed> $opt */
                $opt = self::compressGroups()[$group]['options'][$key] ?? [];
                self::assertNotEmpty($opt, "deferred '{$media}.{$key}' is not a contract option");
                self::assertTrue(self::isInScope($opt), "deferred '{$media}.{$key}' is no longer expose+contract");
            }
        }
    }

    public function test_every_known_wire_field_is_expose_or_a_pre_exposed_coming_soon_key(): void
    {
        // media → { wireKey → sdk_exposure } from the metadata (image family folds).
        $exposureByMediaKey = [];
        foreach (self::compressGroups() as $group => $mg) {
            $media = self::sdkMediaFor($group);
            /** @var array<string, array<string, mixed>> $options */
            $options = $mg['options'] ?? [];
            foreach ($options as $key => $opt) {
                $mk = "{$media}.{$key}";
                $exposure = $opt['sdk_exposure'] ?? null;
                if (!isset($exposureByMediaKey[$mk]) || $exposure === 'expose') {
                    $exposureByMediaKey[$mk] = $exposure;
                }
            }
        }
        foreach (PresetResolver::KNOWN_WIRE_FIELDS as $media => $keys) {
            foreach ($keys as $key) {
                $exposure = $exposureByMediaKey["{$media}.{$key}"] ?? null;
                self::assertNotNull($exposure, "KNOWN_WIRE_FIELDS['{$media}'] key '{$key}' is not a compress contract option");
                if ($exposure === 'expose') {
                    continue;
                }
                self::assertContains(
                    $key,
                    self::PRE_EXPOSED[$media] ?? [],
                    "KNOWN_WIRE_FIELDS['{$media}'] key '{$key}' is '{$exposure}' (not expose) and not in PRE_EXPOSED "
                        . '— the SDK emits a field the contract has not exposed. Reconcile or document it.',
                );
                self::assertSame('coming_soon', $exposure, "PRE_EXPOSED['{$media}'] key '{$key}' is no longer 'coming_soon'");
            }
        }
        // No stale PRE_EXPOSED entries.
        foreach (self::PRE_EXPOSED as $media => $keys) {
            foreach ($keys as $key) {
                self::assertContains($key, self::knownFor($media), "PRE_EXPOSED['{$media}'] key '{$key}' is not in KNOWN_WIRE_FIELDS");
            }
        }
    }

    public function test_negative_control_partition_reverse_and_reachability_have_teeth(): void
    {
        // (a) uncovered: a new expose+contract option in no bucket is flagged.
        self::assertSame('uncovered', self::classify('audio', '__synthetic_unexposed_key__'));

        // (c) per-token strictness: `progressive` is jpeg-only; the base `image` group's gif
        // route does NOT honor it, so routing it under the multi-token base group would fail.
        self::assertNotContains('progressive', ImageOutputRoutes::IMAGE_OUTPUT_ROUTES['same_format']['gif']['honored']);
        self::assertContains('progressive', ImageOutputRoutes::IMAGE_OUTPUT_ROUTES['same_format']['jpeg']['honored']);

        // (b) reverse exact-gate: a `coming_soon` KNOWN_WIRE_FIELDS key is REJECTED unless it
        // is in PRE_EXPOSED. Pin the predicate so it survives PRE_EXPOSED shrinking.
        $acceptable = static fn (string $media, string $key, string $exposure): bool =>
            $exposure === 'expose' || \in_array($key, self::PRE_EXPOSED[$media] ?? [], true);
        self::assertFalse($acceptable('image', '__synthetic_coming_soon_key__', 'coming_soon'));
        self::assertTrue($acceptable('document_office', 'strip_macros', 'coming_soon'));
        self::assertTrue($acceptable('image', 'quality', 'expose'));

        // (d) reachability has teeth: a routed image key (width → output()) IS reachable, so a
        // DEFERRED entry that is actually reachable would be caught.
        $rasterImageGroup = null;
        foreach (\array_keys(self::compressGroups()) as $g) {
            if (self::sdkMediaFor($g) !== 'image') {
                continue;
            }
            /** @var list<string> $mimes */
            $mimes = self::compressGroups()[$g]['mimes'] ?? [];
            foreach ($mimes as $mime) {
                if (ImageOutputRoutes::tokenForMime($mime) !== 'svg') {
                    $rasterImageGroup = $g;
                    break 2;
                }
            }
        }
        self::assertNotNull($rasterImageGroup, 'a raster image compress group exists');
        self::assertArrayHasKey('width', self::mediaReachableKeys($rasterImageGroup));
    }
}
