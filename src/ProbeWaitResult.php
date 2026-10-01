<?php

declare(strict_types=1);

namespace Gisl\Sdk;

use Gisl\Generated\OpenApi\Model\UploadProbeResponse;

/**
 * Result of {@see GislClient::waitForProbe()}.
 *
 * Mirrors the TS `ProbeWaitResult` at `packages/typescript/src/types.ts`.
 * The wait NEVER throws for a slow/unavailable probe — `landed: false` with a
 * `reason` is the give-up signal, and the caller proceeds to create the
 * workflow anyway (the server's size heuristic routes the job; worst case =
 * single-task, i.e. today's behaviour). Genuine failures (404 upload-not-found,
 * auth, caller cancellation) DO throw out of `waitForProbe`.
 */
final class ProbeWaitResult
{
    /**
     * @param bool                      $landed True iff `POST /api/uploads/{id}/probe` returned
     *                                          200 (ANY `probe_status`). A landed probe lets the
     *                                          server admit the parallel video split.
     * @param UploadProbeResponse|null  $probe  The landed probe response — present iff `landed`.
     * @param 'timeout'|'prober_error'|'not_applicable'|null $reason Why the wait gave up
     *                                          without landing — present iff `!landed`.
     *                                          `not_applicable` = the server answered
     *                                          `422 probe_not_applicable` (the upload's
     *                                          type is never probed, e.g. an image), so
     *                                          the wait stopped after one request.
     */
    public function __construct(
        public readonly bool $landed,
        public readonly ?UploadProbeResponse $probe = null,
        public readonly ?string $reason = null,
    ) {
    }
}
