<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Errors;

use Gisl\Sdk\Errors\GislApiError;
use Gisl\Sdk\Generated\SdkSpec\ErrorCategory;
use Gisl\Sdk\Http\RateLimitHeaders;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for W8v4jWzx — the four additive read accessors on
 * GislApiError (retryable / category / rateLimit / retryAfterSeconds) resolved
 * from the generated ERROR_CODES registry + the response headers. Mirrors the
 * TS reference tests (packages/typescript/tests/unit/error-retry-metadata.test.ts).
 * Per-lang unit; TS↔PHP registry parity lives in the generator pytest.
 */
#[CoversClass(GislApiError::class)]
#[CoversClass(ErrorCategory::class)]
final class GislApiErrorRetryMetadataTest extends TestCase
{
    // -----------------------------------------------------------------
    // category + retryable — registry resolution
    // -----------------------------------------------------------------

    public function testProbePendingResolvesViaErrorTypeDiscriminator(): void
    {
        // PHP payload is the RAW decoded envelope array — the discriminator is
        // read from payload['error_type']. 422 is NOT status-retryable, so
        // retryable comes purely from the registry entry (probe_pending: true).
        $error = new GislApiError(
            message: 'Upload probe pending',
            statusCode: 422,
            errorCode: 'PROBE_PENDING',
            payload: ['error_type' => 'probe_pending'],
        );

        self::assertFalse(RateLimitHeaders::isApiRetryableStatus(422));
        self::assertSame(ErrorCategory::Api, $error->category());
        self::assertSame('api', $error->category()?->value);
        self::assertTrue($error->retryable());
    }

    public function testValidationErrorResolvesViaErrorTypeDiscriminator(): void
    {
        $error = new GislApiError(
            message: 'Validation error',
            statusCode: 422,
            errorCode: 'VALIDATION_ERROR',
            payload: ['error_type' => 'validation_error'],
        );

        self::assertSame(ErrorCategory::Validation, $error->category());
        self::assertFalse($error->retryable());
    }

    public function testEnvelopeErrorCodeResolvesViaErrorCode(): void
    {
        // A realistic envelope-`error` code — SCREAMING_SNAKE normalises
        // (trim + lowercase) to the registry key. No discriminator present.
        $error = new GislApiError(
            message: 'Invalid API key',
            statusCode: 401,
            errorCode: 'AUTH_FAILED',
        );

        self::assertSame(ErrorCategory::Auth, $error->category());
        // 401 is not status-retryable and the registry entry is not retryable.
        self::assertFalse($error->retryable());
    }

    public function testApiCategoryEnvelopeErrorCodeResolvesViaErrorCode(): void
    {
        $error = new GislApiError(
            message: 'Not found',
            statusCode: 404,
            errorCode: 'multipart_session_not_found',
        );

        self::assertSame(ErrorCategory::Api, $error->category());
        self::assertFalse($error->retryable());
    }

    public function testDiscriminatorWinsOverErrorCode(): void
    {
        // D1 resolution order: the error_type discriminator beats errorCode.
        $error = new GislApiError(
            message: 'Probe pending',
            statusCode: 422,
            errorCode: 'AUTH_FAILED',
            payload: ['error_type' => 'probe_pending'],
        );

        self::assertSame(ErrorCategory::Api, $error->category());
        self::assertTrue($error->retryable());
    }

    public function testStatusWinsOverNonRetryableRegistryCode(): void
    {
        // D2 OR (not ??): auth_failed is category:auth, retryable:false — but a
        // 5xx status makes the failure retryable:
        // retryable = isApiRetryableStatus(status) || entry.retryable. A
        // regression to registry-first (??) would wrongly report false here.
        $error = new GislApiError(
            message: 'Auth failed',
            statusCode: 500,
            errorCode: 'AUTH_FAILED',
        );

        self::assertTrue(RateLimitHeaders::isApiRetryableStatus(500));
        self::assertTrue($error->retryable());
        // Category still resolves to the registry entry's category.
        self::assertSame(ErrorCategory::Auth, $error->category());
    }

    // -----------------------------------------------------------------
    // unknown / absent code — category null, retryable from status only
    // -----------------------------------------------------------------

    public function testUnknownCodeWithRetryableStatus(): void
    {
        $error = new GislApiError(
            message: 'Server error',
            statusCode: 500,
            errorCode: 'unknown_error',
        );

        self::assertNull($error->category());
        self::assertTrue($error->retryable());
    }

    public function testUnknownCodeWithNonRetryableStatus(): void
    {
        $error = new GislApiError(
            message: 'Bad request',
            statusCode: 400,
            errorCode: 'unknown_error',
        );

        self::assertNull($error->category());
        self::assertFalse($error->retryable());
    }

    public function testUnknownStringDiscriminatorFallsBackToErrorCode(): void
    {
        // An unknown but STRING discriminator ('no_such_type') is not in the
        // registry, so resolution falls through to errorCode (also unknown here
        // → null category). Never throws.
        $error = new GislApiError(
            message: 'Mystery',
            statusCode: 400,
            errorCode: 'totally_unknown_code',
            payload: ['error_type' => 'no_such_type'],
        );

        self::assertNull($error->category());
        self::assertFalse($error->retryable());
    }

    public function testNonStringDiscriminatorDoesNotThrowAndFallsBackToErrorCode(): void
    {
        // A present-but-non-string error_type (int 123) must be skipped by the
        // is_string() guard WITHOUT throwing; resolution falls through to
        // errorCode. A registry errorCode then resolves the entry.
        $resolved = new GislApiError(
            message: 'Auth failed',
            statusCode: 401,
            errorCode: 'AUTH_FAILED',
            payload: ['error_type' => 123],
        );
        self::assertSame(ErrorCategory::Auth, $resolved->category());

        // When errorCode also isn't a registry key, category is null (still no
        // throw) and retryable derives purely from status.
        $unknown = new GislApiError(
            message: 'x',
            statusCode: 400,
            errorCode: 'not_a_registry_code',
            payload: ['error_type' => 123],
        );
        self::assertNull($unknown->category());
        self::assertFalse($unknown->retryable());
    }

    // -----------------------------------------------------------------
    // retryable — status predicate boundaries via the accessor
    // -----------------------------------------------------------------

    /**
     * @return list<array{int, bool}>
     */
    public static function statusBoundaryProvider(): array
    {
        return [
            [408, true],
            [429, true],
            [500, true],
            [599, true],
            [400, false],
            [404, false],
            [600, false],
        ];
    }

    #[DataProvider('statusBoundaryProvider')]
    public function testRetryableFollowsStatusBoundaries(int $status, bool $expected): void
    {
        // errorCode is a registry miss so retryable is driven purely by status.
        $error = new GislApiError(
            message: 'x',
            statusCode: $status,
            errorCode: 'unknown_error',
        );

        self::assertSame($expected, $error->retryable());
    }

    // -----------------------------------------------------------------
    // 429 rate-limited response — snapshot + back-off hint together
    // -----------------------------------------------------------------

    public function testRateLimitedResponseExposesSnapshotAndBackoff(): void
    {
        $error = new GislApiError(
            message: 'Too many requests',
            statusCode: 429,
            errorCode: 'RATE_LIMITED',
            responseHeaders: [
                'x-ratelimit-limit' => '100',
                'x-ratelimit-remaining' => '0',
                'x-ratelimit-reset' => '42',
                'retry-after' => '30',
            ],
        );

        self::assertTrue($error->retryable());
        self::assertSame(
            ['limit' => 100, 'remaining' => 0, 'resetSeconds' => 42],
            $error->rateLimit(),
        );
        self::assertSame(30, $error->retryAfterSeconds());
    }

    // -----------------------------------------------------------------
    // rateLimit / retryAfterSeconds shapes
    // -----------------------------------------------------------------

    public function testFlatAuthShapeHasBackoffButNoSnapshot(): void
    {
        // Only Retry-After (no x-ratelimit-*): retryAfterSeconds present,
        // rateLimit null.
        $error = new GislApiError(
            message: 'Slow down',
            statusCode: 429,
            errorCode: 'RATE_LIMITED',
            responseHeaders: ['retry-after' => '60'],
        );

        self::assertSame(60, $error->retryAfterSeconds());
        self::assertNull($error->rateLimit());
    }

    public function testPartialRateLimitSetIsNull(): void
    {
        $error = new GislApiError(
            message: 'Slow down',
            statusCode: 429,
            errorCode: 'RATE_LIMITED',
            responseHeaders: [
                'x-ratelimit-limit' => '100',
                'x-ratelimit-remaining' => '5',
                // reset omitted
            ],
        );

        self::assertNull($error->rateLimit());
    }

    public function testNoResponseHeadersYieldsNullAccessors(): void
    {
        $error = new GislApiError(
            message: 'Server error',
            statusCode: 500,
            errorCode: 'unknown_error',
        );

        self::assertNull($error->rateLimit());
        self::assertNull($error->retryAfterSeconds());
    }

    public function testZeroAndMalformedRetryAfterAreNull(): void
    {
        $zero = new GislApiError(
            message: 'x',
            statusCode: 429,
            errorCode: 'RATE_LIMITED',
            responseHeaders: ['retry-after' => '0'],
        );
        self::assertNull($zero->retryAfterSeconds());

        $bad = new GislApiError(
            message: 'x',
            statusCode: 429,
            errorCode: 'RATE_LIMITED',
            responseHeaders: ['retry-after' => 'later'],
        );
        self::assertNull($bad->retryAfterSeconds());
    }
}
