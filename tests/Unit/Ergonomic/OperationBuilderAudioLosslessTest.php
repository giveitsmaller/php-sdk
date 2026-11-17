<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Ergonomic;

use Gisl\Sdk\Ergonomic\OperationBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * 0Vcogefw / gK4TWEiZ — the audio-format classifiers used by the compress
 * preset resolver to drop the shipped-preset bitrate on lossless (flac/wav)
 * and uncertain audio (D569). PHP analogue of the TS `_classifyAudioFormat`
 * unit block (`builder.test.ts`):
 *   - {@see OperationBuilder::classifyAudioFormat()} — filename extension.
 *   - {@see OperationBuilder::classifyAudioFormatFromMime()} — lossless and
 *     lossy MIME sets; null defers to the filename.
 */
#[CoversClass(OperationBuilder::class)]
final class OperationBuilderAudioLosslessTest extends TestCase
{
    public function testClassifyAudioFormatByFilenameExtension(): void
    {
        // label => [filename, expected]
        $cases = [
            'song.flac' => ['song.flac', 'lossless'],
            'a.wav' => ['a.wav', 'lossless'],
            'uppercase path' => ['PATH/X.FLAC', 'lossless'],
            'mixed case wav' => ['audio/clip.WAV', 'lossless'],
            'mp3' => ['song.mp3', 'lossy'],
            'aac' => ['a.aac', 'lossy'],
            'm4a' => ['b.m4a', 'lossy'],
            'ogg' => ['c.ogg', 'lossy'],
            'oga' => ['d.oga', 'lossy'],
            'opus' => ['e.opus', 'lossy'],
            'unknown ext' => ['f.unknownext', 'uncertain'],
            'no extension' => ['noextension', 'uncertain'],
        ];

        self::assertNotSame([], $cases);

        $failures = [];
        foreach ($cases as $label => [$input, $expected]) {
            try {
                $actual = OperationBuilder::classifyAudioFormat($input);
                if ($actual !== $expected) {
                    $failures[] = "{$label}: classifyAudioFormat('{$input}') returned " . \var_export($actual, true);
                }
            } catch (\Throwable $e) {
                $failures[] = "{$label}: threw " . $e::class . ": {$e->getMessage()}";
            }
        }

        $this->assertSame([], $failures, \implode("\n", $failures));
    }

    public function testClassifyAudioFormatFromMime(): void
    {
        // label => [mime, expected]
        $cases = [
            'audio/flac' => ['audio/flac', 'lossless'],
            'audio/x-flac' => ['audio/x-flac', 'lossless'],
            'audio/wav' => ['audio/wav', 'lossless'],
            'audio/x-wav' => ['audio/x-wav', 'lossless'],
            'audio/wave' => ['audio/wave', 'lossless'],
            // MIME parameters must be stripped before the exact-set lookup
            // (codex 18b6b684) — mirrors the TS Blob parameterised-MIME case.
            'audio/flac; codecs=flac' => ['audio/flac; codecs=flac', 'lossless'],
            // The lookup is case-insensitive.
            'AUDIO/FLAC' => ['AUDIO/FLAC', 'lossless'],
            'audio/mpeg' => ['audio/mpeg', 'lossy'],
            'audio/aac' => ['audio/aac', 'lossy'],
            'audio/ogg' => ['audio/ogg', 'lossy'],
            'audio/mp4' => ['audio/mp4', 'lossy'],
            'audio/opus' => ['audio/opus', 'lossy'],
            // An unrecognised audio MIME says nothing about the codec (D569).
            'audio/x-aiff' => ['audio/x-aiff', null],
            'image/png' => ['image/png', null],
        ];

        self::assertNotSame([], $cases);

        $failures = [];
        foreach ($cases as $label => [$mime, $expected]) {
            try {
                $actual = OperationBuilder::classifyAudioFormatFromMime($mime);
                if ($actual !== $expected) {
                    $failures[] = "{$label}: classifyAudioFormatFromMime('{$mime}') returned " . \var_export($actual, true);
                }
            } catch (\Throwable $e) {
                $failures[] = "{$label}: threw " . $e::class . ": {$e->getMessage()}";
            }
        }

        $this->assertSame([], $failures, \implode("\n", $failures));
    }
}
