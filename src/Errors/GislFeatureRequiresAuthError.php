<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * Raised when an operation is invoked through a code path that has no
 * credentials AND is not on the anonymous-capable allowlist. Carries
 * the operation name so callers can recover by switching to an
 * authenticated factory or routing to a different op.
 *
 * Raised by the client {@see \Gisl\Sdk\Gisl::anonymous()} returns
 * ({@see \Gisl\Sdk\GislAnonymousClient}) for any method outside
 * {@see \Gisl\Sdk\Gisl::ANONYMOUS_ALLOWLIST}, and for a guest upload too large
 * for the single-shot path — always before any request.
 *
 * Mirrors `packages/typescript/src/errors.ts` `GislFeatureRequiresAuthError`.
 */
final class GislFeatureRequiresAuthError extends GislConfigError
{
    public function __construct(
        public readonly string $operation,
        string $message,
    ) {
        parent::__construct($message);
    }
}
