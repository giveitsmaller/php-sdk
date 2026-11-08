<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Ergonomic;

use Gisl\Sdk\Ergonomic\OperationBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * 0Vcogefw — the LOSSLESS-audio classifiers used by the compress preset
 * resolver to drop the shipped-preset bitrate on flac/wav. PHP analogue of
 * the TS `_detectAudioLossless` unit block (`builder.test.ts`):
 *   - {@see OperationBuilder::detectAudioLossless()} — filename extension.
 *   - {@see OperationBuilder::detectAudioLosslessFromMime()} — 5-mime set.
 */
#[CoversClass(OperationBuilder::class)]
final class OperationBuilderAudioLosslessTest extends TestCase
{
    public function testDetectAudioLosslessClassifiesByFilenameExtension(): void
    {
        // label => [filename, expected]
        $cases = [
            'song.flac' => ['song.flac', true],
            'a.wav' => ['a.wav', true],
            'uppercase path' => ['PATH/X.FLAC', true],
            'mixed case wav' => ['audio/clip.WAV', true],
            'mp3' => ['song.mp3', false],
            'aac' => ['a.aac', false],
            'm4a' => ['b.m4a', false],
            'ogg' => ['c.ogg', false],
            'oga' => ['d.oga', false],
            'opus' => ['e.opus', false],
            'unknown ext' => ['f.unknownext', false],
            'no extension' => ['noextension', false],
        ];

        self::assertNotSame([], $cases);

        $failures = [];
        foreach ($cases as $label => [$input, $expected]) {
            try {
                $actual = OperationBuilder::detectAudioLossless($input);
                if ($actual !== $expected) {
                    $failures[] = "{$label}: detectAudioLossless('{$input}') returned " . \var_export($actual, true);
                }
            } catch (\Throwable $e) {
                $failures[] = "{$label}: threw " . $e::class . ": {$e->getMessage()}";
            }
        }

        $this->assertSame([], $failures, \implode("\n", $failures));
    }

    public function testDetectAudioLosslessFromMimeClassifiesTheLosslessSet(): void
    {
        // label => [mime, expected]
        $cases = [
            'audio/flac' => ['audio/flac', true],
            'audio/x-flac' => ['audio/x-flac', true],
            'audio/wav' => ['audio/wav', true],
            'audio/x-wav' => ['audio/x-wav', true],
            'audio/wave' => ['audio/wave', true],
            // MIME parameters must be stripped before the exact-set lookup
            // (codex 18b6b684) — mirrors the TS Blob parameterised-MIME case.
            'audio/flac; codecs=flac' => ['audio/flac; codecs=flac', true],
            // The lookup is case-insensitive.
            'AUDIO/FLAC' => ['AUDIO/FLAC', true],
            'audio/mpeg' => ['audio/mpeg', false],
            'audio/ogg' => ['audio/ogg', false],
            'image/png' => ['image/png', false],
        ];

        self::assertNotSame([], $cases);

        $failures = [];
        foreach ($cases as $label => [$mime, $expected]) {
            try {
                $actual = OperationBuilder::detectAudioLosslessFromMime($mime);
                if ($actual !== $expected) {
                    $failures[] = "{$label}: detectAudioLosslessFromMime('{$mime}') returned " . \var_export($actual, true);
                }
            } catch (\Throwable $e) {
                $failures[] = "{$label}: threw " . $e::class . ": {$e->getMessage()}";
            }
        }

        $this->assertSame([], $failures, \implode("\n", $failures));
    }
}
