<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Errors;

use Gisl\Sdk\Errors\GislDownloadHttpError;
use Gisl\Sdk\Errors\GislError;
use Gisl\Sdk\Errors\GislNetworkError;
use Gisl\Sdk\Errors\GislRequestNotSentError;
use Gisl\Sdk\Errors\GislTransportError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The `t2qCrjdr` split, at the class level. Mirrors the TS assertions in
 * `packages/typescript/tests/http-downloader.test.ts`.
 *
 * The ticket exists because ONE `retryable` could not be true for both a DNS
 * failure and a 404. So the thing worth testing is not that the classes exist —
 * it is that each one now gives an ANSWER, and that the answers differ.
 */
#[CoversClass(GislTransportError::class)]
#[CoversClass(GislDownloadHttpError::class)]
#[CoversClass(GislRequestNotSentError::class)]
#[CoversClass(GislNetworkError::class)]
final class GislNetworkErrorSplitTest extends TestCase
{
    #[Test]
    public function a_transport_failure_is_always_retryable(): void
    {
        // codex 42669e3fe7be: this class DOCUMENTED "always retryable" and
        // exposed no method to say so, which is the same unbacked-claim defect
        // the whole ticket removes — one level down.
        self::assertTrue((new GislTransportError('dns exploded'))->retryable());
    }

    #[Test]
    public function an_unsendable_request_is_never_retryable(): void
    {
        // The request never left. Re-issuing it identically fails identically,
        // so backing off only burns the caller's deadline.
        self::assertFalse((new GislRequestNotSentError('malformed uri'))->retryable());
    }

    /**
     * @return list<array{int, bool}>
     */
    public static function statusRetryability(): array
    {
        return [
            [404, false],   // the case that made a class-wide `true` a lie
            [403, false],
            [410, false],
            [408, true],
            [429, true],
            [500, true],
            [503, true],    // the case that made a class-wide `false` a lie
        ];
    }

    #[Test]
    #[DataProvider('statusRetryability')]
    public function a_download_http_error_derives_retryability_from_its_status(
        int $status,
        bool $expected,
    ): void {
        $e = new GislDownloadHttpError("Download failed with status {$status}", $status);

        self::assertSame($status, $e->status);
        self::assertSame($expected, $e->retryable());
    }

    #[Test]
    public function every_half_is_still_catchable_as_the_old_class(): void
    {
        // The load-bearing compatibility claim, asserted rather than assumed:
        // three catch sites per language drive the SSE poll-fallback off
        // GislNetworkError, so narrowing the base would have silently changed
        // which failures fall back to polling.
        foreach (
            [
                new GislTransportError('t'),
                new GislRequestNotSentError('r'),
                new GislDownloadHttpError('d', 404),
            ] as $e
        ) {
            self::assertInstanceOf(GislNetworkError::class, $e);
            self::assertInstanceOf(GislError::class, $e);
        }
    }

    #[Test]
    public function the_two_halves_do_not_catch_each_other(): void
    {
        // Without this, a single subclass of everything would pass the test
        // above and tell us nothing.
        self::assertNotInstanceOf(GislDownloadHttpError::class, new GislTransportError('t'));
        self::assertNotInstanceOf(GislTransportError::class, new GislDownloadHttpError('d', 404));
        self::assertNotInstanceOf(GislTransportError::class, new GislRequestNotSentError('r'));
    }

    #[Test]
    public function the_underlying_transport_exception_is_preserved(): void
    {
        $cause = new \RuntimeException('connect timed out');

        self::assertSame($cause, (new GislTransportError('t', $cause))->getPrevious());
        self::assertSame($cause, (new GislRequestNotSentError('r', $cause))->getPrevious());
        self::assertSame($cause, (new GislDownloadHttpError('d', 503, $cause))->getPrevious());
    }
}
