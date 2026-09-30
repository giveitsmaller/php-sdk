<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * Per-position options carried by {@see ClipEntry}. Mirrors the TS
 * `ClipOptions` interface at `packages/typescript/src/merge.ts:92-99`.
 *
 * Wire-truth (lowering.md §sequences):
 *  - Image merges reject ANY per-input options: a clip with any of these
 *    set on an image-merge sequence raises {@see \Gisl\Sdk\Errors\GislConfigError}
 *    locally before upload.
 *  - Video merges accept `transition` + `crossfadeDuration` + `trimStart` +
 *    `trimEnd`.
 *  - Audio merges accept `transition` + `crossfadeDuration` + `gapDuration` +
 *    `trimStart` + `trimEnd`.
 *  - `trimStart` / `trimEnd` are seconds cut from this clip before any
 *    transition (contract `min: 0`; the upper bound is the clip's own
 *    duration, enforced server-side at processing time).
 *  - On a video merge, `gapDuration` is dropped at wire-format time
 *    (audio-only field) per TS R1 medium 128404fa16a9.
 */
final class ClipOptions
{
    public function __construct(
        public readonly ?string $transition = null,
        public readonly ?float $crossfadeDuration = null,
        public readonly ?float $gapDuration = null,
        // Appended last so existing positional callers keep their meaning.
        public readonly ?float $trimStart = null,
        public readonly ?float $trimEnd = null,
    ) {
    }

    /**
     * True if any per-input option is set. Used by the planner to gate
     * image-merge clip rejection AND to skip emitting an empty
     * `per_input_options` object on the wire.
     */
    public function isEmpty(): bool
    {
        return $this->transition === null
            && $this->crossfadeDuration === null
            && $this->gapDuration === null
            && $this->trimStart === null
            && $this->trimEnd === null;
    }
}
