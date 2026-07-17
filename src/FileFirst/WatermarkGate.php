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
            'video' => ['mimes' => ['video/mp4', 'video/webm'], 'availability' => 'beta'],
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
                            "watermark for {$mime} bases is not yet available ({$wireOp} is '{$group['availability']}'). "
                            . 'The contract schema is defined but the server returns feature_not_available until it ships.',
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

    /**
     * Lower the watermark op itself. Options (anchor/opacity/margin_x/margin_y/
     * overlay_width, or the multi-overlay overlays[] stack) are already wire
     * keys; empty options omit the `options` key (byte-identical to the TS
     * `_lowerWatermarkOp`).
     *
     * @param array<string, mixed> $options
     */
    public static function lowerWatermarkOp(string $wireOp, array $options): OperationDef
    {
        return new OperationDef(type: $wireOp, options: $options === [] ? null : $options);
    }
}
