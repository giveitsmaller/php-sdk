<?php

declare(strict_types=1);

namespace Gisl\Sdk;

use Gisl\Generated\OpenApi\Model\MultipartInitiateRequestMetadataHint;
use Gisl\Sdk\Errors\GislConfigError;

/**
 * Per-call options for {@see GislClient::uploadFile()}.
 *
 * Mirrors the TS `UploadOptions` interface at packages/typescript/src/types.ts:392-410.
 * `signal` is intentionally absent here — PHP's PSR-18 / PSR-7 surface has no
 * `AbortSignal` analogue. Cooperative cancellation lives at the ergonomic
 * layer ({@see \Gisl\Sdk\Cancellation} via `RunOptions`/`SubmitOptions`,
 * VOxtu0RZ-B3), which checks between steps; an in-flight transfer is not
 * interrupted mid-request (transfer-level abort is a possible follow-up).
 */
final class UploadOptions
{
    /**
     * @param (callable(int $uploadedBytes, int $totalBytes): void)|null $onProgress
     *   Fired after each chunk completes during multipart uploads, and once
     *   at end of single-shot uploads. The callback receives the running
     *   uploaded byte count and total file size; both monotonically non-
     *   decreasing per call.
     * @param string|null $resumeUploadId
     *   SDK-3 (Wb6ebOMM): resume an in-progress multipart upload. When set,
     *   `uploadFile()` skips `/multipart/initiate` and instead walks
     *   `/status` -> presigns missing parts -> PUTs missing -> `/complete`.
     *   The caller's `$filePathOrResource` MUST be byte-identical to the
     *   file used in the original initiate call; mismatched bytes will
     *   produce S3 etags that don't match server state and `/complete`
     *   will reject.
     *
     *   Anonymous-initiated sessions cannot be resumed by an authed caller
     *   (server returns 403 -> `GislMultipartSessionAuthRequiredError`).
     *   Non-existent / expired sessions return 404 ->
     *   `GislMultipartSessionNotFoundError`. Authed-but-non-owning callers
     *   return 403 -> `GislMultipartSessionOwnershipError`.
     * @param (callable(MultipartCheckpointState $state): void)|null $onCheckpoint
     *   SDK-3 (Wb6ebOMM): called after every successful part PUT in
     *   fresh-upload AND resume paths. Receives a JSON-serialisable
     *   snapshot of upload state — persistable via `json_encode($state)`
     *   for round-trip across process restarts, so a future
     *   `uploadFile(filePath, new UploadOptions(resumeUploadId: $state->uploadId))`
     *   can pick up where the prior process stopped.
     *
     *   Callback fires OUTSIDE the per-part retry-scoped path: a throw here
     *   will fail the upload but NEVER trigger a duplicate PUT (mirrors the
     *   `onProgress` discipline).
     * @param string|null $contentType
     *   Caller-overridable Content-Type for the multipart `file` part. When
     *   null the SDK sends `application/octet-stream` (RFC 7578 §4.4
     *   unknown-binary fallback). Applies to both the single-shot upload and
     *   the multipart `/initiate` first-chunk part.
     * @param string|null $filename
     *   Caller-overridable filename for the multipart `file` part (fFwaKsN5).
     *   When null the SDK derives it from the source (a path's basename, or
     *   `upload.bin` for a nameless stream). Lets a file-first resource input
     *   carry its name hint through to the upload.
     * @param bool $bufferNonSeekable
     *   KS04SnqR: accept a NON-seekable stream (`php://stdin`, a pipe) by first
     *   copying it into a seekable php://temp buffer, which is closed after the
     *   upload, success or failure. Default false: a non-seekable stream is
     *   rejected (`non_seekable_stream`), so a large pipe never spools to disk
     *   unasked. Opting in costs disk equal to the input.
     */
    public function __construct(
        public mixed $onProgress = null,
        public ?MultipartInitiateRequestMetadataHint $metadataHint = null,
        public ?string $resumeUploadId = null,
        public mixed $onCheckpoint = null,
        public ?string $contentType = null,
        public ?string $filename = null,
        public bool $bufferNonSeekable = false,
    ) {
        // Validate the wire-bound hints at construction (fail-fast). The same
        // check is the pre-upload chokepoint for file-first resource inputs
        // (Recipe/FilesRecipe/MergedRecipe/ArchivedRecipe call it BEFORE any
        // upload so a bad hint on a later fan-out input can't waste earlier
        // uploads), and the authoritative wire-assembly guards in GislClient's
        // body builders re-check the actual value (bypass-proof vs. mutation).
        self::assertHintsValid($contentType, $filename);
    }

    /**
     * Validate the multipart `contentType` / `filename` hints (fFwaKsN5). Both
     * are concatenated into raw multipart header bytes, so reject CR/LF/NUL
     * (header/body injection); the filename additionally must be a BARE name —
     * the upload contract's `^[^/\\]+$`, max 255 bytes — so reject path
     * separators + over-length. An empty filename is the "no override" sentinel
     * (uploadFile falls back to the source name), so it is not rejected.
     *
     * @internal Shared by the ctor + the file-first pre-upload preflights.
     */
    public static function assertHintsValid(?string $contentType, ?string $filename): void
    {
        if ($contentType !== null && \preg_match('/[\r\n\x00]/', $contentType) === 1) {
            throw new GislConfigError(
                'UploadOptions contentType contains illegal characters for a multipart '
                . 'Content-Type header (no CR, LF, or NUL allowed): '
                . \var_export($contentType, true),
            );
        }
        // Reject `"` too (codex r3): the filename is written as a quoted-string
        // in the multipart Content-Disposition header, so a `"` breaks it. This
        // keeps the preflight in lock-step with the authoritative wire guard
        // GislClient::assertFilenameHeaderSafe() — a `"`-bearing name must NOT
        // pass the fan-out preflight only to fail at body assembly after earlier
        // inputs have already uploaded.
        if ($filename !== null && \preg_match('/["\r\n\x00]/', $filename) === 1) {
            throw new GislConfigError(
                'UploadOptions filename contains illegal characters for a multipart '
                . 'Content-Disposition header (no `"`, CR, LF, or NUL allowed): '
                . \var_export($filename, true),
            );
        }
        if ($filename !== null && $filename !== ''
            && (\str_contains($filename, '/') || \str_contains($filename, '\\') || \strlen($filename) > 255)
        ) {
            throw new GislConfigError(
                'UploadOptions filename must be a bare filename with no path separators '
                . "('/' or '\\') and at most 255 bytes: " . \var_export($filename, true),
            );
        }
    }
}
