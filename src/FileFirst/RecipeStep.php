<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

/**
 * One step in a {@see Recipe}'s sequential chain — an operation kind plus the
 * ergonomic arguments captured for it. Steps are lowered to {@see \Gisl\Sdk\OperationDef}
 * at `Recipe::toWorkflowPayload()` time (compress steps run the preset resolver
 * then; convert/thumbnail/text_watermark steps carry wire-ready options).
 *
 * Immutable value object. The `$options` shape is per-op:
 *  - `compress`       — `['optimize' => OptimizeFor|null]` (resolved at lower-time).
 *  - `convert`        — `['output_format' => string]` (the contract convert key; the `format` shorthand is lowered to it).
 *  - `thumbnail`      — `['width'? => int, 'height'? => int]` (nulls already dropped).
 *  - `transform`      — `{rotate?: 0|90|180|270, flip?: 'none'|'horizontal'|'vertical'|'both'}` (passthrough; nulls dropped).
 *  - `text_watermark` — `['text' => string]`.
 *  - `output`         — `['output_format'? => string, ...resize/route options]` (an INTERNAL step kind for the image
 *                       Output facade; lowers to a `compress` (same_format) or `convert` (format_change) wire op per the
 *                       route projection — see {@see Recipe::lowerOutputStep()}).
 */
final class RecipeStep
{
    /**
     * @param 'compress'|'convert'|'thumbnail'|'transform'|'text_watermark'|'output' $opType
     * @param array<string, mixed>                                       $options
     */
    public function __construct(
        public readonly string $opType,
        public readonly array $options,
    ) {
    }
}
