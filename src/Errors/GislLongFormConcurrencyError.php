<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

use Gisl\Generated\OpenApi\Model\LongFormConcurrencyLimitResponse;

/**
 * 429 on `POST /api/workflows` when the caller already holds the maximum number
 * of concurrent in-flight long-form (Fargate) workflows their tier permits
 * (Pro 2 / Max 5; Enterprise uncapped). DISTINCT from an infrastructure
 * rate-limit 429: it carries the machine code `LONG_FORM_CONCURRENCY_LIMIT_EXCEEDED`
 * and a `links.upgrade` deep link, and has NO `Retry-After` — the limit clears
 * when an in-flight long-form workflow finishes, not on a timer. A generic infra
 * rate-limit 429 (no matching code) surfaces as the base {@see GislApiError}
 * instead, where {@see GislApiError::retryAfterSeconds()} applies.
 *
 * Dispatched on the machine `error` CODE, not `error_type` (the envelope carries
 * none). Mirrors `packages/typescript/src/errors.ts:GislLongFormConcurrencyError`.
 */
final class GislLongFormConcurrencyError extends GislApiError
{
    /**
     * @param array<string, string> $responseHeaders  HTTP response headers, keys LOWERCASED.
     *                                                Multi-value headers comma-joined.
     *                                                Mirrors {@see GislApiError::$responseHeaders}.
     * @param string|null           $contentLanguage  `Content-Language` response header.
     *                                                DISTINCT from `$locale` (I26 body tag).
     */
    public function __construct(
        string $message,
        int $statusCode,
        string $errorCode,
        public readonly LongFormConcurrencyLimitResponse $typedPayload,
        array $payload = [],
        ?string $messageKey = null,
        ?string $locale = null,
        ?array $messageParams = null,
        array $responseHeaders = [],
        ?string $contentLanguage = null,
    ) {
        parent::__construct($message, $statusCode, $errorCode, $payload, $messageKey, $locale, $messageParams, $responseHeaders, $contentLanguage);
    }

    /**
     * The pricing / upgrade deep link (`links.upgrade`), or null when absent.
     * Mirrors the TS `GislLongFormConcurrencyError.upgradeUrl` getter.
     */
    public function upgradeUrl(): ?string
    {
        return $this->typedPayload->getLinks()?->getUpgrade();
    }
}
