<?php

declare(strict_types=1);

namespace Gisl\Sdk\Http;

use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislError;

/**
 * A uniform byte-range source for the upload path — either a filesystem path
 * or an open, **seekable** stream resource (the PHP analogue of a JS `Blob` /
 * in-memory input, VOxtu0RZ-B4).
 *
 * The whole upload engine (single-shot, multipart initiate + chunked PUTs)
 * reads bytes through this seam so it does not care whether the source is a
 * path or a stream. A path source opens a FRESH handle per range read, so it
 * is safe for the concurrent multipart uploader; a stream source seeks the one
 * caller-provided handle, so it is NOT concurrency-safe and the engine forces
 * sequential multipart for it ({@see isConcurrencySafe()}).
 *
 * **Seekable-only.** Non-seekable streams (`php://stdin`, network pipes) are
 * rejected at construction with an actionable error: they have no random
 * access for chunk PUTs and no reliable size. Buffering one is OPT-IN only
 * ({@see bufferNonSeekable()}, KS04SnqR), so the SDK never makes a disk copy of
 * potentially-large input the caller did not ask for.
 *
 * The caller owns the stream's lifecycle — this class never closes a
 * caller-provided resource.
 */
final class UploadSource
{
    /**
     * @param resource|null $stream
     */
    private function __construct(
        private readonly ?string $path,
        private readonly mixed $stream,
        private readonly int $size,
        private readonly string $name,
    ) {
    }

    public static function fromPath(string $path): self
    {
        if (!\is_file($path) || !\is_readable($path)) {
            throw new GislConfigError("File not found or not readable: {$path}");
        }
        $size = \filesize($path);
        if ($size === false) {
            throw new GislConfigError("Unable to stat file: {$path}");
        }
        return new self($path, null, $size, \basename($path));
    }

    /**
     * Validate that `$resource` is an open, seekable, READABLE stream — the same
     * guard {@see fromStream()} applies, exposed so multi-input builders can
     * preflight EVERY resource input before uploading ANY of them (so an invalid
     * later input never burns an earlier upload).
     */
    public static function assertUploadableStream(mixed $resource): void
    {
        if (!\is_resource($resource)) {
            throw new GislConfigError(
                'uploadFile expected a string filesystem path or a stream resource; got '
                . \get_debug_type($resource) . '.',
            );
        }
        $meta = \stream_get_meta_data($resource);
        if ($meta['seekable'] !== true) {
            throw new GislConfigError(
                'uploadFile received a non-seekable stream (e.g. php://stdin or a pipe). '
                . 'Stream uploads require random access for chunked PUTs — buffer the data to a '
                . 'temp file and pass its path, or pass a seekable stream (e.g. php://temp).',
                reason: 'non_seekable_stream',
            );
        }
        // A write-only handle (e.g. fopen(..., 'wb')) is seekable but cannot be
        // read for the upload body — reject it up front rather than failing mid-
        // upload (codex VOxtu0RZ-B4 r2).
        if (\strpbrk($meta['mode'], 'r+') === false) {
            throw new GislConfigError(
                "uploadFile received a non-readable stream (mode '{$meta['mode']}'). "
                . 'Open the stream for reading (e.g. r+b) — a write-only handle has no bytes to upload.',
                reason: 'non_readable_stream',
            );
        }
    }

    /** Bytes a buffered copy keeps in memory before php://temp spills to a temp file. */
    private const BUFFER_MEMORY_BYTES = 2 * 1024 * 1024;

    /**
     * Opt-in buffering of a NON-seekable stream (`php://stdin`, a pipe) into a
     * seekable `php://temp` copy (KS04SnqR, "Option A"). A seekable stream is
     * returned unchanged. The WHOLE input is read now: past 2 MiB PHP spills it
     * to a temp file, which PHP deletes when the returned handle is closed or
     * garbage-collected, so no temp file outlives it. Disk use equal to the
     * input is the cost the caller opts into.
     *
     * @param resource $resource An open, readable stream.
     * @return resource A seekable stream positioned at the start.
     */
    public static function bufferNonSeekable(mixed $resource): mixed
    {
        if (!\is_resource($resource)) {
            throw new GislConfigError(
                'bufferNonSeekable expected an open stream resource; got ' . \get_debug_type($resource) . '.',
            );
        }
        $meta = \stream_get_meta_data($resource);
        if ($meta['seekable'] === true) {
            return $resource;
        }
        if (\strpbrk($meta['mode'], 'r+') === false) {
            throw new GislConfigError(
                "bufferNonSeekable received a non-readable stream (mode '{$meta['mode']}').",
                reason: 'non_readable_stream',
            );
        }
        $copy = \fopen('php://temp/maxmemory:' . self::BUFFER_MEMORY_BYTES, 'w+b');
        if ($copy === false) {
            throw new GislConfigError('Unable to open a php://temp buffer for the non-seekable stream.');
        }
        if (\stream_copy_to_stream($resource, $copy) === false || !\rewind($copy)) {
            \fclose($copy);
            throw new GislConfigError('Unable to buffer the non-seekable stream (copy to php://temp failed).');
        }
        return $copy;
    }

    /**
     * @param resource $stream An open, seekable stream resource.
     */
    public static function fromStream(mixed $stream): self
    {
        self::assertUploadableStream($stream);
        $meta = \stream_get_meta_data($stream);

        // Size a seekable stream by seeking to the end (fstat size is unreliable
        // for php://temp/memory). Restore the cursor to the start afterwards.
        if (\fseek($stream, 0, SEEK_END) !== 0) {
            throw new GislConfigError('Unable to seek the upload stream to determine its size.');
        }
        $size = \ftell($stream);
        if ($size === false) {
            throw new GislConfigError('Unable to determine the upload stream size (ftell failed).');
        }
        if (\fseek($stream, 0) !== 0) {
            throw new GislConfigError('Unable to rewind the upload stream after sizing.');
        }

        // Derive a filename from the stream's backing URI when it is a real
        // path; otherwise a neutral default (php://temp/memory have no name).
        $uri = $meta['uri'] ?? '';
        $name = ($uri !== '' && !\str_starts_with($uri, 'php://'))
            ? \basename($uri)
            : 'upload.bin';

        return new self(null, $stream, $size, $name);
    }

    public function size(): int
    {
        return $this->size;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * A path source reopens per read (concurrency-safe); a single stream handle
     * has one cursor, so the multipart engine must read it sequentially.
     */
    public function isConcurrencySafe(): bool
    {
        return $this->path !== null;
    }

    /**
     * Filesystem path — only valid for a path source (callers gate on
     * {@see isConcurrencySafe()} before reaching the concurrent uploader).
     */
    public function path(): string
    {
        if ($this->path === null) {
            throw new GislError('UploadSource::path() called on a stream source.');
        }
        return $this->path;
    }

    /**
     * Read exactly `$length` bytes starting at `$offset`. A path source opens a
     * fresh handle; a stream source seeks the held handle. Throws on a short
     * read before EOF (a wire/plan bug), mirroring the prior `readChunk`.
     */
    public function readRange(int $offset, int $length): string
    {
        if ($length < 1) {
            throw new GislError("readRange called with non-positive length ({$length}) at offset {$offset}.");
        }

        if ($this->path !== null) {
            $fh = @\fopen($this->path, 'rb');
            if ($fh === false) {
                throw new GislError("Unable to reopen {$this->path} for chunk read.");
            }
            try {
                return $this->readRangeFromHandle($fh, $offset, $length);
            } finally {
                \fclose($fh);
            }
        }

        \assert(\is_resource($this->stream));
        return $this->readRangeFromHandle($this->stream, $offset, $length);
    }

    /**
     * Read the entire source into memory (single-shot upload path).
     */
    public function readAll(): string
    {
        if ($this->path !== null) {
            $contents = \file_get_contents($this->path);
            if ($contents === false) {
                throw new GislError("Unable to read file: {$this->path}");
            }
            return $contents;
        }

        \assert(\is_resource($this->stream));
        if (\fseek($this->stream, 0) !== 0) {
            throw new GislError('Unable to rewind the upload stream.');
        }
        $contents = \stream_get_contents($this->stream);
        if ($contents === false) {
            throw new GislError('Unable to read the upload stream.');
        }
        return $contents;
    }

    /**
     * @param resource $fh
     */
    private function readRangeFromHandle(mixed $fh, int $offset, int $length): string
    {
        $label = $this->path ?? 'stream';
        if (\fseek($fh, $offset) !== 0) {
            throw new GislError("Failed to seek to offset {$offset} in {$label}.");
        }
        // fread can return short on streams even when more bytes remain — loop
        // until $length bytes are read or EOF is hit.
        $buffer = '';
        $remaining = $length;
        while ($remaining > 0) {
            $part = \fread($fh, $remaining);
            if ($part === false || $part === '') {
                break;
            }
            $buffer .= $part;
            $remaining -= \strlen($part);
        }
        if (\strlen($buffer) !== $length) {
            throw new GislError(
                "Short read at offset {$offset} in {$label}: expected {$length} bytes, got "
                . \strlen($buffer) . '.',
            );
        }
        return $buffer;
    }
}
