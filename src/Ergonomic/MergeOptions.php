<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * Merge-level options passed to {@see \Gisl\Sdk\GislErgonomicClient::merge()}.
 * Mirrors the TS `MergeOptions` interface at
 * `packages/typescript/src/merge.ts:131-152`.
 *
 * SDK-only fields (NOT serialised to the wire):
 *  - {@see $mediaKind}: forces the inferred kind, bypassing the
 *    first-asset filename sniff. Cast to wire variant inside
 *    {@see MergeBuilder::buildPayload()}.
 *  - {@see $allowUnusedAssets}: bypasses the unused-asset local validator.
 *    Rarely needed; usually indicates a bug.
 *
 * Wire-level options (all snake_cased into `operations[0].options`):
 *  - `transition`, `crossfade_duration` — every media kind
 *  - `gap_duration` — AUDIO only (TS R2 medium ab2422e56ea0); dropped
 *    silently for video/image
 *  - `re_encode_mode`, `codec`, `crf`, `preset`, `target_resolution`,
 *    `target_size_bytes` + `encoding_mode: target_size` — VIDEO only
 *  - `normalize_audio` — video + audio
 *  - `transition_duration`, `fps`, `duration_per_image`, `delay`,
 *    `loop_count`, `video_format` — IMAGE only
 *  - `output_type` — every media kind
 */
final class MergeOptions
{
    public function __construct(
        public readonly ?string $transition = null,
        public readonly ?float $crossfadeDuration = null,
        public readonly ?float $gapDuration = null,
        public readonly ?bool $normalizeAudio = null,
        /**
         * Video re-encode policy (`auto`|`always`|`never`). Passed through
         * verbatim — the worker only honours `codec`/`crf`/`preset`/
         * `targetResolution`/`targetSize` when re-encoding (`auto`/`always`);
         * the server owns that dependency validation (this SDK is a passthrough
         * allowlist, same as the pre-existing codec/crf/preset fields).
         */
        public readonly ?string $reEncodeMode = null,
        public readonly ?string $codec = null,
        public readonly ?int $crf = null,
        public readonly ?string $preset = null,
        /** Video output dimensions `WxH` (e.g. `'1920x1080'`); omit to inherit from inputs. */
        public readonly ?string $targetResolution = null,
        /**
         * Target output size. Lowered to wire `target_size_bytes`, and the SDK also
         * sets `encoding_mode: 'target_size'` alongside it.
         *
         * UNITS ARE DECIMAL HERE (1 KB = 1000), UNLIKE compress. The compress
         * `targetSize` parses the same strings as BINARY (1 KB = 1024), so '50MB' is
         * 50,000,000 bytes on a merge and 52,428,800 on a compress. That divergence is
         * NOT deliberate — it contradicts the pinned convention that every
         * human-readable size string in this SDK is binary — and it has a sharp edge:
         * the contract floor for `target_size_bytes` is 1 MiB (1,048,576), so '1MB'
         * here resolves to 1,000,000 and is rejected as below the minimum. Prefer an
         * explicit byte count until this is reconciled. Tracked by YOCz0i74; changing
         * it moves bytes for existing callers, so it is a deliberate decision rather
         * than a silent correction.
         *
         * NOT AVAILABLE FOR LONG INPUTS. Merges whose summed input duration routes to
         * the long-form Fargate path reject both keys — that path is single-pass-CRF
         * by construction and two-pass target-size is unbuilt. The request fails during
         * execution, and the SDK cannot warn earlier: the routing decision is made
         * server-side at create-plan time, so there is nothing here to check it
         * against. Short-form merges honour it normally. Tracked by zJN6XIi5, blocked
         * on a contract that can express per-execution-path availability.
         *
         * @var int|string|null Numeric byte count, or sized string `'10MB'`/`'500KB'`/`'1.5GB'`.
         */
        public readonly int|string|null $targetSize = null,
        public readonly ?float $transitionDuration = null,
        public readonly ?float $fps = null,
        public readonly ?float $durationPerImage = null,
        /** Milliseconds between frames for an animated-GIF image merge (`output_type: gif`). */
        public readonly ?int $delay = null,
        public readonly ?int $loopCount = null,
        public readonly ?string $output = null,
        public readonly ?string $videoFormat = null,
        public readonly ?string $outputType = null,
        /** @var "video"|"audio"|"image"|null */
        public readonly ?string $mediaKind = null,
        public readonly bool $allowUnusedAssets = false,
    ) {
    }
}
