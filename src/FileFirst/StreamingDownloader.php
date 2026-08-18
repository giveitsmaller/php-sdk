<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

use Gisl\Sdk\Errors\GislDownloadHttpError;
use Gisl\Sdk\Errors\GislRequestNotSentError;
use Gisl\Sdk\Errors\GislSinkError;
use Gisl\Sdk\Errors\GislTransportError;

/**
 * Streaming {@see Downloader} implementation.
 *
 * Copies a (typically pre-signed) URL to a local path without buffering the
 * whole body in memory. Pre-signed download URLs require no SDK auth, so this
 * opens the source URL directly via a stream and copies it chunk-by-chunk.
 */
final class StreamingDownloader implements Downloader
{
    public function downloadTo(string $url, string $destPath): void
    {
        // Clear any process-global HTTP headers stored by a prior HTTP-wrapper
        // call, so a connect-failure attempt below (no HTTP response this call)
        // cannot read a stale status from an earlier, unrelated request.
        if (\function_exists('http_clear_last_response_headers')) {
            \http_clear_last_response_headers();
        }

        // codex f46340e1d58a: a malformed URL fails DETERMINISTICALLY, so it
        // must not land in the always-retryable bucket with DNS and TLS.
        // @fopen returns the same `false` for both, so the only way to tell
        // them apart is to check BEFORE the call. `file://` is legitimate here
        // (the parity fixtures use it) and has no host, so require a SCHEME
        // rather than a host.
        if (\parse_url($url, PHP_URL_SCHEME) === null) {
            throw new GislRequestNotSentError('Download source is not a valid URL: ' . $url);
        }

        $in = @fopen($url, 'rb');
        if ($in === false) {
            // Read the last response header lines 8.5-deprecation-safe: prefer
            // the non-deprecated http_get_last_response_headers() (PHP 8.5+),
            // fall back to the $http_response_header magic var (8.1-8.4). Both
            // return array<int,string> of header lines, so parsing is identical.
            // The ternary's false branch is never evaluated on PHP 8.5 (where
            // the function exists), so the deprecated magic var is not read
            // there; the `?? null` guards an undefined var on 8.1-8.4 connect
            // failures. (PHPStan models $http_response_header as always-defined,
            // so it flags the `?? null` as redundant — a false positive vs the
            // real per-call runtime nullability; L8 here is not CI-gated.)
            $headers = \function_exists('http_get_last_response_headers')
                ? \http_get_last_response_headers()
                : ($http_response_header ?? null);

            // Redirect-safe: a redirect chain emits multiple `HTTP/...` status
            // lines; scan ALL of them and keep the LAST (the applicable failure
            // status), rather than reading only index [0]. Null-safe against a
            // missing/malformed header array.
            $status = null;
            if (\is_array($headers)) {
                foreach ($headers as $line) {
                    if (\preg_match('#^HTTP/\S+\s+(\d{3})\b#', $line, $matches) === 1) {
                        $status = $matches[1];
                    }
                }
            }

            // A captured status mirrors TS http-downloader.ts. t2qCrjdr: the
            // status is now carried as a FIELD as well as in the message, so a
            // consumer telling a permanent 404 from a transient 503 never has to
            // parse the string. No status line (pure DNS/refused connect failure,
            // or a non-HTTP wrapper) → the server was never reached, which is
            // transport (mirrors TS's branch).
            if ($status !== null) {
                throw new GislDownloadHttpError(
                    "Download failed with status {$status}",
                    (int) $status,
                );
            }

            throw new GislTransportError('Failed to open download source: ' . $url);
        }

        $out = @fopen($destPath, 'wb');
        if ($out === false) {
            fclose($in);

            throw new GislSinkError(
                'Failed to open destination for writing: ' . $destPath,
                reason: 'write_failed',
            );
        }

        try {
            if (stream_copy_to_stream($in, $out) === false) {
                throw new GislSinkError(
                    'Failed to stream download to destination: ' . $destPath,
                    reason: 'write_failed',
                );
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }
}
