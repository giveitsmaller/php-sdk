<?php

declare(strict_types=1);

namespace Gisl\Sdk;

use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Preset\AudioCompressPresetOptions;
use Gisl\Sdk\Preset\DocumentEpubCompressPresetOptions;
use Gisl\Sdk\Preset\DocumentOdfCompressPresetOptions;
use Gisl\Sdk\Preset\DocumentOfficeCompressPresetOptions;
use Gisl\Sdk\Preset\ImageCompressPresetOptions;
use Gisl\Sdk\Preset\VideoCompressPresetOptions;

/**
 * Immutable builder for layered preset configuration. Mirrors the TS
 * `PresetDefaults` (T4a).
 *
 *   $defaults = PresetDefaults::create()
 *       ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 75))
 *       ->videoCompress(OptimizeFor::Quality);
 *
 * Each per-cell method is immutable: it returns a FRESH instance carrying
 * the prior registrations plus the new (mediaOp, level) delta — calling the
 * same method twice on one instance yields two distinct objects. A call
 * with no input registers an empty delta ("apply shipped defaults verbatim
 * for this cell"); the P6 resolver reads the registered delta via
 * {@see cellFor()} and merges shipped defaults + this user delta + per-call
 * overrides. This card ships only the typed slot + builder semantics; the
 * merge logic and `Gisl::create()` wiring land with the resolver (P6).
 *
 * @phpstan-type PresetCellDelta ImageCompressPresetOptions|AudioCompressPresetOptions|VideoCompressPresetOptions|DocumentOfficeCompressPresetOptions|DocumentOdfCompressPresetOptions|DocumentEpubCompressPresetOptions
 */
final class PresetDefaults
{
    /**
     * Registered user deltas keyed by "<mediaOpKey>:<level wire value>".
     *
     * @param array<string, PresetCellDelta> $cells
     */
    private function __construct(private readonly array $cells)
    {
    }

    public static function create(): self
    {
        return new self([]);
    }

    public function imageCompress(OptimizeFor $level, ?ImageCompressPresetOptions $input = null): self
    {
        return $this->with('image_compress', $level, $input ?? new ImageCompressPresetOptions());
    }

    public function audioCompress(OptimizeFor $level, ?AudioCompressPresetOptions $input = null): self
    {
        return $this->with('audio_compress', $level, $input ?? new AudioCompressPresetOptions());
    }

    public function videoCompress(OptimizeFor $level, ?VideoCompressPresetOptions $input = null): self
    {
        return $this->with('video_compress', $level, $input ?? new VideoCompressPresetOptions());
    }

    public function officeCompress(OptimizeFor $level, ?DocumentOfficeCompressPresetOptions $input = null): self
    {
        return $this->with('document_office_compress', $level, $input ?? new DocumentOfficeCompressPresetOptions());
    }

    public function odfCompress(OptimizeFor $level, ?DocumentOdfCompressPresetOptions $input = null): self
    {
        return $this->with('document_odf_compress', $level, $input ?? new DocumentOdfCompressPresetOptions());
    }

    public function epubCompress(OptimizeFor $level, ?DocumentEpubCompressPresetOptions $input = null): self
    {
        return $this->with('document_epub_compress', $level, $input ?? new DocumentEpubCompressPresetOptions());
    }

    /**
     * Return the registered USER delta for (mediaOpKey, level), or null if
     * no delta was registered. Does NOT return shipped defaults — those
     * live on the leaf DTO via `*::shippedDefaultsFor($level)`.
     *
     * `$mediaOpKey` is the generated PRESETS key, e.g. `'image_compress'`
     * or `'document_office_compress'`.
     *
     * @return PresetCellDelta|null
     */
    public function cellFor(string $mediaOpKey, OptimizeFor $level): ImageCompressPresetOptions|AudioCompressPresetOptions|VideoCompressPresetOptions|DocumentOfficeCompressPresetOptions|DocumentOdfCompressPresetOptions|DocumentEpubCompressPresetOptions|null
    {
        return $this->cells[self::key($mediaOpKey, $level)] ?? null;
    }

    /**
     * Deep-merge two {@see PresetDefaults} into a new instance (P7 /
     * `5k3ZWo6B`). Used by {@see GislErgonomicClient::withPresetDefaults()} to
     * stack scoped derives: `withPresetDefaults($a)->withPresetDefaults($b)`
     * yields a scoped layer equivalent to `merge($a, $b)` — `$child`'s per-cell
     * fields override `$parent`'s where defined; `$parent`'s fields fill the
     * gaps. A `(mediaOpKey, level)` present in only one side is taken verbatim.
     *
     * Per-cell semantics are scalar-leaf (every leaf-DTO field is a primitive,
     * enum case, or `string|int` targetSize), so a field-wise `?? ` overlay
     * gives the correct override. Mirrors the TS `PresetDefaults.merge` +
     * `mergePresetOptions` (`packages/typescript/src/ergonomic/presets/index.ts`).
     *
     * Both `$parent` and `$child` are left unchanged.
     */
    public static function merge(self $parent, self $child): self
    {
        $cells = $parent->cells;
        foreach ($child->cells as $key => $childDelta) {
            $existing = $cells[$key] ?? null;
            $cells[$key] = $existing === null ? $childDelta : self::mergeCell($existing, $childDelta);
        }

        return new self($cells);
    }

    /**
     * Field-wise merge of two same-class leaf DTOs: `$child` field wins where
     * non-null, `$parent` fills the gaps. Dispatches on the concrete leaf type
     * so the typed constructor is called with statically-known field types
     * (mirrors the TS `switch (cellKey)` in `mergePresetOptions`). `$parent` and
     * `$child` are always the same class (same `(mediaOpKey, level)` cell).
     *
     * @param PresetCellDelta $parent
     * @param PresetCellDelta $child
     *
     * @return PresetCellDelta
     */
    private static function mergeCell(object $parent, object $child): object
    {
        return match (true) {
            $parent instanceof ImageCompressPresetOptions && $child instanceof ImageCompressPresetOptions
                => new ImageCompressPresetOptions(
                    quality: $child->quality ?? $parent->quality,
                    metadata: $child->metadata ?? $parent->metadata,
                    outputFormat: $child->outputFormat ?? $parent->outputFormat,
                ),
            $parent instanceof AudioCompressPresetOptions && $child instanceof AudioCompressPresetOptions
                => new AudioCompressPresetOptions(
                    bitrate: $child->bitrate ?? $parent->bitrate,
                    channels: $child->channels ?? $parent->channels,
                    sampleRate: $child->sampleRate ?? $parent->sampleRate,
                    normalize: $child->normalize ?? $parent->normalize,
                ),
            $parent instanceof VideoCompressPresetOptions && $child instanceof VideoCompressPresetOptions
                => new VideoCompressPresetOptions(
                    codec: $child->codec ?? $parent->codec,
                    targetSize: $child->targetSize ?? $parent->targetSize,
                    crf: $child->crf ?? $parent->crf,
                    preset: $child->preset ?? $parent->preset,
                    width: $child->width ?? $parent->width,
                    height: $child->height ?? $parent->height,
                    fit: $child->fit ?? $parent->fit,
                    fps: $child->fps ?? $parent->fps,
                    faststart: $child->faststart ?? $parent->faststart,
                    audioCodec: $child->audioCodec ?? $parent->audioCodec,
                    audioBitrate: $child->audioBitrate ?? $parent->audioBitrate,
                ),
            $parent instanceof DocumentOfficeCompressPresetOptions && $child instanceof DocumentOfficeCompressPresetOptions
                => new DocumentOfficeCompressPresetOptions(
                    stripMacros: $child->stripMacros ?? $parent->stripMacros,
                    stripHiddenData: $child->stripHiddenData ?? $parent->stripHiddenData,
                    stripUnusedFonts: $child->stripUnusedFonts ?? $parent->stripUnusedFonts,
                ),
            $parent instanceof DocumentOdfCompressPresetOptions && $child instanceof DocumentOdfCompressPresetOptions
                => new DocumentOdfCompressPresetOptions(
                    stripMetadata: $child->stripMetadata ?? $parent->stripMetadata,
                    stripUnusedStyles: $child->stripUnusedStyles ?? $parent->stripUnusedStyles,
                ),
            $parent instanceof DocumentEpubCompressPresetOptions && $child instanceof DocumentEpubCompressPresetOptions
                => new DocumentEpubCompressPresetOptions(
                    fontSubsetting: $child->fontSubsetting ?? $parent->fontSubsetting,
                    stripUnusedCss: $child->stripUnusedCss ?? $parent->stripUnusedCss,
                ),
            default => throw new \LogicException(
                'Preset cell merge received mismatched or unknown leaf types: '
                . $parent::class . ' vs ' . $child::class . '.',
            ),
        };
    }

    /**
     * @param PresetCellDelta $delta
     */
    private function with(string $mediaOpKey, OptimizeFor $level, object $delta): self
    {
        $cells = $this->cells;
        $cells[self::key($mediaOpKey, $level)] = $delta;

        return new self($cells);
    }

    private static function key(string $mediaOpKey, OptimizeFor $level): string
    {
        return $mediaOpKey . ':' . $level->value;
    }
}
