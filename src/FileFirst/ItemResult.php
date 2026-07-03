<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

/**
 * One succeeded entry in {@see RunResult::$succeeded}: a single input's
 * outputs, addressable by the `key:` the caller gave that file (null when
 * no key was supplied).
 *
 * Mirrors the TS `ItemResult` in `packages/typescript/src/file-first.ts`.
 */
final class ItemResult
{
    /**
     * @param list<OutputFile> $outputs The outputs this input produced.
     */
    public function __construct(
        public readonly ?string $key,
        public readonly array $outputs,
    ) {
    }

    /**
     * @return array{key: string|null, outputs: list<array{url: string, filename: string, sizeBytes: int, operation: string, chosenQuality?: int, targetSizeMet?: bool, measuredQuality?: float, qualityMetric?: string}>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'outputs' => array_map(
                static fn (OutputFile $o): array => $o->toArray(),
                $this->outputs,
            ),
        ];
    }
}
