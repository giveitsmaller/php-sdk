<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

use Gisl\Sdk\Ergonomic\OperationBuilder;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\OperationDef;

/**
 * Watermark routing + planned-op gating (FF4a). Mirrors the TS helpers in
 * `packages/typescript/src/file-first.ts`.
 *
 * The {@see CAPABILITY} table is the single SDK-side source of truth for which
 * `(wire op, base mime)` combinations the file-first `watermark()` verb may emit
 * and their availability. The generated typed metadata sidecar does NOT carry
 * the supported-mime allowlist (`MimeGroupMetadata` has no `mimes` field and
 * `per_mime_availability` is empty for these ops), so this hand table is the
 * gate's source — PINNED to the generated `availability.json` by a conformance
 * test (mirrors the wire-key-conformance pattern). The gate reads ONLY this table.
 */
final class WatermarkGate
{
    public const OP_IMAGE = 'image_watermark';
    public const OP_VIDEO = 'video_watermark';

    /**
     * wire op => group => { mimes, availability }. Pinned to availability.json
     * by WatermarkCapabilityConformanceTest.
     *
     * @var array<string, array<string, array{mimes: list<string>, availability: string}>>
     */
    public const CAPABILITY = [
        self::OP_IMAGE => [
            'image' => ['mimes' => ['image/jpeg', 'image/png', 'image/webp'], 'availability' => 'stable'],
            'image_gif' => ['mimes' => ['image/gif'], 'availability' => 'planned'],
            'image_tiff' => ['mimes' => ['image/tiff'], 'availability' => 'stable'],
            'image_bmp' => ['mimes' => ['image/bmp'], 'availability' => 'stable'],
        ],
        self::OP_VIDEO => [
            // 🔴 WITHDRAWN to `planned` by contracts v2.203.0 (withdrawn
            // 2026-09-15, tag cut 2026-09-16). It was `stable`: a worker EXISTS and cannot serve the
            // advertised ceiling — a different kind of `planned` from image_gif,
            // where nothing is built, with opposite remedies and no contract
            // field separating them yet (contracts SYQhXb6R).
            'video' => ['mimes' => ['video/mp4', 'video/webm'], 'availability' => 'planned'],
        ],
    ];

    private const SHIPPABLE = ['stable', 'beta'];

    /**
     * extension => canonical MIME for the gate. Covers the supported formats
     * PLUS common known-but-unsupported ones so the gate throws an actionable
     * "unsupported subtype" rather than silently routing a rejected format.
     *
     * @var array<string, string>
     */
    private const EXT_MIME = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
        'avif' => 'image/avif', 'heic' => 'image/heic', 'heif' => 'image/heif', 'tiff' => 'image/tiff', 'tif' => 'image/tiff', 'bmp' => 'image/bmp',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'mkv' => 'video/x-matroska',
        'avi' => 'video/x-msvideo', 'wmv' => 'video/x-ms-wmv', 'flv' => 'video/x-flv', 'm4v' => 'video/x-m4v',
    ];

    private static function extToMime(string $nameOrFormat): ?string
    {
        $dot = \strrpos($nameOrFormat, '.');
        $ext = \strtolower($dot === false ? $nameOrFormat : \substr($nameOrFormat, $dot + 1));
        return self::EXT_MIME[$ext] ?? null;
    }

    private static function resolveConvertOutputMedia(?string $source, string $outputFormat): ?string
    {
        if ($source === 'video' && \strtolower($outputFormat) === 'ogg') {
            return 'video';
        }
        return OperationBuilder::detectCompressMedia("f.{$outputFormat}");
    }

    /**
     * Resolve the effective `[media, mime]` a watermark op operates on AFTER
     * folding the preceding `convert` (output media + format) and `thumbnail`
     * (always an image output) steps — mirrors the TS `_watermarkEffectiveBase`.
     *
     * @param list<RecipeStep> $steps
     * @return array{0: ?string, 1: ?string} [media, mime]
     */
    public static function effectiveBase(FileInput $input, array $steps): array
    {
        $media = $input->compressMediaHint();
        $mime = self::inputMime($input);
        foreach ($steps as $step) {
            if ($step->opType === 'convert') {
                $fmt = $step->options['output_format'] ?? null;
                if (\is_string($fmt)) {
                    $media = self::resolveConvertOutputMedia($media, $fmt);
                    $mime = self::extToMime($fmt);
                }
            } elseif ($step->opType === 'thumbnail') {
                $media = 'image';
                $mime = 'image/png';
            }
        }
        // Recover the coarse media from a usable (already-normalised) mime when the
        // case-sensitive media classifier could not (e.g. an oddly-cased Image/PNG
        // content-type) — keeps the gate self-consistent: a usable mime implies media.
        if ($media === null && $mime !== null) {
            if (\str_starts_with($mime, 'image/')) {
                $media = 'image';
            } elseif (\str_starts_with($mime, 'video/')) {
                $media = 'video';
            } elseif (\str_starts_with($mime, 'audio/')) {
                $media = 'audio';
            }
        }
        return [$media, $mime];
    }

    private static function inputMime(FileInput $input): ?string
    {
        if ($input->kind === FileInput::KIND_PATH && $input->path !== null) {
            return self::extToMime($input->path);
        }
        if ($input->kind === FileInput::KIND_RESOURCE) {
            // Mirror compressMediaHint precedence: use contentType ONLY when it is
            // media-bearing (image/ video/ audio/ — params stripped, lowercased);
            // a generic/unknown type (e.g. application/octet-stream) falls back to
            // the filename extension, so a filename-hinted resource routes like a path.
            if ($input->contentType !== null) {
                $raw = \strtolower(\trim(\explode(';', $input->contentType)[0]));
                if (\str_starts_with($raw, 'image/') || \str_starts_with($raw, 'video/') || \str_starts_with($raw, 'audio/')) {
                    return $raw;
                }
            }
            if ($input->filename !== null) {
                return self::extToMime($input->filename);
            }
        }
        return null;
    }

    /**
     * Resolve the wire op (`image_watermark` / `video_watermark`) for a watermark
     * base, or THROW {@see GislConfigError} pre-upload — the planned-op gate. The
     * capability is read from {@see CAPABILITY}: a base mime in a `{stable,beta}`
     * group routes; a `planned` group (animated GIF) throws; a known image/video
     * subtype outside the allowlist (AVIF/HEIC/MOV/…) throws "unsupported"; audio/
     * document throw "not supported". An undetectable base media throws an
     * actionable error. Mirrors the TS `_resolveWatermarkWireOp`.
     */
    public static function resolveWireOp(?string $media, ?string $mime): string
    {
        if ($media === null) {
            throw new GislConfigError(
                'watermark needs a detectable base media to route to image_watermark / video_watermark, '
                . 'but the input has no inferable type (a pre-uploaded file id or hint-less resource carries '
                . 'no extension or MIME). Use a path with a file extension, or a resource with a filename/contentType hint.',
                reason: 'media_unknown',
            );
        }
        if ($mime !== null) {
            foreach (self::CAPABILITY as $wireOp => $groups) {
                foreach ($groups as $group) {
                    if (\in_array($mime, $group['mimes'], true)) {
                        if (\in_array($group['availability'], self::SHIPPABLE, true)) {
                            return $wireOp;
                        }
                        throw new GislConfigError(
                            // ⚠️ "not yet" was a promise the contract does not make:
                            // it is false for a capability that WAS stable and has
                            // been withdrawn (v2.203.0 did that to video_watermark).
                            // Mirrors the TypeScript wording; no contract field
                            // separates the two kinds yet (contracts SYQhXb6R).
                            "watermark for {$mime} bases is not available ({$wireOp} is "
                            . "'{$group['availability']}' in the contract this SDK was built against). "
                            . 'Workflow-create would return feature_not_available, so this is refused '
                            . 'before any upload. Check getSchema() for the current server answer.',
                            reason: 'feature_not_available',
                        );
                    }
                }
            }
        }
        if ($media === 'image' || $media === 'video') {
            $shown = $mime ?? $media;
            throw new GislConfigError(
                "watermark does not support {$shown} base files. image_watermark accepts image/jpeg, image/png, "
                . 'image/webp; video_watermark accepts video/mp4, video/webm. Convert the base to a supported format first.',
                reason: 'unsupported_media',
            );
        }
        throw new GislConfigError(
            "watermark does not support {$media} base files — overlay watermarking targets image or video bases. "
            . 'textWatermark() is image-only, so it is not an alternative for document or audio bases.',
            reason: 'unsupported_media',
        );
    }

    /** Contract `per_role_cardinality.overlay.max` / `overlays[]` `maxItems`. */
    private const MAX_OVERLAYS = 8;

    /**
     * The overlay argument of `watermark()` as a list: one {@see Recipe} is the
     * single-overlay path, a list is the multi-overlay stack. The contract caps the
     * overlay role at 1-8 sources, so a count outside that (or a non-Recipe
     * element) throws pre-upload. Mirrors the TS `_normalizeWatermarkOverlays`.
     *
     * @param Recipe|array<mixed> $overlay
     * @return list<Recipe>
     */
    public static function normalizeOverlays(Recipe|array $overlay): array
    {
        $overlays = $overlay instanceof Recipe ? [$overlay] : \array_values($overlay);
        $count = \count($overlays);
        if ($count < 1 || $count > self::MAX_OVERLAYS) {
            throw new GislConfigError(
                'watermark() takes 1 to ' . self::MAX_OVERLAYS . " overlays; got {$count}.",
                reason: 'invalid_overlay_count',
                conflictingFields: ['overlay'],
            );
        }
        $recipes = [];
        foreach ($overlays as $each) {
            if (!$each instanceof Recipe) {
                throw new GislConfigError(
                    'watermark() overlays must be Recipe file-nodes (e.g. $client->file(\'logo.png\')); got '
                    . \get_debug_type($each) . '.',
                    reason: 'invalid_overlay',
                    conflictingFields: ['overlay'],
                );
            }
            $recipes[] = $each;
        }

        return $recipes;
    }

    /**
     * Validate a watermark overlay locally: the overlay role is always an IMAGE.
     * A KNOWN non-image overlay (audio/video/document) throws pre-upload; an
     * undetectable overlay media is ALLOWED (the server enforces it). Mirrors the
     * TS `_validateWatermarkOverlay`.
     */
    public static function validateOverlay(Recipe $overlay): void
    {
        [$media] = self::effectiveBase($overlay->recipeInput(), $overlay->recipeSteps());
        if ($media !== null && $media !== 'image') {
            throw new GislConfigError(
                "watermark overlay must be an image; got a {$media} overlay. The overlay is the watermark image "
                . 'composited onto the base — pass an image file (or a recipe whose output is an image).',
                reason: 'invalid_overlay_media',
                conflictingFields: ['overlay'],
            );
        }
    }

    /** The flat single-overlay placement options, mutually exclusive with `overlays[]`. */
    private const FLAT_PLACEMENT_KEYS = ['anchor', 'margin_x', 'margin_y', 'opacity', 'overlay_width'];

    /**
     * Lower the watermark op itself. Every option (the flat keys and `overlays[]`)
     * is already a wire key; empty options omit the `options` key (byte-identical
     * to the TS `_lowerWatermarkOp`).
     *
     * The contract declares the multi-overlay stack (`overlays[]`, and any request
     * with more than one overlay source) on the `image` group ONLY — jpeg/png/webp;
     * and `overlays[$i]` places overlay source $i, so its length MUST equal the
     * overlay count. Both are refused here, at lowering (which the pre-upload
     * preflight runs), so a post-watermark() `$opts['overlays'] = [...]` mutation
     * is caught too. A second overlay without `overlays[]`, and `overlays[]` set
     * alongside a flat placement option, are refused too (both are
     * `invalid_options` server-side).
     *
     * @param array<string, mixed> $options
     */
    public static function lowerWatermarkOp(
        string $wireOp,
        array $options,
        int $overlayCount = 1,
        ?string $baseMime = null,
    ): OperationDef {
        // array_key_exists (not isset): a present `overlays => null` is checked
        // too, matching the TS `overlays !== undefined`.
        $hasOverlays = array_key_exists('overlays', $options);
        $multiOverlayMimes = self::CAPABILITY[self::OP_IMAGE]['image']['mimes'];
        if (
            ($hasOverlays || $overlayCount > 1)
            && ($wireOp !== self::OP_IMAGE || $baseMime === null || !\in_array($baseMime, $multiOverlayMimes, true))
        ) {
            throw new GislConfigError(
                "watermark(): multiple overlays / 'overlays[]' need a " . \implode(', ', $multiOverlayMimes)
                . ' base; got ' . ($baseMime ?? 'an undetectable') . ' base. Other bases take a single overlay placed '
                . 'with the top-level anchor / opacity / margin_x / margin_y / overlay_width options.',
                reason: 'overlays_unsupported_base',
                conflictingFields: ['overlays'],
            );
        }
        // A second overlay with no `overlays[]` is invalid too: the flat options
        // place ONE overlay (codex 5fd9cafaf7ca).
        if (!$hasOverlays && $overlayCount > 1) {
            throw new GislConfigError(
                "watermark(): {$overlayCount} overlays need 'overlays[]' with one placement per overlay, in overlay "
                . 'order; the top-level anchor / opacity / margin_x / margin_y / overlay_width place a single overlay.',
                reason: 'overlays_count_mismatch',
                conflictingFields: ['overlays', 'overlay'],
            );
        }
        // `overlays[]` and the flat single-overlay options are mutually exclusive
        // (`invalid_options`, api + worker); no silent precedence (codex 7383823c9875).
        $flatSet = \array_values(\array_filter(
            self::FLAT_PLACEMENT_KEYS,
            // array_key_exists (not isset): `anchor => null` is still sent, so it still conflicts.
            static fn (string $key): bool => \array_key_exists($key, $options),
        ));
        if ($hasOverlays && $flatSet !== []) {
            throw new GislConfigError(
                "watermark(): 'overlays[]' cannot be combined with the single-overlay option(s) "
                . \implode(', ', $flatSet) . "; put each overlay's placement inside its 'overlays[]' entry.",
                reason: 'invalid_combination',
                conflictingFields: ['overlays', ...$flatSet],
            );
        }
        if ($hasOverlays) {
            $given = $options['overlays'];
            $givenIsList = \is_array($given) && \array_is_list($given);
            if (!$givenIsList || \count($given) !== $overlayCount) {
                throw new GislConfigError(
                    "watermark(): 'overlays[]' needs exactly one entry per overlay, in overlay order — "
                    . "{$overlayCount} overlay(s), " . ($givenIsList ? \count($given) . ' entries' : 'not a list') . '.',
                    reason: 'overlays_count_mismatch',
                    conflictingFields: ['overlays', 'overlay'],
                );
            }
        }

        return new OperationDef(type: $wireOp, options: $options === [] ? null : $options);
    }
}
