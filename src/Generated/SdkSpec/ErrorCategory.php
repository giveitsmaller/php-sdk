<?php

/**
 * CODE GENERATED — DO NOT EDIT.
 * Source: compression_contracts/sdk-spec/ (see sdk-spec/README.md).
 * Regenerate with: scripts/generate.py.
 */

declare(strict_types=1);

namespace Gisl\Sdk\Generated\SdkSpec;

/**
 * Error-taxonomy category — the grouping every ERROR_CODES entry carries.
 * Mirrors the TS `ErrorCategory` union at
 * packages/typescript/src/generated/sdk_spec/errors.ts.
 */
enum ErrorCategory: string
{
    case Api = 'api';
    case Config = 'config';
    case Network = 'network';
    case Auth = 'auth';
    case Validation = 'validation';
    case Chain = 'chain';
}
