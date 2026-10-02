<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * Flat result projection returned by {@see OperationBuilder::run()}.
 *
 * `artifacts` is always an array (empty on failure); error info surfaces
 * via `status === 'failed'` and per-operation `errorCode` / `errorMessage`
 * on {@see Result::$jobs}.
 *
 * `url` is sugar for `$result->artifacts[0]->url` when the result has
 * exactly one artifact; `null` otherwise (e.g. multi-output workflows,
 * failed workflows). Use {@see Result::$artifacts} for the canonical
 * shape.
 *
 * Mirrors the TS `Result` interface at
 * `packages/typescript/src/builder.ts:147-163`.
 */
final class Result
{
    /**
     * @param list<Artifact>     $artifacts
     * @param list<JobBreakdown> $jobs
     */
    public function __construct(
        public readonly string $workflowId,
        public readonly string $status,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
        public readonly array $artifacts,
        public readonly array $jobs,
        public readonly ?string $url,
        public readonly ResolvedOptions $resolvedOptions,
        /**
         * How the terminal status was actually observed (v0JhuD8V); see
         * {@see RunTransport}. Always set by `run()` — a `mapEach()` combined
         * result is `Sse` only when the parent and every child streamed to
         * terminal. Nullable with a default only so the constructor stays
         * backward compatible.
         */
        public readonly ?RunTransport $transport = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'workflowId' => $this->workflowId,
            'status' => $this->status,
        ];
        if ($this->createdAt !== null) {
            $out['createdAt'] = $this->createdAt;
        }
        if ($this->updatedAt !== null) {
            $out['updatedAt'] = $this->updatedAt;
        }
        $out['artifacts'] = \array_map(
            static fn (Artifact $a): array => $a->toArray(),
            $this->artifacts,
        );
        $out['jobs'] = \array_map(
            static fn (JobBreakdown $j): array => $j->toArray(),
            $this->jobs,
        );
        if ($this->url !== null) {
            $out['url'] = $this->url;
        }
        $out['resolvedOptions'] = $this->resolvedOptions->toArray();
        // Last, matching the TS Result's key order (v0JhuD8V).
        if ($this->transport !== null) {
            $out['transport'] = $this->transport->value;
        }
        return $out;
    }
}
