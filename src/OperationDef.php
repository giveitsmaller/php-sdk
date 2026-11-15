<?php

declare(strict_types=1);

namespace Gisl\Sdk;

use Gisl\Generated\OpenApi\Model\OperationDefinition;

/**
 * One operation inside a {@see JobDefinitionPayload}.
 *
 * Mirrors the TS interface at packages/typescript/src/types.ts:73-76 — a
 * hand-written shape distinct from the generated `OperationDefinition` (which
 * exposes setters/getters and is the wire-receive type). This SDK-side input
 * type stays minimal so callers building requests get a clean named-arg
 * surface.
 */
final class OperationDef
{
    /**
     * @param string                $type    Operation kind: `compress`, `convert`, `merge`,
     *                                       `archive`, `thumbnail_image`, `thumbnail_video`,
     *                                       `thumbnail_document`, `thumbnail_office`,
     *                                       `image_watermark`, `text_watermark`,
     *                                       `audio_overlay`, `audio_watermark`,
     *                                       `custom_luma`. The server validates.
     * @param array<string, mixed>|null $options Operation-family-specific options. Snake_case
     *                                           wire keys (e.g. `quality`, `output_format`).
     * @param OperationDefinition::BASE_*|null $base For derived artifacts (`thumbnail`, `split`):
     *                                           the composition node the artifact branches from.
     *                                           The default depends on the operation (owner
     *                                           decision 720): `thumbnail` defaults to `original`,
     *                                           `split` to `processed_base`. `null` sends no
     *                                           `base` key, so the server default applies.
     */
    public function __construct(
        public readonly string $type,
        public readonly ?array $options = null,
        public readonly ?string $base = null,
    ) {
    }

    /**
     * @return array{type: string, options?: array<string, mixed>, base?: string}
     */
    public function toWire(): array
    {
        $payload = ['type' => $this->type];
        if ($this->options !== null) {
            $payload['options'] = $this->options;
        }
        if ($this->base !== null) {
            $payload['base'] = $this->base;
        }
        return $payload;
    }
}
