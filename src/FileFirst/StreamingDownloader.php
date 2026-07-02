<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

use Gisl\Sdk\Errors\GislNetworkError;
use Gisl\Sdk\Errors\GislSinkError;

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

            // A captured status mirrors TS http-downloader.ts:40 (status in the
            // message only — GislNetworkError has no status field). No status
            // line (pure DNS/refused connect failure, or a non-HTTP wrapper) →
            // keep the existing connect-failure message (mirrors TS's branch).
            if ($status !== null) {
                throw new GislNetworkError("Download failed with status {$status}");
            }

            throw new GislNetworkError('Failed to open download source: ' . $url);
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
