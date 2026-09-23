<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Conformance;

use Gisl\Generated\Operations\ImageWatermarkMetadata;
use Gisl\Sdk\FileFirst\WatermarkGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Watermark capability conformance guard (FF4a / Z7zTr789).
 *
 * The watermark planned-op gate reads a hand SDK table ({@see
 * WatermarkGate::CAPABILITY}) rather than the generated typed metadata, because
 * the typed `MimeGroupMetadata` carries NO supported-mime allowlist. The
 * allowlist + availability live only in the raw `availability.json` sidecar.
 * This suite pins the SDK table to that sidecar — a contract regen that changes
 * the supported mimes or availability of `image_watermark` / `video_watermark`
 * fails HERE. Mirrors the TS `watermark-capability-conformance.test.ts` (and the
 * wire-key-conformance pattern). The PHP test/check container ships only
 * `generated/php/`, located here via the contracts package install.
 */
#[CoversClass(WatermarkGate::class)]
final class WatermarkCapabilityConformanceTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $availability = [];

    public static function setUpBeforeClass(): void
    {
        // operations/src/ImageWatermarkMetadata.php -> dirname x3 = generated/php root.
        $opFile = (new \ReflectionClass(ImageWatermarkMetadata::class))->getFileName();
        $root = \dirname((string) $opFile, 3);
        $path = $root . '/availability/availability.json';
        $json = \json_decode((string) \file_get_contents($path), true);
        // Operations are nested under the top-level `operations` key.
        $ops = \is_array($json) && isset($json['operations']) && \is_array($json['operations'])
            ? $json['operations']
            : [];
        self::$availability = $ops;
    }

    /**
     * The contract's availability ladder, MOST-CAUTIOUS FIRST
     * (compression_contracts schemas/availability-ladder.yaml, v2.206.0+). Pinned to
     * that file by scripts/tests/test_availability_ladder_parity.py.
     */
    public const AVAILABILITY_LADDER = [
        'planned',
        'experimental',
        'beta',
        'deprecated',
        'stable_pending_audit',
        'stable',
    ];

    /**
     * PMvwhNI1: resolve a chain MOST-CAUTIOUSLY - the strictest present link wins,
     * an absent key is `stable` at that link. It was "group key, else op key"
     * (precedence), which agreed with the contract on every real cell only by
     * coincidence. Mirrors mostCautious() in the TS conformance test.
     */
    public static function mostCautious(mixed ...$links): string
    {
        $strictest = \count(self::AVAILABILITY_LADDER) - 1;
        foreach ($links as $link) {
            // Absent (null) is `stable`; any OTHER non-string is malformed and must
            // not quietly rank as stable (codex dc3adea955fc) - TS throws too.
            if ($link !== null && !\is_string($link)) {
                throw new \UnexpectedValueException('non-string availability value: ' . \get_debug_type($link));
            }
            $value = $link ?? 'stable';
            $rank = \array_search($value, self::AVAILABILITY_LADDER, true);
            if ($rank === false) {
                throw new \UnexpectedValueException("unknown availability value '{$value}' - not on the contract ladder");
            }
            $strictest = \min($strictest, $rank);
        }

        return self::AVAILABILITY_LADDER[$strictest];
    }

    private function resolvedAvailability(string $op, string $group): string
    {
        /** @var array<string, mixed> $opMeta */
        $opMeta = self::$availability[$op] ?? [];
        /** @var array<string, mixed> $groups */
        $groups = $opMeta['mime_groups'] ?? [];
        /** @var array<string, mixed> $groupMeta */
        $groupMeta = $groups[$group] ?? [];

        return self::mostCautious($opMeta['availability'] ?? null, $groupMeta['availability'] ?? null);
    }

    public function test_a_stable_group_under_a_planned_root_resolves_to_planned(): void
    {
        self::assertSame('planned', self::mostCautious('planned', 'stable'));
        self::assertSame('planned', self::mostCautious(null, 'planned'));
        self::assertSame('experimental', self::mostCautious('beta', 'experimental'));
        self::assertSame('stable', self::mostCautious(null, null));
        foreach ([0, [], false] as $malformed) {
            try {
                self::mostCautious('stable', $malformed);
                self::fail('a non-string availability must not rank as stable');
            } catch (\UnexpectedValueException) {
            }
        }
        $this->expectException(\UnexpectedValueException::class);
        self::mostCautious('stable', 'gamma');
    }

    public function test_capability_table_matches_availability_json(): void
    {
        foreach (WatermarkGate::CAPABILITY as $op => $groups) {
            self::assertArrayHasKey($op, self::$availability, "{$op} is not a real contract operation");
            foreach ($groups as $group => $cell) {
                /** @var array<string, mixed> $opMeta */
                $opMeta = self::$availability[$op];
                /** @var array<string, mixed> $mimeGroups */
                $mimeGroups = $opMeta['mime_groups'] ?? [];
                self::assertArrayHasKey($group, $mimeGroups, "{$op}.{$group} missing in availability.json");
                /** @var array<string, mixed> $groupMeta */
                $groupMeta = $mimeGroups[$group];

                $contractMimes = $groupMeta['mimes'] ?? [];
                self::assertIsArray($contractMimes);
                $expected = $cell['mimes'];
                \sort($expected);
                \sort($contractMimes);
                self::assertSame($expected, $contractMimes, "{$op}.{$group} mimes drifted from the contract");

                self::assertSame(
                    $cell['availability'],
                    $this->resolvedAvailability($op, $group),
                    "{$op}.{$group} availability drifted from the contract",
                );
            }
        }
    }
}
