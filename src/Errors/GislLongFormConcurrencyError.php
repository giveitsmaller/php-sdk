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
     * ALWAYS `false`, overriding the base 429-implies-retryable heuristic
     * (UO1xYecu). This 429 is not a rate limit: it carries no `Retry-After` and
     * clears only when an in-flight long-form workflow finishes, so a back-off
     * retries into a wall that no amount of waiting-then-retrying opens. The
     * base accessor reported `true` purely from the status, contradicting this
     * class's own documented handling ("wait on completion or upgrade — do NOT
     * back off") and instructing the one recovery that cannot work.
     *
     * Overridden per-class rather than via a code table because this is the only
     * such code today; the general fix — an explicit taxonomy verdict outranking
     * the status heuristic — arrives with the `error-taxonomy.yaml` `retryable`
     * enum (contracts `plwcAqBr`). Mirrors the TS override.
     */
    #[\Override]
    public function retryable(): bool
    {
        return false;
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
