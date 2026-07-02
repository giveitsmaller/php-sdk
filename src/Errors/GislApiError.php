<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

use Gisl\Sdk\Generated\SdkSpec\ErrorCategory;
use Gisl\Sdk\Generated\SdkSpec\Errors;
use Gisl\Sdk\Http\RateLimitHeaders;

/**
 * 4xx / 5xx response carrying a typed error envelope (`{ success: false,
 * error: "...", details: [...] }`).
 *
 * Mirrors `packages/typescript/src/errors.ts:GislApiError`. The localisation
 * triple (`messageKey` + `locale` + `messageParams`) implements ticket I26 —
 * surfaced on every typed error so consumers can drive client-side i18n
 * catalogs without unwrapping the typed payload. Field names are camelCase
 * here (PHP convention); the on-wire envelope carries snake_case
 * (`message_key`, `locale`, `message_params`).
 */
class GislApiError extends GislError
{
    /**
     * @param int                          $statusCode       HTTP status code.
     * @param string                       $errorCode        Wire-stable machine code from
     *                                                       `error` field. Never localised.
     * @param array<string, mixed>         $payload          Full decoded envelope body for
     *                                                       caller-side narrowing (`details`,
     *                                                       `message_key`, `locale`,
     *                                                       `message_params` when present).
     * @param string|null                  $messageKey       Stable, never-localised i18n key.
     *                                                       Carried through from the wire
     *                                                       `message_key` field per I26.
     * @param string|null                  $locale           Locale tag (e.g. `en-GB`) the
     *                                                       server resolved for the
     *                                                       `message` and `message_key`
     *                                                       on this response.
     * @param array<string, mixed>|null    $messageParams    Substitution params for
     *                                                       client-side i18n catalog
     *                                                       rendering of `messageKey`.
     * @param array<string, string>        $responseHeaders  HTTP response headers, keys
     *                                                       LOWERCASED (RFC 9110
     *                                                       case-insensitive). Multi-value
     *                                                       headers (e.g. `set-cookie`) are
     *                                                       collapsed to a single
     *                                                       comma-joined string — do NOT
     *                                                       rely on this map for cookies.
     *                                                       Mirrors
     *                                                       `packages/typescript/src/errors.ts:34-41`.
     * @param string|null                  $contentLanguage  The `Content-Language` response
     *                                                       header value — the language the
     *                                                       server actually resolved for
     *                                                       content negotiation. DISTINCT
     *                                                       from `$locale`, which is the
     *                                                       body-envelope I26 localisation
     *                                                       tag (`ErrorEnvelope.locale`).
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly string $errorCode,
        public readonly array $payload = [],
        public readonly ?string $messageKey = null,
        public readonly ?string $locale = null,
        public readonly ?array $messageParams = null,
        public readonly array $responseHeaders = [],
        public readonly ?string $contentLanguage = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Whether this failure is worth retrying: a 408 / 429 / 5xx status OR a
     * registry code flagged retryable. OR (not `??`) — a 429 / 5xx is
     * retryable regardless of the taxonomy, and a registry-retryable code
     * (e.g. `probe_pending`) is retryable regardless of status. Mirrors the TS
     * `GislApiError.retryable` accessor.
     */
    public function retryable(): bool
    {
        $entry = $this->resolveErrorEntry();
        $registryRetryable = $entry['retryable'] ?? false;

        return RateLimitHeaders::isApiRetryableStatus($this->statusCode) || $registryRetryable;
    }

    /**
     * The taxonomy category for this error's resolved registry code, or null
     * when the code isn't in the registry (or the discriminator is absent on a
     * bare {@see GislApiError}). Call `->value` for the wire string. Mirrors
     * the TS `GislApiError.category` accessor (which returns the string union).
     */
    public function category(): ?ErrorCategory
    {
        $entry = $this->resolveErrorEntry();
        if ($entry === null) {
            return null;
        }

        return ErrorCategory::tryFrom($entry['category']);
    }

    /**
     * The tiered rate-limit snapshot parsed from the `X-RateLimit-*` response
     * headers, or null unless all of limit / remaining / reset are present and
     * integer. `resetSeconds` is seconds-to-reset.
     *
     * @return array{limit: int, remaining: int, resetSeconds: int}|null
     */
    public function rateLimit(): ?array
    {
        return RateLimitHeaders::rateLimit($this->responseHeaders);
    }

    /**
     * The `Retry-After` back-off hint in whole seconds (floor of the ms
     * parse), or null when absent / non-positive / unparseable.
     */
    public function retryAfterSeconds(): ?int
    {
        return RateLimitHeaders::retryAfterSeconds($this->responseHeaders);
    }

    /**
     * Resolve this error's registry entry, discriminator-first (D1): the raw
     * `error_type` field wins when present + known, else the envelope `error`
     * code (`$errorCode`). Both keys are normalised (trim + lowercase) to the
     * lowercase_snake registry keys. Returns null when neither resolves — the
     * accessors then fall back (category null; retryable from status). Never
     * throws on a missing / non-string discriminator.
     *
     * @return array{
     *     code: string,
     *     category: string,
     *     source: string,
     *     status: string,
     *     httpStatus: int|null,
     *     retryable: bool,
     *     sdkClass: string,
     *     description: string,
     *     metadataSchema: array<string, string>,
     * }|null
     */
    private function resolveErrorEntry(): ?array
    {
        $discriminator = $this->payload['error_type'] ?? null;
        if (\is_string($discriminator)) {
            $entry = Errors::ERROR_CODES[self::normaliseCode($discriminator)] ?? null;
            if ($entry !== null) {
                return $entry;
            }
        }

        return Errors::ERROR_CODES[self::normaliseCode($this->errorCode)] ?? null;
    }

    /**
     * Normalise a wire error code to a registry key: trim surrounding
     * whitespace + lowercase (registry keys are lowercase_snake).
     */
    private static function normaliseCode(string $code): string
    {
        return \strtolower(\trim($code));
    }
}
