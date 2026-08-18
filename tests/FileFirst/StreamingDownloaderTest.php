<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\FileFirst;

use Gisl\Sdk\Errors\GislDownloadHttpError;
use Gisl\Sdk\Errors\GislNetworkError;
use Gisl\Sdk\Errors\GislRequestNotSentError;
use Gisl\Sdk\Errors\GislTransportError;
use Gisl\Sdk\Errors\GislSinkError;
use Gisl\Sdk\FileFirst\StreamingDownloader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FF2b — the streaming {@see StreamingDownloader}: copy a (pre-signed) URL to
 * a local path via fopen/stream_copy, chunk-by-chunk. A bad source URL throws
 * {@see GislNetworkError}; an unwritable destination throws {@see GislSinkError}.
 * The `file://` cases stay network-free; the HTTP-status cases spin up a
 * throwaway loopback `php -S` server (a `file://` source cannot produce an HTTP
 * status line, so the status-capture path can only be exercised over HTTP).
 * Mirrors the TS `http-downloader.test.ts`.
 */
final class StreamingDownloaderTest extends TestCase
{
    /** The loopback router: `/redirect` 302→`/final`, everything else → 404. */
    private const ROUTER_SCRIPT = <<<'PHP'
        <?php
        $path = \parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH);
        if ($path === '/redirect') {
            \header('Location: /final', true, 302);
            exit;
        }
        if ($path === '/unavailable') {
            // t2qCrjdr: a TRANSIENT non-2xx, so the same class reports
            // retryable=true here and false on /missing.
            \http_response_code(503);
            exit;
        }
        // `/final` and any other path answer 404 — this is the applicable
        // (final) failure status after a redirect chain is followed.
        \http_response_code(404);
        exit;
        PHP;

    /** @var resource|null The running `php -S` process (killed in tearDown). */
    private $serverProcess = null;

    private ?string $routerScript = null;

    protected function tearDown(): void
    {
        if (\is_resource($this->serverProcess)) {
            \proc_terminate($this->serverProcess);
            \proc_close($this->serverProcess);
            $this->serverProcess = null;
        }
        if ($this->routerScript !== null && \is_file($this->routerScript)) {
            @\unlink($this->routerScript);
            $this->routerScript = null;
        }
    }

    #[Test]
    public function streams_the_source_url_to_the_destination_path(): void
    {
        $source = \tempnam(\sys_get_temp_dir(), 'gisl_dl_src_');
        self::assertIsString($source);
        \file_put_contents($source, 'streamed-bytes');

        $dest = \tempnam(\sys_get_temp_dir(), 'gisl_dl_dst_');
        self::assertIsString($dest);

        try {
            (new StreamingDownloader())->downloadTo('file://' . $source, $dest);
            self::assertSame('streamed-bytes', \file_get_contents($dest));
        } finally {
            @\unlink($source);
            @\unlink($dest);
        }
    }

    #[Test]
    public function throws_network_error_when_the_source_cannot_be_opened(): void
    {
        $dest = \tempnam(\sys_get_temp_dir(), 'gisl_dl_dst_');
        self::assertIsString($dest);
        try {
            // A `file://` connect failure carries no HTTP status line, so the
            // implementation falls back to the connect-failure message.
            (new StreamingDownloader())->downloadTo('file:///no/such/source/file.bin', $dest);
            self::fail('expected GislNetworkError');
        } catch (GislNetworkError $e) {
            self::assertStringContainsString('Failed to open download source:', $e->getMessage());
        } finally {
            @\unlink($dest);
        }
    }

    #[Test]
    public function throws_sink_error_when_the_destination_cannot_be_opened(): void
    {
        $source = \tempnam(\sys_get_temp_dir(), 'gisl_dl_src_');
        self::assertIsString($source);
        \file_put_contents($source, 'bytes');

        try {
            // A destination inside a non-existent directory cannot be opened
            // for writing — the sink-side failure surfaces as GislSinkError
            // carrying the machine-readable reason 'write_failed'.
            (new StreamingDownloader())->downloadTo(
                'file://' . $source,
                \sys_get_temp_dir() . '/gisl-no-such-dir-' . \uniqid() . '/out.bin',
            );
            self::fail('expected GislSinkError');
        } catch (GislSinkError $e) {
            self::assertSame('write_failed', $e->getReason());
        } finally {
            @\unlink($source);
        }
    }

    #[Test]
    public function a_source_returning_http_404_throws_network_error_carrying_the_status(): void
    {
        // The status-capture path: a source that answers 4xx makes @fopen return
        // false while populating the last-response headers, so the thrown
        // GislNetworkError message must carry the numeric status (mirrors TS
        // http-downloader.ts:40 — status in the message, not a field).
        $base = $this->startLoopbackServer();

        $dest = \tempnam(\sys_get_temp_dir(), 'gisl_dl_dst_');
        self::assertIsString($dest);
        try {
            (new StreamingDownloader())->downloadTo("{$base}/missing", $dest);
            self::fail('expected GislNetworkError');
        } catch (GislNetworkError $e) {
            self::assertStringContainsString('404', $e->getMessage());
        } finally {
            @\unlink($dest);
        }
    }

    #[Test]
    public function a_redirect_chain_reports_the_final_status_not_the_intermediate_302(): void
    {
        // The HTTP wrapper follows the 302 to `/final` (which answers 404), so the
        // last-response headers carry BOTH a 302 and a 404 status line. The
        // implementation scans ALL lines and keeps the LAST — the applicable
        // failure status. A `[0]`-only parse would wrongly report 302, so this
        // asserts 404 IS present and 302 is NOT.
        $base = $this->startLoopbackServer();

        $dest = \tempnam(\sys_get_temp_dir(), 'gisl_dl_dst_');
        self::assertIsString($dest);
        try {
            (new StreamingDownloader())->downloadTo("{$base}/redirect", $dest);
            self::fail('expected GislNetworkError');
        } catch (GislNetworkError $e) {
            self::assertStringContainsString('404', $e->getMessage());
            self::assertStringNotContainsString('302', $e->getMessage());
        } finally {
            @\unlink($dest);
        }
    }

    #[Test]
    public function a_connect_failure_after_an_http_request_does_not_inherit_the_stale_status(): void
    {
        // wf133EDR (codex r2 issue 2) — pins the http_clear_last_response_headers()
        // guard (StreamingDownloader.php:24-26). An HTTP download populates the
        // process-global last-response headers; a SUBSEQUENT connect-failure
        // download (no HTTP response of its own) must NOT inherit that stale
        // status and mislabel a pure open failure as "Download failed with status
        // NNN". Removing the clear() call makes this test fail (order-independent:
        // the prime step runs first WITHIN this test).
        if (!\function_exists('http_clear_last_response_headers')) {
            self::markTestSkipped(
                'http_clear_last_response_headers() is unavailable (< PHP 8.5) — the '
                . '$http_response_header magic var is per-call, so there is no stale global to clear.',
            );
        }

        $base = $this->startLoopbackServer();

        // 1. Prime the process-global last-response headers with a 404 via a real
        //    HTTP download (it throws; we only need the header side effect).
        $primeDest = \tempnam(\sys_get_temp_dir(), 'gisl_dl_prime_');
        self::assertIsString($primeDest);
        try {
            (new StreamingDownloader())->downloadTo("{$base}/missing", $primeDest);
            self::fail('expected the priming HTTP download to fail with 404');
        } catch (GislNetworkError $primeError) {
            self::assertStringContainsString('404', $primeError->getMessage());
        } finally {
            @\unlink($primeDest);
        }

        // 2. A connect failure (no HTTP response) in the SAME process must clear
        //    the stale global and fall back to the connect-failure message — never
        //    reporting the earlier request's 404.
        $dest = \tempnam(\sys_get_temp_dir(), 'gisl_dl_dst_');
        self::assertIsString($dest);
        try {
            (new StreamingDownloader())->downloadTo('file:///no/such/connect/failure.bin', $dest);
            self::fail('expected GislNetworkError');
        } catch (GislNetworkError $e) {
            self::assertStringContainsString('Failed to open download source:', $e->getMessage());
            // The stale 404 must NOT leak into the connect-failure message.
            self::assertStringNotContainsString('404', $e->getMessage());
            self::assertDoesNotMatchRegularExpression('/status\s+\d{3}/', $e->getMessage());
        } finally {
            @\unlink($dest);
        }
    }

    /**
     * Launch a throwaway loopback `php -S` server on a free port and return its
     * base URL (`http://127.0.0.1:{port}`). Skips (never mocks — a mock would
     * bypass the header-reading magic under test) when the process/server
     * plumbing is unavailable, so the assertions above never run half-wired.
     */
    private function startLoopbackServer(): string
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open is unavailable — cannot launch a loopback php -S server to exercise the HTTP-status path.');
        }
        if (\PHP_BINARY === '') {
            self::markTestSkipped('PHP_BINARY is empty — cannot locate the php CLI to launch php -S.');
        }

        // Bind :0 to let the OS hand out a free port, read it, then release it.
        $probe = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            self::markTestSkipped("Could not bind a loopback port for the test server: {$errstr}");
        }
        $address = \stream_socket_get_name($probe, false);
        \fclose($probe);
        if (!\is_string($address)) {
            self::markTestSkipped('Could not read the bound loopback address.');
        }
        $colonPos = \strrpos($address, ':');
        if ($colonPos === false) {
            self::markTestSkipped('Could not parse the free loopback port.');
        }
        $port = (int) \substr($address, $colonPos + 1);

        // Write the router to a real .php file the server executes per request.
        $tmp = \tempnam(\sys_get_temp_dir(), 'gisl_router_');
        self::assertIsString($tmp);
        $routerScript = $tmp . '.php';
        \rename($tmp, $routerScript);
        \file_put_contents($routerScript, self::ROUTER_SCRIPT);
        $this->routerScript = $routerScript;

        // Discard the child's std streams to /dev/null so a full pipe buffer can
        // never wedge the server (the router bodies are empty anyway).
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $pipes = [];
        $process = @\proc_open(
            [\PHP_BINARY, '-S', "127.0.0.1:{$port}", $routerScript],
            $descriptors,
            $pipes,
        );
        if (!\is_resource($process)) {
            self::markTestSkipped('proc_open could not launch `php -S` — the HTTP-status path is not exercised here.');
        }
        $this->serverProcess = $process;

        // Wait for readiness: retry-connect for up to ~2s before asserting.
        $deadline = \microtime(true) + 2.0;
        while (\microtime(true) < $deadline) {
            $conn = @\fsockopen('127.0.0.1', $port, $connErrno, $connErrstr, 0.2);
            if ($conn !== false) {
                \fclose($conn);
                return "http://127.0.0.1:{$port}";
            }
            \usleep(50_000);
        }

        self::markTestSkipped('The loopback `php -S` server did not become ready within 2s.');
    }

    // -------------------------------------------------------------------------
    // t2qCrjdr — the split. The assertions above still pass BY INHERITANCE,
    // which is the point: nothing a consumer wrote against GislNetworkError
    // breaks. These pin the half that is new — which subclass, and what it says
    // about retrying.
    // -------------------------------------------------------------------------

    #[Test]
    public function a_non_2xx_download_raises_GislDownloadHttpError_carrying_the_status(): void
    {
        $base = $this->startLoopbackServer();

        $dest = \tempnam(\sys_get_temp_dir(), 'gisl_dl_dst_');
        self::assertIsString($dest);
        try {
            (new StreamingDownloader())->downloadTo("{$base}/missing", $dest);
            self::fail('expected GislDownloadHttpError');
        } catch (GislDownloadHttpError $e) {
            // The status is a FIELD now, so telling a permanent 404 from a
            // transient 503 no longer means parsing the message.
            self::assertSame(404, $e->status);
            // The honest answer, and the one the unsplit class could not give.
            self::assertFalse($e->retryable());
            // Still catchable the old way — no consumer breakage.
            self::assertInstanceOf(GislNetworkError::class, $e);
        } finally {
            @\unlink($dest);
        }
    }

    #[Test]
    public function a_503_download_is_retryable_while_a_404_is_not(): void
    {
        // Same class, opposite advice — retryability is DERIVED FROM THE STATUS
        // rather than fixed for the class. A single class-wide `retryable` is
        // exactly what made this undeclarable in the contracts taxonomy.
        $base = $this->startLoopbackServer();

        $dest = \tempnam(\sys_get_temp_dir(), 'gisl_dl_dst_');
        self::assertIsString($dest);
        try {
            (new StreamingDownloader())->downloadTo("{$base}/unavailable", $dest);
            self::fail('expected GislDownloadHttpError');
        } catch (GislDownloadHttpError $e) {
            self::assertSame(503, $e->status);
            self::assertTrue($e->retryable());
        } finally {
            @\unlink($dest);
        }
    }

    #[Test]
    public function an_unparseable_url_is_rejected_before_any_io_as_non_retryable(): void
    {
        // codex f46340e1d58a: a malformed URL fails DETERMINISTICALLY, so it
        // must not land in the always-retryable bucket with DNS and TLS.
        // @fopen returns the same false for both, so the check happens first.
        $dest = \tempnam(\sys_get_temp_dir(), 'gisl_dl_dst_');
        self::assertIsString($dest);
        try {
            (new StreamingDownloader())->downloadTo('not a url', $dest);
            self::fail('expected GislRequestNotSentError');
        } catch (GislRequestNotSentError $e) {
            self::assertFalse($e->retryable());
            self::assertInstanceOf(GislNetworkError::class, $e);
        } finally {
            @\unlink($dest);
        }
    }

    #[Test]
    public function a_file_url_is_still_accepted(): void
    {
        // Negative control for the guard above: `file://` has a scheme and no
        // host, and the parity fixtures rely on it. A host-based check would
        // have broken every one of them while this suite stayed green.
        $source = \tempnam(\sys_get_temp_dir(), 'gisl_dl_src_');
        self::assertIsString($source);
        \file_put_contents($source, 'BYTES');
        $dest = \tempnam(\sys_get_temp_dir(), 'gisl_dl_dst_');
        self::assertIsString($dest);

        try {
            (new StreamingDownloader())->downloadTo('file://' . $source, $dest);
            self::assertSame('BYTES', \file_get_contents($dest));
        } finally {
            @\unlink($source);
            @\unlink($dest);
        }
    }

    #[Test]
    public function an_unreachable_source_raises_GislTransportError(): void
    {
        // Port 1 on loopback answers nothing, so there is no HTTP status line
        // to capture — the server was never reached, which is transport and NOT
        // the HTTP half.
        $dest = \tempnam(\sys_get_temp_dir(), 'gisl_dl_dst_');
        self::assertIsString($dest);
        try {
            (new StreamingDownloader())->downloadTo('http://127.0.0.1:1/never-listening', $dest);
            self::fail('expected GislTransportError');
        } catch (GislTransportError $e) {
            self::assertNotInstanceOf(GislDownloadHttpError::class, $e);
            self::assertInstanceOf(GislNetworkError::class, $e);
        } finally {
            @\unlink($dest);
        }
    }
}
