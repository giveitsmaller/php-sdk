<?php

declare(strict_types=1);

namespace Gisl\Sdk;

/**
 * Per-call options for {@see GislClient::getSchema()}.
 *
 * Mirrors the TS `GetSchemaOptions` interface at
 * `packages/typescript/src/types.ts`. All fields optional — passing no
 * options fetches the full schema with no conditional revalidation.
 *
 * `mimeType` and `operation` filter the returned schema server-side and
 * are forwarded as query parameters. `ifNoneMatch` drives HTTP conditional
 * revalidation: pass the previously-received `ETag` to receive a 304
 * sentinel ({@see GetSchemaNotModifiedResult}) when the cached copy is still
 * fresh. `ifModifiedSince` is sent as `If-Modified-Since`, but the API does
 * NOT honour it: the ETag is the endpoint's sole conditional validator and
 * `Last-Modified` is informational only (contract
 * `GET /api/operations/schema`), so passing only it always returns the full
 * schema.
 */
final class GetSchemaOptions
{
    public function __construct(
        public readonly ?string $mimeType = null,
        public readonly ?string $operation = null,
        public readonly ?string $ifNoneMatch = null,
        public readonly ?string $ifModifiedSince = null,
    ) {
    }
}
