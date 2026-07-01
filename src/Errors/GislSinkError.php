<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * Thrown by the file-first {@see \Gisl\Sdk\FileFirst\RunResult} sinks
 * (`toFile()` / `downloadTo()`) when they cannot deliver. The machine
 * readable `$reason` discriminates the six cases below, mirroring the
 * `reason`-bag convention on {@see GislConfigError}:
 *
 *  - `not_single_output`     — `toFile()` requires exactly one output but
 *                              the run produced zero or more than one.
 *  - `downloader_unavailable`— the `RunResult` has no downloader bound
 *                              (e.g. a browser / no-I/O context). Producers
 *                              inject one; constructed-by-hand results do not.
 *  - `partial_failure`       — `downloadTo(failOnPartial: true)` and the run
 *                              had at least one failed input.
 *  - `duplicate_filename`    — two outputs share a destination filename in one
 *                              `downloadTo($dir)`, which would silently overwrite.
 *  - `invalid_directory`     — `downloadTo('')` — empty target directory.
 *  - `write_failed`          — a concrete {@see \Gisl\Sdk\FileFirst\Downloader}
 *                              could not open or stream to the destination path.
 *
 * Mirrors the TS `GislSinkError` in `packages/typescript/src/errors.ts`.
 */
final class GislSinkError extends GislError
{
    public function __construct(
        string $message,
        public readonly string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Machine-readable cause: `not_single_output` | `downloader_unavailable`
     * | `partial_failure` | `duplicate_filename` | `invalid_directory` |
     * `write_failed` (see the class docblock for what each means).
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
