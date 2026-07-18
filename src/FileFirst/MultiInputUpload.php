<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

use Gisl\Generated\OpenApi\Model\WorkflowCreateResponse;
use Gisl\Sdk\Cancellation;
use Gisl\Sdk\Ergonomic\BuilderInternals;
use Gisl\Sdk\Ergonomic\UploadProgressEvent;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\UploadOptions;
use Gisl\Sdk\WorkflowCreatePayload;

/**
 * @internal
 *
 * Shared multi-input upload-then-create TAIL for the multi-input file-first
 * recipes ({@see FilesRecipe}, {@see MergedRecipe}, {@see ArchivedRecipe},
 * {@see WatermarkedRecipe}). The PHP analogue of the TS `_uploadInputsAndCreate`
 * free function in `packages/typescript/src/file-first.ts` (xxy5Rlsy).
 *
 * Scope is the upload-then-create TAIL — the upload loop (verbatim pre-uploaded
 * id; path or seekable resource stream otherwise, with the byte-counter progress
 * closure + resource filename/contentType hints), the post-upload deadline/cancel
 * checks, the best-effort sequential probe-before-create, and `createWorkflow` —
 * PLUS a shared composed-payload preflight HEAD (see below).
 *
 * Two preflight layers, by design:
 *  1. Recipe-specific INPUT validation stays caller-side and runs before this: it
 *     differs by recipe (FilesRecipe additionally lowers each input's op chain for
 *     per-input `media_unknown` validation, which the combine recipes do not),
 *     plus `validatePreUpload()` and the `no_client` guard.
 *  2. This helper then OPENS with a shared composed-payload preflight — it lowers
 *     `$toPayload` with placeholder ids so a COMPOSITION-level lowering error (a
 *     fan-out `nested_sole_op`, a route-invalid option in a watermark base/overlay
 *     or a merge/archive member) fails before ANY upload, closing the gap the
 *     per-recipe input preflight leaves (T3ltXsou).
 *
 * The recipe-specific cancel-stage strings and timeout-message nouns are passed in
 * so every thrown message is byte-identical to the per-recipe originals.
 *
 * Lives in the FileFirst namespace (not {@see BuilderInternals}) because it
 * consumes {@see FileInput}, which itself depends on the Ergonomic namespace —
 * placing it there would invert the FileFirst -> Ergonomic dependency direction.
 */
final class MultiInputUpload
{
    /**
     * @param list<FileInput>                                       $inputs
     * @param \Closure(list<string>, ?string): WorkflowCreatePayload $toPayload
     * @param \Closure(UploadProgressEvent): void|null              $onProgressClosure
     */
    public static function uploadAllAndCreate(
        GislClient $client,
        array $inputs,
        \Closure $toPayload,
        ?string $webhook,
        ?int $deadlineMs,
        ?\Closure $onProgressClosure,
        ?Cancellation $cancellation,
        ?bool $probeBeforeCreate,
        ?int $probeTimeoutMs,
        string $uploadCancelStage,
        string $workflowCancelStage,
        string $uploadsLabel,
        string $workflowLabel,
    ): WorkflowCreateResponse {
        // Preflight: lower the composed chains with placeholder ids so a
        // route-invalid option (or any lowering-time gate — overlays, sole_op
        // split, route/enum) in ANY input's chain — a watermark base/overlay, a
        // merge/archive member — throws BEFORE we spend a single upload byte. The
        // multi-input analog of the single-input Recipe preflight (0azjb6Rg);
        // mirrors TS. The placeholder ids never reach the wire — the payload is
        // discarded (T3ltXsou). toWorkflowPayload is pure, so re-lowering at
        // create is cheap.
        $toPayload(array_map(static fn (int $i): string => "preflight_{$i}", array_keys($inputs)), null);

        // Upload EVERY input: verbatim for a pre-uploaded id; a path or a
        // seekable stream resource otherwise, via the byte-counter progress
        // closure. A pre-uploaded id carries no local mime/size, so it is never
        // probed.
        $fileIds = [];
        /** @var list<array{fileId: string, isVideo: bool, sizeBytes: int|null}> $probeTargets */
        $probeTargets = [];
        foreach ($inputs as $input) {
            // Fail fast between uploads — a deadline that elapses or a caller
            // cancel mid-batch should not force every remaining input to upload
            // before throwing.
            BuilderInternals::throwIfCancelled($cancellation, $uploadCancelStage);
            if ($deadlineMs !== null && BuilderInternals::nowMs() >= $deadlineMs) {
                throw new GislTimeoutError("maxWait elapsed during {$uploadsLabel} uploads before all inputs were uploaded.");
            }
            if ($input->kind === FileInput::KIND_UPLOAD_ID) {
                $fileIds[] = BuilderInternals::coerceString($input->fileId);
                continue;
            }
            $onProgressUpload = $onProgressClosure !== null
                ? static function (int $u, int $t) use ($onProgressClosure): void {
                    $onProgressClosure(new UploadProgressEvent($u, $t));
                }
                : null;
            $uploadTarget = BuilderInternals::coerceString($input->path);
            $uploadOpts = $onProgressUpload !== null ? new UploadOptions(onProgress: $onProgressUpload) : null;
            if ($input->kind === FileInput::KIND_RESOURCE) {
                \assert(\is_resource($input->resource));
                $uploadTarget = $input->resource;
                // Carry the resource's filename/contentType hints into the
                // upload (fFwaKsN5) so a resource input is consistent with the
                // single-file file() path.
                $uploadOpts = new UploadOptions(
                    onProgress: $onProgressUpload,
                    contentType: $input->contentType,
                    filename: $input->filename,
                );
            }
            $uploadResp = $client->uploadFile($uploadTarget, $uploadOpts);
            $fileIds[] = $uploadResp->getFileId() ?? '';
            $probeTargets[] = [
                'fileId' => $uploadResp->getFileId() ?? '',
                'isVideo' => $input->compressMediaHint() === 'video',
                'sizeBytes' => $uploadResp->getSizeBytes(),
            ];
        }

        BuilderInternals::throwIfCancelled($cancellation, $workflowCancelStage);
        if ($deadlineMs !== null && BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError("Uploads completed but maxWait elapsed before {$workflowLabel} could be created.");
        }

        // Best-effort probe-before-create for the multipart-video inputs
        // (sequential with a shared budget; never-bounce). The total budget is
        // capped to the remaining maxWait (run() passes $deadlineMs; submit()
        // passes null → uncapped) so the waits cannot push createWorkflow past
        // the caller's deadline.
        BuilderInternals::waitForVideoProbes($client, $probeTargets, $probeBeforeCreate, $probeTimeoutMs, $cancellation, $deadlineMs);
        // A cancel arriving during a FINAL successful probe request must not
        // still create the workflow (the probe waits return landed without a
        // final cancel re-check), so check here BEFORE createWorkflow.
        BuilderInternals::throwIfCancelled($cancellation, $workflowCancelStage);
        // RE-CHECK the deadline AFTER the probe waits (they consume time).
        if ($deadlineMs !== null && BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError("Probe wait completed but maxWait elapsed before {$workflowLabel} could be created.");
        }

        return $client->createWorkflow($toPayload($fileIds, $webhook));
    }
}
