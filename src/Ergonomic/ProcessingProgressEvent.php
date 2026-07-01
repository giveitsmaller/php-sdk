<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * Processing-phase progress event. Projects `SseOperationProgressData`
 * from the wire with the `phase` discriminator added by the SDK.
 *
 * Wire fields surfaced verbatim:
 *  - `status`     — one of `started|downloading|probing|decoding|processing|encoding|uploading`
 *                   (server-side enum; SDK does not validate).
 *  - `progress`   — percent complete, `0.0..100.0` (the wire int `0-100` cast to
 *                   float; NOT normalized to a 0..1 fraction).
 *  - `jobRef`     — author-supplied job id.
 *  - `operationId`— UUID v7 of the operation.
 *  - `stage`      — optional sub-stage label.
 *  - `phaseInputIndex` / `phaseTotalInputs` — 1-based index + total of the input
 *                   currently being processed (e.g. "probing input 2/4" on
 *                   long-form merges). Per codex TS r2 medium ed873d706d96.
 *
 * Mirrors `ProcessingProgressEvent` at
 * `packages/typescript/src/builder.ts:195-210`.
 */
final class ProcessingProgressEvent extends ProgressEvent
{
    public function __construct(
        public readonly float $progress,
        public readonly string $jobRef,
        public readonly string $operationId,
        public readonly ?string $status = null,
        public readonly ?string $stage = null,
        public readonly ?int $phaseInputIndex = null,
        public readonly ?int $phaseTotalInputs = null,
    ) {
        parent::__construct('processing');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'phase' => $this->phase,
            'progress' => $this->progress,
            'jobRef' => $this->jobRef,
            'operationId' => $this->operationId,
        ];
        if ($this->status !== null) {
            $out['status'] = $this->status;
        }
        if ($this->stage !== null) {
            $out['stage'] = $this->stage;
        }
        if ($this->phaseInputIndex !== null) {
            $out['phaseInputIndex'] = $this->phaseInputIndex;
        }
        if ($this->phaseTotalInputs !== null) {
            $out['phaseTotalInputs'] = $this->phaseTotalInputs;
        }
        return $out;
    }
}
