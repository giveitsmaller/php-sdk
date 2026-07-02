<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

/**
 * A single deliverable output of a file-first run — the file-first layer's
 * flat output type.
 *
 * Distinct from (and coexisting with) the operation-first
 * {@see \Gisl\Sdk\Ergonomic\Artifact} until the operation-first layer is
 * removed (FF6). The file-first surface is deliberately leaner: just the
 * four fields a caller needs to identify + fetch an output.
 *
 * Mirrors the TS `OutputFile` in `packages/typescript/src/file-first.ts`.
 */
final class OutputFile
{
    /**
     * @param int|null  $chosenQuality For a `target_size` encode: the quality the
     *                                 encode-measure loop settled on. Projected from
     *                                 the generated OperationDownload; null (omitted)
     *                                 for non-target-size outputs.
     * @param bool|null $targetSizeMet For a `target_size` encode: whether the output
     *                                 landed at or under the requested byte target.
     *                                 `false` is an honest best-effort outcome, NOT a
     *                                 failure. Null for non-target-size outputs.
     * @param float|null  $measuredQuality The measured perceptual quality of an
     *                                     `auto_quality` encode (0-1). Projected from
     *                                     the generated OperationDownload; null when
     *                                     the worker reported no measurement.
     * @param string|null $qualityMetric  The metric `$measuredQuality` was measured on
     *                                     (e.g. `ssimulacra2`). Null when no
     *                                     measurement was reported.
     */
    public function __construct(
        public readonly string $url,
        public readonly string $filename,
        public readonly int $sizeBytes,
        public readonly string $operation,
        public readonly ?int $chosenQuality = null,
        public readonly ?bool $targetSizeMet = null,
        public readonly ?float $measuredQuality = null,
        public readonly ?string $qualityMetric = null,
    ) {
    }

    /**
     * Plain-array projection for tests + JSON-serialise paths. Field order
     * is fixed so the serialised shape matches the TS reference exactly
     * (cross-language shape assertion, FF1; harness parity fixture, FF2b).
     * The target-size fields are OMITTED when null, mirroring TS's
     * omit-when-undefined so non-target-size outputs stay byte-identical.
     *
     * @return array{url: string, filename: string, sizeBytes: int, operation: string, chosenQuality?: int, targetSizeMet?: bool, measuredQuality?: float, qualityMetric?: string}
     */
    public function toArray(): array
    {
        $out = [
            'url' => $this->url,
            'filename' => $this->filename,
            'sizeBytes' => $this->sizeBytes,
            'operation' => $this->operation,
        ];
        if ($this->chosenQuality !== null) {
            $out['chosenQuality'] = $this->chosenQuality;
        }
        if ($this->targetSizeMet !== null) {
            $out['targetSizeMet'] = $this->targetSizeMet;
        }
        if ($this->measuredQuality !== null) {
            $out['measuredQuality'] = $this->measuredQuality;
        }
        if ($this->qualityMetric !== null) {
            $out['qualityMetric'] = $this->qualityMetric;
        }
        return $out;
    }
}
