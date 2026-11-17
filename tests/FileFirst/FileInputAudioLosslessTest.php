<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\FileFirst;

use Gisl\Sdk\FileFirst\FileInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 0Vcogefw — {@see FileInput::compressAudioLosslessHint()}. Derives the
 * lossless-audio classification from the same hints the resolver uses for
 * media detection: the filename extension (path inputs) and the
 * `contentType`/`filename` hints (resource inputs, MIME-first). Mirrors the
 * TS `_classifyAudioFormat` Blob/path branches.
 */
#[CoversClass(FileInput::class)]
final class FileInputAudioLosslessTest extends TestCase
{
    #[Test]
    public function path_with_flac_extension_is_lossless(): void
    {
        self::assertTrue(FileInput::path('track.flac')->compressAudioLosslessHint());
    }

    #[Test]
    public function upload_id_input_has_no_inferable_signal(): void
    {
        self::assertFalse(FileInput::uploadId('uploaded-123')->compressAudioLosslessHint());
    }

    #[Test]
    public function resource_prefers_a_lossless_content_type(): void
    {
        $stream = \fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        try {
            // MIME-first: an audio/wav contentType wins over a .mp3 filename.
            $input = FileInput::resource($stream, filename: 'track.mp3', contentType: 'audio/wav');
            self::assertTrue($input->compressAudioLosslessHint());
        } finally {
            \fclose($stream);
        }
    }

    #[Test]
    public function resource_lossy_content_type_is_not_lossless(): void
    {
        $stream = \fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        try {
            $input = FileInput::resource($stream, filename: 'x.flac', contentType: 'audio/mpeg');
            // contentType is an audio/* MIME → it is authoritative and lossy,
            // the .flac filename is NOT consulted.
            self::assertFalse($input->compressAudioLosslessHint());
        } finally {
            \fclose($stream);
        }
    }

    #[Test]
    public function resource_falls_back_to_filename_when_content_type_is_absent(): void
    {
        $stream = \fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        try {
            $input = FileInput::resource($stream, filename: 'clip.flac');
            self::assertTrue($input->compressAudioLosslessHint());
        } finally {
            \fclose($stream);
        }
    }

    #[Test]
    public function resource_with_an_unrecognised_audio_content_type_defers_to_the_filename(): void
    {
        // gK4TWEiZ: an audio contentType outside both MIME sets says nothing about
        // the codec, so the filename decides; with no filename it is uncertain.
        $stream = \fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        try {
            $named = FileInput::resource($stream, filename: 'song.m4a', contentType: 'audio/x-m4a');
            self::assertSame('lossy', $named->compressAudioFormatHint());
            self::assertSame('uncertain', FileInput::resource($stream, contentType: 'audio/x-m4a')->compressAudioFormatHint());
        } finally {
            \fclose($stream);
        }
    }

    #[Test]
    public function hintless_resource_is_not_lossless(): void
    {
        $stream = \fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        try {
            self::assertFalse(FileInput::resource($stream)->compressAudioLosslessHint());
        } finally {
            \fclose($stream);
        }
    }
}
