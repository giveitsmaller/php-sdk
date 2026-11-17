<?php

/**
 * CODE GENERATED — DO NOT EDIT.
 * Source: compression_contracts operation-capabilities/operation-capabilities.json
 * (compress.audio.bitrate.lossless_output) and schemas/operations/compress.yaml (mime_groups.audio.mimes).
 * Regenerate with: scripts/generate.py.
 */

declare(strict_types=1);

namespace Gisl\Sdk\Generated\Capabilities;

/**
 * The compress-audio bitrate rule as input-MIME sets. Mirrors the TS
 * packages/typescript/src/generated/capabilities/compress_audio_bitrate.ts.
 *
 * @internal
 */
final class CompressAudioBitrate
{
    /** Compress audio input MIMEs on which the contract refuses `bitrate` (lossless). */
    public const REFUSED_MIMES = ['audio/flac', 'audio/wav'];

    /** Compress audio input MIMEs on which the contract accepts `bitrate` (lossy). */
    public const ACCEPTED_MIMES = ['audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/ogg'];

    /** @codeCoverageIgnore */
    private function __construct()
    {
    }
}
