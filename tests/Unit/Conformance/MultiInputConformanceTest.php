<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Conformance;

use Gisl\Generated\Operations\CompressMetadata;
use Gisl\Sdk\GislErgonomicClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Multi-input conformance guard (7vD8RGlI). PHP arm of the TS
 * `multi-input-conformance.test.ts`.
 *
 * {@see GislErgonomicClient::operation()} is single-input, and its docblock
 * names the builder for each multi-input op and says the rest have none because
 * the contract marks them `planned`. This suite pins that claim to the shipped
 * `operation-capabilities.json`: every op the contract marks `input.model:
 * multi` is either one with a builder here, or `planned`.
 *
 * When this goes red, that is the good news: an op was re-listed (or a new
 * multi-input op was added) and needs a builder in BOTH SDKs. Write it, then add
 * it to HAS_BUILDER — never the other way round.
 */
#[CoversClass(GislErgonomicClient::class)]
final class MultiInputConformanceTest extends TestCase
{
    /** Multi-input ops with a dedicated builder: merge(), files()->archive(), file()->watermark(). */
    private const HAS_BUILDER = ['archive', 'image_watermark', 'merge', 'video_watermark'];

    public function test_every_multi_input_op_without_a_builder_is_planned(): void
    {
        $opFile = (new \ReflectionClass(CompressMetadata::class))->getFileName();
        $root = \dirname((string) $opFile, 3);
        $path = $root . '/operation-capabilities/operation-capabilities.json';
        $json = \json_decode((string) \file_get_contents($path), true);
        self::assertIsArray($json, 'operation-capabilities.json must decode to an array');
        self::assertIsArray($json['operations'] ?? null);

        $builderless = [];
        foreach ($json['operations'] as $op => $caps) {
            if (!\is_array($caps) || ($caps['input']['model'] ?? null) !== 'multi') {
                continue;
            }
            if (!\in_array($op, self::HAS_BUILDER, true)) {
                $builderless[(string) $op] = $caps['availability'] ?? null;
            }
        }

        // A positive control: the three ops the docblock names must be found, or
        // this suite is reading the wrong file and would pass on nothing.
        \ksort($builderless);
        self::assertSame(
            ['audio_overlay', 'audio_to_video', 'custom_luma'],
            \array_keys($builderless),
            'the multi-input ops without a PHP builder changed; update the operation() docblock',
        );
        foreach ($builderless as $op => $availability) {
            self::assertSame('planned', $availability, "{$op} is no longer planned: it needs a builder in both SDKs (7vD8RGlI)");
        }
    }

    public function test_every_op_with_a_builder_is_multi_input_in_the_contract(): void
    {
        $opFile = (new \ReflectionClass(CompressMetadata::class))->getFileName();
        $root = \dirname((string) $opFile, 3);
        $json = \json_decode((string) \file_get_contents($root . '/operation-capabilities/operation-capabilities.json'), true);
        self::assertIsArray($json);
        self::assertIsArray($json['operations'] ?? null);

        foreach (self::HAS_BUILDER as $op) {
            self::assertSame('multi', $json['operations'][$op]['input']['model'] ?? null, "{$op} is not multi-input in the contract");
        }
    }
}
