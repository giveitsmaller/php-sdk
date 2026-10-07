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
         * Units are BINARY (1 KB = 1024; B/KB/MB/GB/TB), exactly as the compress
         * targetSize — one shared parser, so '50MB' is 52,428,800 bytes on both and
         * '1MB' is the contract floor (1 MiB). Merge parsed decimal before YOCz0i74.
         *
         * NOT AVAILABLE FOR LONG INPUTS. Merges whose summed input duration routes to
         * the long-form Fargate path reject both keys — that path is single-pass-CRF
         * by construction and two-pass target-size is unbuilt. The request fails during
         * execution, and the SDK cannot warn earlier: the routing decision is made
         * server-side at create-plan time, so there is nothing here to check it
         * against. Short-form merges honour it normally.
         *
         * The contract CAN now express this — per_class_availability scopes an
         * option to a processing class, vendored at v2.195.0 and pinned by
         * PerClassAvailabilityConformanceTest. That buys an honest 422 from the
         * API at CREATE rather than a job dying mid-execution; it does NOT become
         * a client-side gate, because routing is still decided server-side and a
         * duration heuristic here would be wrong at the boundary. Tracked by
         * zJN6XIi5.
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
