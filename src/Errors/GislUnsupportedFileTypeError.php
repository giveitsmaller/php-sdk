<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * 415 — the upload's file type is one NO tier can process (eWtnqHZm; api
 * A1hdxtPC). An upgrade does not help, unlike {@see GislTierRestrictedError}
 * (403 `tier_restriction`, where some tier does permit the type). Thrown from
 * `uploadFile()` (single-shot and multipart initiate). Dispatched on the status
 * alone: the contract models the body as a plain `ErrorEnvelope`, and the
 * machine code (`UNSUPPORTED_FILE_TYPE`) is on `->errorCode`.
 *
 * Mirrors `packages/typescript/src/errors.ts:GislUnsupportedFileTypeError`.
 */
final class GislUnsupportedFileTypeError extends GislApiError
{
}
