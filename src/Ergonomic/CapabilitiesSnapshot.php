<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

use Gisl\Generated\OpenApi\Model\ImageEncodeCapabilities;
use Gisl\Generated\OpenApi\Model\OperationCapability;
use Gisl\Generated\OpenApi\Model\OutputProperties;

/**
 * Typed projection of the operation-capability surface returned by
 * {@see \Gisl\Sdk\GislErgonomicClient::capabilities()} (qUhxfDA5). Bundles the
 * three v2.124 capability fields of `OperationsSchemaResponse` — previously
 * typed but with no ergonomic consumer — so a caller can read them without
 * dropping to `getSchema()` and its hit/not-modified result union.
 *
 * Mirrors the TS `CapabilitiesSnapshot` interface
 * (`packages/typescript/src/types.ts`); the field set is identical across the
 * two SDKs.
 */
final class CapabilitiesSnapshot
{
    /**
     * @param array<string, OperationCapability> $operations       Tier-scoped operation-capability
     *                                                              matrix, keyed by operation type
     *                                                              (empty when the server omits it).
     * @param array<string, OutputProperties>    $outputProperties Output-format property table
     *                                                              (`hasAudioTrack`/`isAnimated`),
     *                                                              keyed by output_format; tier-invariant.
     */
    public function __construct(
        public readonly array $operations,
        public readonly array $outputProperties,
        public readonly ?ImageEncodeCapabilities $imageEncode = null,
    ) {
    }

    /**
     * Plain-array projection (keys mirror the TS interface fields).
     *
     * @return array{
     *   operations: array<string, OperationCapability>,
     *   outputProperties: array<string, OutputProperties>,
     *   imageEncode: ImageEncodeCapabilities|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'operations' => $this->operations,
            'outputProperties' => $this->outputProperties,
            'imageEncode' => $this->imageEncode,
        ];
    }
}
