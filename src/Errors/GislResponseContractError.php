<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * The API answered **2xx**, but the SDK could not read the body against the
 * contract — a field of the wrong type, a value the generated model rejects
 * (out of range, pattern-violating), or a payload that is not an object at all
 * (`response_contract_violation`, `u6Q9oxuI`).
 *
 * Distinct from both of its neighbours, on purpose, so a caller can branch on
 * it instead of catching everything and sorting afterwards:
 *
 * - not a {@see GislApiError} — the HTTP exchange SUCCEEDED; there is no error
 *   envelope and no failing status to report;
 * - not a {@see GislNetworkError} — the response arrived intact.
 *
 * The usual cause is the SDK and the API being on different contract versions,
 * e.g. in the window between a producer and a consumer deploying. Before this
 * class the generated deserialiser's own `InvalidArgumentException` (or a
 * `TypeError`) escaped every `catch (GislError)`.
 *
 * **Never retryable:** re-reading the same host returns the same body.
 *
 * - `operation` — the request path the response answered, query string
 *   removed (e.g. `/api/workflows/{id}/status`).
 * - `path` — the field the generated model rejected, when it names one, else
 *   `null`.
 * - `getPrevious()` — the underlying deserialiser failure, when there was one.
 *
 * ⚠️ An ABSENT required field is NOT reported: it hydrates as null. New
 * required response fields ship contract-first, and failing a whole call on
 * one would break it against any producer not yet deployed — the co-land
 * window. See `GislClient::hydrate()`.
 *
 * ⚠️ The two SDKs detect different subsets, because each wraps what its own
 * generated deserialiser throws: PHP's setters validate ranges and patterns
 * (TS's do not), while TS's `FromJSON` throws on an absent required map or
 * list (PHP's leaves it null). Same class, same meaning, same `retryable`.
 *
 * Mirrors `GislResponseContractError` in `packages/typescript/src/errors.ts`.
 */
final class GislResponseContractError extends GislError
{
    public function __construct(
        string $message,
        public readonly string $operation,
        public readonly ?string $path = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Always `false` — re-reading the same host returns the same body. */
    public function retryable(): bool
    {
        return false;
    }
}
