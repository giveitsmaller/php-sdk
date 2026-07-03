<?php

declare(strict_types=1);

namespace Gisl\Sdk;

use Gisl\Generated\OpenApi\Model\AudioWatermarkDecodeRequest;
use Gisl\Generated\OpenApi\Model\AudioWatermarkDecodeResponse;
use Gisl\Generated\OpenApi\Model\AuthErrorResponse;
use Gisl\Generated\OpenApi\Model\AuthErrorType;
use Gisl\Generated\OpenApi\Model\AuthRejectionEnvelope;
use Gisl\Generated\OpenApi\Model\BalanceExhaustedResponse;
use Gisl\Generated\OpenApi\Model\AccountLimits;
use Gisl\Generated\OpenApi\Model\ContactRequest;
use Gisl\Generated\OpenApi\Model\CreditsBalanceResponse;
use Gisl\Generated\OpenApi\Model\CreditsUsageResponse;
use Gisl\Generated\OpenApi\Model\ExternalImportCreatedResponse;
use Gisl\Generated\OpenApi\Model\ExternalImportRequest;
use Gisl\Generated\OpenApi\Model\FeatureNotAvailableResponse;
use Gisl\Generated\OpenApi\Model\FeatureTierRestrictedResponse;
use Gisl\Generated\OpenApi\Model\LoginUser200ResponseData;
use Gisl\Generated\OpenApi\Model\LoginUserRequest;
use Gisl\Generated\OpenApi\Model\MetadataResponse;
use Gisl\Generated\OpenApi\Model\MultipartInitiateResponse;
use Gisl\Generated\OpenApi\Model\OperationsSchemaResponse;
use Gisl\Generated\OpenApi\Model\PresignedUrlPart;
use Gisl\Generated\OpenApi\Model\RetryResponse;
use Gisl\Generated\OpenApi\Model\TierRestrictionResponse;
use Gisl\Generated\OpenApi\Model\UploadProbeResponse;
use Gisl\Generated\OpenApi\Model\UploadResponse;
use Gisl\Generated\OpenApi\Model\ValidationErrorEnvelope;
use Gisl\Generated\OpenApi\Model\WorkflowCancelResponse;
use Gisl\Generated\OpenApi\Model\WorkflowCreateResponse;
use Gisl\Generated\OpenApi\Model\WorkflowDownloadResponse;
use Gisl\Generated\OpenApi\Model\ProbePendingResponse;
use Gisl\Generated\OpenApi\Model\WorkflowExpiredResponse;
use Gisl\Generated\OpenApi\Model\UploadDurationExceedsTierResponse;
use Gisl\Generated\OpenApi\Model\UploadSizeExceedsTierResponse;
use Gisl\Generated\OpenApi\Model\WorkflowListResponse;
use Gisl\Generated\OpenApi\Model\WorkflowResumeResponse;
use Gisl\Generated\OpenApi\Model\WorkflowStatusResponse;
use Gisl\Generated\OpenApi\Model\WorkflowSummary;
use Gisl\Generated\OpenApi\ObjectSerializer;
use Gisl\Sdk\Errors\GislAbortError;
use Gisl\Sdk\Errors\GislApiError;
use Gisl\Sdk\Errors\GislAuthError;
use Gisl\Sdk\Errors\GislAuthRejectionError;
use Gisl\Sdk\Errors\GislBalanceExhaustedError;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislError;
use Gisl\Sdk\Errors\GislFeatureNotAvailableError;
use Gisl\Sdk\Errors\GislFeatureTierRestrictedError;
use Gisl\Sdk\Errors\GislMultipartPartCountError;
use Gisl\Sdk\Errors\GislMultipartPartError;
use Gisl\Sdk\Http\MultipartPartUploader;
use Gisl\Sdk\Http\RateLimitHeaders;
use Gisl\Sdk\Http\UploadSource;
use Gisl\Sdk\Errors\GislMultipartSessionAuthRequiredError;
use Gisl\Sdk\Errors\GislMultipartSessionNotFoundError;
use Gisl\Sdk\Errors\GislMultipartSessionOwnershipError;
use Gisl\Sdk\Errors\GislNetworkError;
use Gisl\Sdk\Errors\GislTierRestrictedError;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\Errors\GislUploadCapExceededError;
use Gisl\Sdk\Errors\GislValidationError;
use Gisl\Sdk\Errors\GislProbePendingError;
use Gisl\Sdk\Errors\GislWorkflowExpiredError;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Thin, customer-facing PHP SDK for the GISL compression service.
 *
 * Public-API surface mirrors `packages/typescript/src/client.ts`. This
 * scaffold (sub-card `VOxtu0RZ-A`) ships the constructor, the request loop,
 * envelope unwrapping, error mapping, and three core methods:
 *
 *   - {@see uploadFile}            single-shot or multipart upload (POST /api/uploads)
 *   - {@see createWorkflow}        POST /api/workflows
 *   - {@see getWorkflowStatus}     GET /api/workflows/{id}/status
 *   - {@see getWorkflowDownloads}  GET /api/workflows/{id}/downloads
 *
 * Sub-card `VOxtu0RZ-B2.3` (`lT54YsPS`) extends the surface with the
 * workflow lifecycle methods: {@see cancelWorkflow}, {@see resumeWorkflow},
 * {@see retryOperation}, {@see waitForWorkflow}, and {@see getMetadata}.
 *
 * Sub-card `VOxtu0RZ-B2.4` (`zxGUQSmI`) adds auth + credits + contact:
 * {@see login}, {@see logout}, {@see submitContact}, {@see getCreditsBalance},
 * {@see getCreditsUsage}.
 *
 * **Threading / concurrency note.** When constructed with
 * `GislClientConfig::$useSessionCookie === true`, the client holds
 * per-instance mutable session state (the `gisl_session` cookie value
 * captured from {@see login}'s `Set-Cookie` response header). Sharing one
 * GislClient instance across concurrent execution contexts — Swoole fibers,
 * ReactPHP loops, parallel PHPUnit processes, etc. — is **unsupported** in
 * that mode: a logout on context A clears the cookie observed by context B
 * mid-request. With `useSessionCookie === false` (the default), every
 * GislClient call is stateless and safe to share. Callers running concurrent
 * workloads under cookie auth should construct one GislClient per fiber /
 * worker.
 *
 * Multipart routing: when an injected {@see Http\MultipartPartUploader} is
 * present (the `curl_multi` uploader wired by {@see Gisl::create()} with
 * ext-curl + `multipartConcurrency > 1`, z9bDW2iH), fresh-upload chunks are PUT
 * to S3 concurrently, bounded by `GislClientConfig::$multipartConcurrency`.
 * With no uploader injected (direct construction, or concurrency 1, or
 * ext-curl absent) chunks are PUT one at a time. The resume path
 * (`resumeUploadId`) uses the same uploader, concurrent per batch of missing
 * parts (`7Vl01jFs`). SSE and the parity runner arrive in `VOxtu0RZ-B2`
 * (`bf68ju2r`).
 *
 * The HTTP transport is PSR-18 abstract — callers may inject their own
 * client / factories, or let php-http/discovery resolve installed
 * implementations at runtime. Tests must always inject explicit mocks so the
 * assertion surface stays deterministic.
 *
 * **Not `final` by design.** {@see GislErgonomicClient} extends this
 * to add the ergonomic operation-builder factory methods (`compress` /
 * `thumbnail` / `convert` / `watermark` / `archive`). The TS reference
 * composes those methods via a Proxy
 * (`packages/typescript/src/gisl.ts:102-133`); PHP has no Proxy
 * primitive, so the seal is loosened here for a single deliberate
 * subclass. Other downstream consumers should still treat `GislClient`
 * as the stable low-level surface and AVOID subclassing.
 */
class GislClient
{
    private readonly ClientInterface $httpClient;
    private readonly RequestFactoryInterface $requestFactory;
    private readonly StreamFactoryInterface $streamFactory;

    /**
     * Captured `gisl_session` cookie value when
     * `GislClientConfig::$useSessionCookie === true`. Mutated only by
     * {@see login} (set) and {@see logout} (cleared). See the threading-
     * unsafety note on this class's docblock — sharing one GislClient
     * across concurrent contexts under cookie auth is unsupported.
     */
    private ?string $sessionCookie = null;

    /**
     * Concurrent multipart part-uploader (z9bDW2iH). NULL ⇒ the sequential
     * PSR-18 chunk loop (the default for direct construction, so the
     * `StubPsr18Client` parity/unit suites keep capturing part PUTs). The
     * ergonomic factory {@see Gisl::create()} injects a {@see CurlMultiPartUploader}
     * for production, gated on ext-curl + `multipartConcurrency > 1`.
     */
    private readonly ?MultipartPartUploader $partUploader;

    public function __construct(
        public readonly GislClientConfig $config,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?MultipartPartUploader $partUploader = null,
    ) {
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
        $this->partUploader = $partUploader;
    }

    /**
     * Upload a file. Routes to single-shot for files at-or-below
     * `multipartThresholdBytes`, else to the sequential multipart path.
     *
     * @param string|resource $filePathOrResource Filesystem path string, or an
     *                                            open **seekable** stream
     *                                            resource (the in-memory/Blob
     *                                            analogue, VOxtu0RZ-B4). A
     *                                            non-seekable stream
     *                                            (php://stdin, a pipe) is
     *                                            rejected with an actionable
     *                                            error — buffer it to a temp
     *                                            path or pass a seekable
     *                                            stream (php://temp).
     */
    public function uploadFile(
        mixed $filePathOrResource,
        ?UploadOptions $options = null,
    ): UploadResponse {
        if (\is_resource($filePathOrResource)) {
            $source = UploadSource::fromStream($filePathOrResource);
        } elseif (\is_string($filePathOrResource)) {
            $source = UploadSource::fromPath($filePathOrResource);
        } else {
            throw new GislConfigError(
                'uploadFile expected a string filesystem path or a stream resource; got ' . \get_debug_type($filePathOrResource) . '.',
            );
        }

        $size = $source->size();
        // fFwaKsN5: an explicit UploadOptions filename (e.g. a file-first resource
        // input's name hint) overrides the source-derived name — a nameless
        // stream defaults to `upload.bin`, which carries no extension for the
        // server's media inference. An empty string falls back to the source name.
        $fileName = (\is_string($options?->filename) && $options->filename !== '')
            ? $options->filename
            : $source->name();

        if (\is_string($options?->resumeUploadId) && $options->resumeUploadId !== '') {
            // Resume re-walks a durable multipart session — it requires a
            // re-openable filesystem path; a stream cursor does not survive the
            // original process, so stream resume is unsupported.
            if (!$source->isConcurrencySafe()) {
                throw new GislConfigError(
                    'uploadFile: resumeUploadId is not supported for stream inputs; resume requires a filesystem path.',
                    reason: 'stream_resume_unsupported',
                );
            }
            // SDK-3 (Wb6ebOMM): resume path takes the durable session's
            // `recommended_chunk_size` from /status (initiate is skipped).
            // Below the multipart threshold a resume is meaningless — the
            // original session was started as multipart, so a sub-threshold
            // file CAN'T be a "resume target" in practice. Guard explicitly
            // so a confused caller gets a clear error rather than a 404 on
            // /status. Mirrors the TS reference.
            if ($size <= $this->config->multipartThresholdBytes) {
                throw new GislError(
                    "uploadFile: resumeUploadId set but file size ({$size}) is at-or-below the "
                    . "multipart threshold ({$this->config->multipartThresholdBytes}); resume targets "
                    . 'must be multipart sessions.',
                );
            }
            return $this->multipartResume(
                source: $source,
                fileName: $fileName,
                totalSize: $size,
                resumeUploadId: $options->resumeUploadId,
                options: $options,
            );
        }

        if ($size > $this->config->multipartThresholdBytes) {
            return $this->multipartUpload($source, $fileName, $size, $options);
        }

        return $this->singleShotUpload($source, $fileName, $size, $options);
    }

    private function singleShotUpload(
        UploadSource $source,
        string $fileName,
        int $size,
        ?UploadOptions $options,
    ): UploadResponse {
        $boundary = $this->generateMultipartBoundary();
        $body = $this->buildSingleShotMultipartBody(
            boundary: $boundary,
            source: $source,
            fileName: $fileName,
            contentType: $options?->contentType,
        );

        $request = $this->buildRequest(
            method: 'POST',
            path: '/api/uploads',
            body: $body,
            extraHeaders: ['Content-Type' => "multipart/form-data; boundary={$boundary}"],
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        $response = $this->hydrate(UploadResponse::class, $data);

        // Match the multipart path's progress contract: fire once at end. The
        // single-shot wire has no chunked granularity to report, but firing
        // here lets callers wire `onProgress` once and have it work for both
        // routes.
        $this->fireProgress($options, $size, $size);

        return $response;
    }

    /**
     * Fresh multipart upload.
     *
     * Three phases:
     *   1. POST /api/uploads/multipart/initiate with first 8 MiB as form-data.
     *      Server returns `upload_id`, `presigned_urls` for parts 2..N, the
     *      recommended chunk size, and the first-chunk etag (which it tracks
     *      server-side; the SDK does NOT submit part 1 in the complete call).
     *   2. PUT each remaining chunk to its presigned S3 URL with bounded retry +
     *      full-jitter backoff per part. When an injected
     *      {@see Http\MultipartPartUploader} is present the PUTs run
     *      concurrently, bounded by `multipartConcurrency` (z9bDW2iH); otherwise
     *      they run sequentially in part order. Either way the collected etags
     *      are assembled in ascending part order for /complete.
     *   3. POST /api/uploads/multipart/complete with the collected etags.
     *
     * Returns a synthesised {@see UploadResponse} so the public `uploadFile()`
     * surface is uniform across single-shot and multipart routes. The
     * multipart/complete wire response carries only `upload_id` + `status`;
     * the SDK pulls `mime_type` and `constraints_applied` from the initiate
     * response (the v2 contract makes `constraintsApplied` REQUIRED on
     * `UploadResponse` and the complete endpoint does not re-emit it).
     */
    private function multipartUpload(
        UploadSource $source,
        string $fileName,
        int $totalSize,
        ?UploadOptions $options,
    ): UploadResponse {
        $firstChunkSize = \min($totalSize, GislClientConfig::DEFAULT_MULTIPART_FIRST_CHUNK_SIZE_BYTES);

        $initiate = $this->multipartInitiate(
            source: $source,
            fileName: $fileName,
            totalSize: $totalSize,
            firstChunkSize: $firstChunkSize,
            metadataHint: $options?->metadataHint,
            contentType: $options?->contentType,
        );

        // Fail fast on a malformed initiate envelope — synthesising an
        // UploadResponse needs `constraints_applied` (the v2 contract makes
        // it REQUIRED on UploadResponse, and the multipart/complete endpoint
        // does NOT re-emit it). Catching this BEFORE the chunk uploads avoids
        // doing N PUTs + a complete call only to throw at synthesis time.
        $constraintsApplied = $initiate->getConstraintsApplied();
        if ($constraintsApplied === null) {
            throw new GislError(
                'Multipart initiate response missing constraints_applied; cannot synthesise UploadResponse.',
            );
        }

        $uploadedBytes = $firstChunkSize;
        $this->fireProgress($options, $uploadedBytes, $totalSize);

        $presignedUrls = $initiate->getPresignedUrls() ?? [];
        $chunkSize = $initiate->getRecommendedChunkSize();
        if ($chunkSize === null || $chunkSize < 1) {
            throw new GislError(
                "Multipart initiate response missing recommendedChunkSize; cannot route remaining bytes.",
            );
        }

        // S3 <=10 000-part ceiling guard (Model A). Mirrors
        // `packages/typescript/src/client.ts` multipartUpload. Trust the
        // server-computed total_parts (consistent with trusting
        // recommendedChunkSize/presignedUrls from the same envelope) but
        // assert the S3 physical ceiling, cross-checked against a client-side
        // recompute. NOTE (out of SDK-1 scope): the contract additionally
        // pins total_parts to maximum:500 and recommended_chunk_size to
        // <=100 MiB, i.e. a ~50 GB contract ceiling that sits below the
        // 50-100 GB Enterprise target — tracked as a separate contracts/API
        // follow-up; this guard stays at the S3-physical 10 000 per the card.
        $serverParts = $initiate->getTotalParts() ?? 0;
        $remainingBytes = \max(0, $totalSize - $firstChunkSize);
        $computedParts = 1 + (int) \ceil($remainingBytes / $chunkSize);
        $maxParts = 10000;
        if ($serverParts > $maxParts || $computedParts > $maxParts) {
            $required = \max($serverParts, $computedParts);
            throw new GislMultipartPartCountError(
                "Upload requires {$required} parts, exceeding the S3 {$maxParts}-part "
                . "multipart limit (server reported {$serverParts}, client computed "
                . "{$computedParts} at {$chunkSize}-byte chunks). A larger chunk size "
                . 'is required server-side to upload a file this large.',
                $required,
                $maxParts,
            );
        }

        // Plan-consistency guard (codex review; mirrors the TS reference).
        // The <=10k ceiling above only bounds the count; it does not catch an
        // initiate plan that is internally inconsistent BELOW the cap. Under
        // Model A a contract-compliant server computes total_parts from the
        // same recommended_chunk_size it returns and emits exactly one
        // presigned URL per remaining part (part 1 = initiate first chunk).
        // If they disagree, proceeding would PUT the wrong number of ranges
        // and fail opaquely at /multipart/complete — fail fast here instead.
        if ($serverParts !== $computedParts || \count($presignedUrls) !== $computedParts - 1) {
            throw new GislError(
                'Multipart initiate plan is internally inconsistent: server '
                . "total_parts={$serverParts}, client computed {$computedParts} "
                . "from {$chunkSize}-byte chunks, presigned_urls count="
                . \count($presignedUrls) . ' (expected ' . ($computedParts - 1)
                . '). Refusing to upload a mismatched part plan.',
            );
        }

        // Resolve uploadId before the chunk loop so a terminal part failure
        // can carry it on the typed GislMultipartPartError (mirrors the TS
        // reference, where the throw site has initResponse.uploadId in scope).
        $uploadId = $initiate->getUploadId();
        if (!\is_string($uploadId) || $uploadId === '') {
            throw new GislError('Multipart initiate response missing upload_id.');
        }

        // Build lazy part descriptors (offset/length index into the file — no
        // chunk is materialised until its PUT runs).
        /** @var list<array{partNumber: int, url: string, offset: int, length: int}> $descriptors */
        $descriptors = [];
        foreach ($presignedUrls as $index => $part) {
            if (!$part instanceof PresignedUrlPart) {
                throw new GislError("Multipart initiate response had a malformed presigned URL at index {$index}.");
            }
            $partNumber = $part->getPartNumber();
            if ($partNumber === null) {
                throw new GislError("Presigned URL at index {$index} missing part_number.");
            }
            $url = $part->getUrl();
            if (!\is_string($url) || $url === '') {
                throw new GislError("Presigned URL at index {$index} (part {$partNumber}) missing url.");
            }
            $start = $firstChunkSize + $index * $chunkSize;
            $end = \min($start + $chunkSize, $totalSize);
            $descriptors[] = ['partNumber' => $partNumber, 'url' => $url, 'offset' => $start, 'length' => $end - $start];
        }

        // Progress fires after EACH successful part (concurrent or sequential).
        $onPartComplete = function (int $partNumber, int $bytes) use (&$uploadedBytes, $options, $totalSize): void {
            $uploadedBytes += $bytes;
            $this->fireProgress($options, $uploadedBytes, $totalSize);
        };

        // z9bDW2iH: when a concurrent uploader is injected (production via
        // Gisl::create on ext-curl), PUT parts with bounded fan-out; otherwise
        // the sequential PSR-18 loop — the default, and the path the
        // StubPsr18Client parity suite captures. Both preserve the typed
        // errors + per-part progress. A stream source is NOT concurrency-safe
        // (one shared cursor), so it always takes the sequential loop even when
        // a concurrent uploader is present (VOxtu0RZ-B4).
        if ($this->partUploader !== null && $source->isConcurrencySafe()) {
            $etagByPart = $this->partUploader->uploadParts(
                $source->path(),
                $descriptors,
                $uploadId,
                $this->config->multipartConcurrency,
                $onPartComplete,
            );
        } else {
            /** @var array<int, string> $etagByPart */
            $etagByPart = [];
            foreach ($descriptors as $d) {
                $etag = $this->putChunkWithRetry(
                    partNumber: $d['partNumber'],
                    url: $d['url'],
                    source: $source,
                    offset: $d['offset'],
                    length: $d['length'],
                    uploadId: $uploadId,
                );
                $etagByPart[$d['partNumber']] = $etag;
                $onPartComplete($d['partNumber'], $d['length']);
            }
        }

        // Assemble the /multipart/complete part list in part_number-ascending
        // order — parts may finish out of order under concurrency, so sort by
        // key (the v2 contract orders parts ascending; ksort makes that
        // deterministic for both paths).
        \ksort($etagByPart);
        /** @var list<array{part_number: int, etag: string}> $parts */
        $parts = [];
        foreach ($etagByPart as $pn => $etag) {
            $parts[] = ['part_number' => $pn, 'etag' => $etag];
        }

        $completeBody = $this->jsonEncode([
            'upload_id' => $uploadId,
            'parts' => $parts,
        ]);
        $completeRequest = $this->buildRequest(
            method: 'POST',
            path: '/api/uploads/multipart/complete',
            body: $this->streamFactory->createStream($completeBody),
            extraHeaders: ['Content-Type' => 'application/json'],
        );

        /** @var array<string, mixed> $completeData */
        $completeData = $this->sendAndUnwrap($completeRequest);
        $completeStatus = $completeData['status'] ?? null;
        if ($completeStatus !== 'completed') {
            throw new GislError(
                'Multipart upload completed with unexpected status: '
                . (\is_string($completeStatus) ? $completeStatus : \get_debug_type($completeStatus)),
            );
        }

        // The complete response carries the authoritative upload_id (matches
        // TS reference: idempotency replays / server-side rebinds end up
        // here, not on the initiate envelope).
        $completeUploadId = $completeData['upload_id'] ?? null;
        if (!\is_string($completeUploadId) || $completeUploadId === '') {
            throw new GislError('Multipart complete response missing upload_id.');
        }

        // Synthesise an UploadResponse from the initiate + complete envelopes.
        // The complete response only carries upload_id+status, so mime_type
        // and constraints_applied are pulled from initiate (the first-chunk
        // probe is the authoritative source for those fields per the v2
        // contract — multipart/complete intentionally does NOT re-emit them).
        // `constraintsApplied` is non-null here: the early check above fails
        // fast before any chunk uploads.
        return $this->hydrate(UploadResponse::class, [
            'file_id' => $completeUploadId,
            'original_name' => $fileName,
            'mime_type' => $initiate->getMimeType(),
            'size_bytes' => $totalSize,
            'constraints_applied' => ObjectSerializer::sanitizeForSerialization($constraintsApplied),
        ]);
    }

    /**
     * SDK-3 (Wb6ebOMM): resume an in-progress multipart upload.
     *
     * Skips `/multipart/initiate` entirely (the original initiate happened in a
     * prior process). Walks `/status` for the authoritative list of recorded
     * parts, re-presigns the missing ones in batches of <=100, PUTs only those,
     * and finalises with `/complete`. Caller's file at `$filePath` MUST be
     * byte-identical to the original upload at the same offsets — parts whose
     * etags don't match server state will fail `/complete`.
     *
     * Re-runs the same uploadId / chunkSize / totalParts / plan-consistency
     * guards as the fresh-upload path, using the /status envelope. `onProgress`
     * fires on entry seeded from sum(uploadedParts.sizeBytes) and again after
     * every successful PUT. `onCheckpoint` fires OUTSIDE the retry-scoped path
     * after every successful PUT — a callback-throw must not trigger a
     * duplicate PUT. Mirrors `packages/typescript/src/client.ts:multipartResume`.
     *
     * TODO(HxUmVr3Y): replace inline hand-coded request body marshalling on
     * regen.
     */
    private function multipartResume(
        UploadSource $source,
        string $fileName,
        int $totalSize,
        string $resumeUploadId,
        ?UploadOptions $options,
    ): UploadResponse {
        // Step 1: Walk /status for authoritative session state.
        $status = $this->walkUploadStatus($resumeUploadId);
        $uploadId = $status['uploadId'];
        if ($uploadId !== $resumeUploadId) {
            throw new GislError(
                'multipartResume: /status response uploadId does not match resumeUploadId.',
            );
        }
        $chunkSize = $status['recommendedChunkSize'];
        // Mirrors the TS reference's MULTIPART_CHUNK_SIZE +
        // RECOMMENDED_CHUNK_SIZE_MAX_BYTES drift guards. Generator pins the
        // chunk-size enum (16 MiB) via UploadThresholds; the upper bound is
        // a contract-spec literal (the openapi `maximum:` on
        // recommended_chunk_size). Bumping either requires a contracts
        // regen that updates this constant.
        $minChunk = \Gisl\Generated\OpenApi\Model\UploadThresholds::MULTIPART_CHUNK_SIZE_NUMBER_16777216;
        $maxChunk = 104_857_600; // 100 MiB — api.yaml UploadThresholds recommended_chunk_size maximum
        if ($chunkSize < $minChunk || $chunkSize > $maxChunk) {
            throw new GislError(
                "multipartResume: /status recommendedChunkSize {$chunkSize} outside the contract "
                . "range [{$minChunk}, {$maxChunk}].",
            );
        }
        $totalParts = $status['totalParts'];
        if ($totalParts < 1) {
            throw new GislError(
                "multipartResume: /status totalParts={$totalParts} is invalid.",
            );
        }
        $maxParts = 10000;
        if ($totalParts > $maxParts) {
            throw new GislMultipartPartCountError(
                "multipartResume: /status totalParts={$totalParts} exceeds the S3 "
                . "{$maxParts}-part multipart limit.",
                $totalParts,
                $maxParts,
            );
        }
        // Wrong-file-for-this-uploadId guard.
        // Mirrors fresh-upload chunk-plan: part 1 = firstChunkSize (8 MiB),
        // parts 2..totalParts each take chunkSize bytes (last part may be
        // a short tail). See `multipartUpload` for the fresh side.
        $firstChunkSize = \min($totalSize, GislClientConfig::DEFAULT_MULTIPART_FIRST_CHUNK_SIZE_BYTES);
        $expectedMin = $firstChunkSize + \max(0, $totalParts - 2) * $chunkSize + ($totalParts > 1 ? 1 : 0);
        $expectedMax = $firstChunkSize + \max(0, $totalParts - 1) * $chunkSize;
        if ($totalSize < $expectedMin || $totalSize > $expectedMax) {
            throw new GislError(
                "multipartResume: caller file size ({$totalSize}) does not match the resumed "
                . "session's recorded plan (totalParts={$totalParts}, chunkSize={$chunkSize}, "
                . "expected {$expectedMin}-{$expectedMax} bytes). Wrong file for this uploadId?",
            );
        }

        // Step 2: Compute missing parts. Part 1 was uploaded inline at
        // initiate — if missing from /status the session is unrecoverable
        // (server rejects re-presigning part 1 to preserve the recorded
        // etag for /complete).
        /** @var array<int, array{partNumber:int,etag:string,sizeBytes:int,lastModified:string}> $uploaded */
        $uploaded = [];
        foreach ($status['uploadedParts'] as $p) {
            $uploaded[$p['partNumber']] = $p;
        }
        if (!isset($uploaded[1])) {
            throw new GislError(
                'multipartResume: part 1 (initiate first chunk) is missing from /status. '
                . 'Part 1 is sealed at initiate and cannot be re-presigned; this session is '
                . 'unrecoverable. Start a fresh upload (call uploadFile without resumeUploadId).',
            );
        }
        /** @var list<int> $missingParts */
        $missingParts = [];
        for ($n = 2; $n <= $totalParts; $n++) {
            if (!isset($uploaded[$n])) {
                $missingParts[] = $n;
            }
        }

        // Seed uploadedBytes from already-uploaded parts so onProgress
        // reflects the true resumption point.
        $uploadedBytes = 0;
        foreach ($uploaded as $p) {
            $uploadedBytes += $p['sizeBytes'];
        }
        $this->fireProgress($options, $uploadedBytes, $totalSize);

        // Parts PUT during THIS resume run but not yet folded into $uploaded.
        // Under the concurrent uploader, ETags are only known once the batch
        // returns, so the per-part completion callback records the partNumber
        // here and the checkpoint unions it — otherwise a checkpoint fired
        // mid-batch (out-of-order completion) would under-report progress.
        // Empty on the sequential path (it folds into $uploaded inline), where
        // the union is a harmless no-op. partNumber => true.
        /** @var array<int, true> $resumeCompleted */
        $resumeCompleted = [];

        $fireCheckpoint = function (?int $extraPartNumber = null) use (&$uploaded, &$resumeCompleted, $status, $options): void {
            // Union the part numbers via a keyed set (O(1) membership) rather
            // than in_array() over a growing list — a 10k-part resume would
            // otherwise do quadratic work per per-part callback.
            /** @var array<int, true> $set */
            $set = [];
            foreach (\array_keys($uploaded) as $pn) {
                $set[$pn] = true;
            }
            foreach (\array_keys($resumeCompleted) as $pn) {
                $set[$pn] = true;
            }
            if ($extraPartNumber !== null) {
                $set[$extraPartNumber] = true;
            }
            /** @var list<int> $all */
            $all = \array_keys($set);
            \sort($all);
            $state = new MultipartCheckpointState(
                uploadId: $status['uploadId'],
                totalParts: $status['totalParts'],
                uploadedPartNumbers: $all,
                manifestExpiresAt: $status['manifestExpiresAt'],
            );
            // OUTSIDE retry-scope. A throw here propagates and fails the
            // upload but cannot trigger a duplicate PUT.
            if (\is_callable($options?->onCheckpoint)) {
                ($options->onCheckpoint)($state);
            }
        };
        // Fire an entry checkpoint so callers can persist resumed state
        // even before any new PUT lands.
        $fireCheckpoint();

        // Step 3: Process batches of <=100 missing parts. Each batch is
        // presigned, then its chunks are PUT either concurrently (when a
        // bounded uploader is injected — Gisl::create on ext-curl) or
        // sequentially. Batches stay sequential so presigned-URL expiry is
        // bounded to one batch's worth of in-flight work.
        $batchSize = 100;
        for ($i = 0; $i < \count($missingParts); $i += $batchSize) {
            $batch = \array_slice($missingParts, $i, $batchSize);
            $presigned = $this->presignParts($uploadId, $batch, $totalParts);

            // Lazy descriptors for this batch. Offset math mirrors the fresh
            // upload: part 1 = firstChunkSize, parts 2..N =
            // firstChunkSize + (partNumber - 2) * chunkSize. Resume never PUTs
            // part 1 (unrecoverable check earlier).
            /** @var list<array{partNumber: int, url: string, offset: int, length: int}> $descriptors */
            $descriptors = [];
            /** @var array<int, int> $lengthByPart */
            $lengthByPart = [];
            foreach ($presigned['presignedUrls'] as $presignedPart) {
                $partNumber = $presignedPart['partNumber'];
                $start = $firstChunkSize + ($partNumber - 2) * $chunkSize;
                $end = \min($start + $chunkSize, $totalSize);
                $contentLength = $end - $start;
                $descriptors[] = [
                    'partNumber' => $partNumber,
                    'url' => $presignedPart['url'],
                    'offset' => $start,
                    'length' => $contentLength,
                ];
                $lengthByPart[$partNumber] = $contentLength;
            }

            if ($this->partUploader !== null) {
                // Concurrent PUTs. ETags arrive only when the batch returns, so
                // the per-part callback drives progress + checkpoint (recording
                // the completed partNumber in $resumeCompleted, which the
                // checkpoint unions), and the ETags are folded into $uploaded
                // afterwards in ascending order.
                $onPartComplete = function (int $partNumber, int $bytes) use (
                    &$uploadedBytes,
                    &$resumeCompleted,
                    $totalSize,
                    $options,
                    $fireCheckpoint,
                ): void {
                    $uploadedBytes = \min($uploadedBytes + $bytes, $totalSize);
                    $resumeCompleted[$partNumber] = true;
                    $this->fireProgress($options, $uploadedBytes, $totalSize);
                    $fireCheckpoint();
                };

                $etagByPart = $this->partUploader->uploadParts(
                    $source->path(),
                    $descriptors,
                    $uploadId,
                    $this->config->multipartConcurrency,
                    $onPartComplete,
                );

                \ksort($etagByPart);
                foreach ($etagByPart as $partNumber => $etag) {
                    $uploaded[$partNumber] = [
                        'partNumber' => $partNumber,
                        'etag' => $etag,
                        'sizeBytes' => $lengthByPart[$partNumber],
                        'lastModified' => \gmdate('Y-m-d\\TH:i:s\\Z'),
                    ];
                }
                // This batch is now in $uploaded; drop it from the pending set
                // so the checkpoint union stays bounded to one batch's parts
                // (it is otherwise unbounded across a many-batch resume).
                $resumeCompleted = [];
            } else {
                // Sequential PSR-18 path (direct construction / concurrency 1 /
                // no ext-curl). Read-once-retry-many per part.
                foreach ($descriptors as $d) {
                    $partNumber = $d['partNumber'];
                    $contentLength = $d['length'];

                    // Surface read failures as the typed GislMultipartPartError
                    // (mirrors fresh-path putChunkWithRetry pattern).
                    try {
                        $chunkBytes = $this->readChunk($source, $d['offset'], $contentLength);
                    } catch (GislError $e) {
                        throw new GislMultipartPartError(
                            "multipartResume: failed to read bytes for part {$partNumber}: " . $e->getMessage(),
                            $partNumber,
                            $uploadId,
                        );
                    }

                    $etag = $this->resumePutWithRetry(
                        url: $d['url'],
                        chunkBytes: $chunkBytes,
                        contentLength: $contentLength,
                        partNumber: $partNumber,
                        uploadId: $uploadId,
                    );

                    $uploaded[$partNumber] = [
                        'partNumber' => $partNumber,
                        'etag' => $etag,
                        'sizeBytes' => $contentLength,
                        'lastModified' => \gmdate('Y-m-d\\TH:i:s\\Z'),
                    ];
                    $uploadedBytes = \min($uploadedBytes + $contentLength, $totalSize);
                    $this->fireProgress($options, $uploadedBytes, $totalSize);
                    $fireCheckpoint();
                }
            }
        }

        // Step 4: /complete with the FULL parts list = recorded etags from
        // /status UNION newly-PUT etags. Sort ascending by part_number.
        /** @var list<array{part_number:int,etag:string}> $allParts */
        $allParts = [];
        foreach ($uploaded as $p) {
            $allParts[] = ['part_number' => $p['partNumber'], 'etag' => $p['etag']];
        }
        \usort($allParts, fn ($a, $b) => $a['part_number'] <=> $b['part_number']);
        if (\count($allParts) !== $totalParts) {
            throw new GislError(
                'multipartResume: assembled parts list has ' . \count($allParts)
                . " entries, expected {$totalParts}. Refusing to /complete with an incomplete part set.",
            );
        }

        $completeBody = $this->jsonEncode([
            'upload_id' => $uploadId,
            'parts' => $allParts,
        ]);
        $completeRequest = $this->buildRequest(
            method: 'POST',
            path: '/api/uploads/multipart/complete',
            body: $this->streamFactory->createStream($completeBody),
            extraHeaders: ['Content-Type' => 'application/json'],
        );
        /** @var array<string, mixed> $completeData */
        $completeData = $this->sendAndUnwrap($completeRequest);
        $completeStatus = $completeData['status'] ?? null;
        if ($completeStatus !== 'completed') {
            throw new GislError(
                'multipartResume: completed with unexpected status: '
                . (\is_string($completeStatus) ? $completeStatus : \get_debug_type($completeStatus)),
            );
        }
        $completeUploadId = $completeData['upload_id'] ?? null;
        if (!\is_string($completeUploadId) || $completeUploadId === '') {
            throw new GislError('multipartResume: complete response missing upload_id.');
        }

        // Resume-path information loss: /status (and /complete) carry
        // neither mime_type nor constraints_applied. Emit sentinel-shaped
        // values (mirrors TS reference); consumers needing authoritative
        // metadata SHOULD call `getMetadata($fileId)`.
        // TODO(HxUmVr3Y): once /status carries mime_type +
        // constraints_applied, replace these sentinels.
        return $this->hydrate(UploadResponse::class, [
            'file_id' => $completeUploadId,
            'original_name' => $fileName,
            'mime_type' => '',
            'size_bytes' => $totalSize,
            'constraints_applied' => [
                'max_size_bytes' => $totalSize,
                // `max_duration_seconds` deliberately omitted: parity
                // comparator filters undefined/missing keys; mirrors TS.
                'processing_class_pre_assignment' => 'unknown',
            ],
        ]);
    }

    /**
     * Resume-path sequential PUT loop — used when no concurrent uploader is
     * injected (direct construction / concurrency 1 / ext-curl absent). When an
     * uploader IS present the resume path PUTs each batch through it instead
     * (`7Vl01jFs`). Bounded retry + full-jitter backoff. Read-once-retry-many —
     * chunk bytes are captured by the caller and passed in.
     */
    private function resumePutWithRetry(
        string $url,
        string $chunkBytes,
        int $contentLength,
        int $partNumber,
        string $uploadId,
    ): string {
        $lastError = null;
        for ($attempt = 0; $attempt < $this->config->multipartMaxAttempts; $attempt++) {
            $request = $this->requestFactory
                ->createRequest('PUT', $url)
                ->withHeader('Content-Length', (string) $contentLength)
                ->withBody($this->streamFactory->createStream($chunkBytes));

            try {
                $response = $this->httpClient->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                $lastError = $e;
                $this->backoff($attempt);
                continue;
            }

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                $etag = $response->getHeaderLine('ETag');
                if ($etag === '') {
                    (string) $response->getBody();
                    throw new GislError(
                        "multipartResume: S3 response missing ETag for part {$partNumber}.",
                    );
                }
                return $etag;
            }
            (string) $response->getBody();
            if (!$this->isRetryableStatus($statusCode)) {
                throw new GislError(
                    "multipartResume: S3 chunk upload failed for part {$partNumber}: HTTP {$statusCode} (non-retryable).",
                );
            }
            $lastError = new GislError(
                "multipartResume: S3 chunk upload failed for part {$partNumber}: HTTP {$statusCode}.",
            );
            $this->backoff($attempt);
        }
        $detail = $lastError instanceof \Throwable ? $lastError->getMessage() : 'unknown error';
        throw new GislMultipartPartError(
            "multipartResume: S3 chunk upload failed for part {$partNumber} after "
            . "{$this->config->multipartMaxAttempts} attempts: {$detail}",
            $partNumber,
            $uploadId,
        );
    }

    // -------------------------------------------------------------------
    // SDK-3 (Wb6ebOMM) resume-support public endpoints. Mirrors
    // packages/typescript/src/client.ts getUploadStatus / presignParts /
    // keepaliveUpload.
    // -------------------------------------------------------------------

    /**
     * Fetch the durable status of an in-progress multipart upload session.
     *
     * Walks every page of `GET /api/uploads/multipart/{uploadId}/status`
     * (paginated via `next_part_number_marker` + `is_truncated`) and
     * returns the aggregated state. Callers see the complete set of
     * recorded parts across pages without driving the cursor themselves.
     *
     * Anonymous-initiated sessions return 403 -> `GislMultipartSessionAuthRequiredError`.
     * Non-existent / expired sessions return 404 -> `GislMultipartSessionNotFoundError`.
     * Authed-but-non-owning callers return 403 -> `GislMultipartSessionOwnershipError`.
     *
     * TODO(HxUmVr3Y): replace hand-coded response shape on regen.
     *
     * @return array{
     *     uploadId: string,
     *     multipartUploadId: string,
     *     cloudKey: string,
     *     totalParts: int,
     *     uploadedParts: list<array{partNumber:int,etag:string,sizeBytes:int,lastModified:string}>,
     *     manifestExpiresAt: string,
     *     recommendedChunkSize: int,
     * }
     */
    public function getUploadStatus(string $uploadId): array
    {
        if ($uploadId === '') {
            throw new GislError('getUploadStatus: uploadId must be a non-empty string.');
        }
        return $this->walkUploadStatus($uploadId);
    }

    /**
     * Re-presign a batch of missing part numbers on an in-progress
     * multipart session.
     *
     * Validates client-side BEFORE the HTTP round-trip:
     * - `$partNumbers` non-empty
     * - count <=100 (server raw-body cap is 8 KiB before json_decode)
     * - every entry an integer in `[2, totalParts]` — part 1 is sealed at
     *   initiate (re-presigning it would break the etag recorded
     *   server-side for /complete)
     * - entries unique
     * - `$totalParts` <=10 000 (S3 hard limit; mirrors SDK-1)
     *
     * TODO(HxUmVr3Y): replace hand-coded request/response shapes on regen.
     *
     * @param list<int> $partNumbers
     * @return array{
     *     uploadId: string,
     *     presignedUrls: list<array{partNumber:int,url:string,expiresAt:string}>,
     * }
     */
    public function presignParts(string $uploadId, array $partNumbers, int $totalParts): array
    {
        if ($uploadId === '') {
            throw new GislError('presignParts: uploadId must be a non-empty string.');
        }
        if ($totalParts < 1) {
            throw new GislError("presignParts: totalParts must be a positive integer, got {$totalParts}.");
        }
        if ($totalParts > 10000) {
            throw new GislMultipartPartCountError(
                "presignParts: totalParts={$totalParts} exceeds the S3 10000-part multipart limit. "
                . 'Refusing to re-presign on a session that cannot complete.',
                $totalParts,
                10000,
            );
        }
        if ($partNumbers === []) {
            throw new GislError('presignParts: partNumbers must be a non-empty array.');
        }
        if (\count($partNumbers) > 100) {
            throw new GislError(
                'presignParts: partNumbers has ' . \count($partNumbers)
                . ' entries — server caps batches at 100.',
            );
        }
        $seen = [];
        foreach ($partNumbers as $n) {
            if (!\is_int($n) || $n < 2 || $n > $totalParts) {
                throw new GislError(
                    "presignParts: partNumbers entry {$n} is not an integer in [2, {$totalParts}]. "
                    . 'Part 1 is sealed at initiate; re-presigning it would invalidate the recorded '
                    . 'etag for /complete.',
                );
            }
            if (isset($seen[$n])) {
                throw new GislError("presignParts: partNumbers contains duplicate {$n}.");
            }
            $seen[$n] = true;
        }
        $body = $this->jsonEncode(['part_numbers' => \array_values($partNumbers)]);
        $request = $this->buildRequest(
            method: 'POST',
            path: '/api/uploads/multipart/' . \rawurlencode($uploadId) . '/presign',
            body: $this->streamFactory->createStream($body),
            extraHeaders: ['Content-Type' => 'application/json'],
        );
        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);

        $resultUploadId = $data['upload_id'] ?? null;
        $presignedRaw = $data['presigned_urls'] ?? null;
        if (!\is_string($resultUploadId) || !\is_array($presignedRaw)) {
            throw new GislError('presignParts: malformed response envelope.');
        }
        /** @var list<array{partNumber:int,url:string,expiresAt:string}> $presigned */
        $presigned = [];
        foreach ($presignedRaw as $entry) {
            if (!\is_array($entry)) {
                throw new GislError('presignParts: malformed presigned_urls entry.');
            }
            $partNumber = $entry['part_number'] ?? null;
            $url = $entry['url'] ?? null;
            $expiresAt = $entry['expires_at'] ?? null;
            if (!\is_int($partNumber) || !\is_string($url) || !\is_string($expiresAt)) {
                throw new GislError('presignParts: malformed presigned_urls entry fields.');
            }
            // Reject an empty presigned URL at the source so it fails fast on
            // BOTH the sequential resume PUT (resumePutWithRetry) and the
            // concurrent paths — an empty URL would otherwise PUT to an empty
            // target, get retried as transient, and surface as
            // retry-exhaustion (GislMultipartPartError), masking the real
            // server-contract violation. Parity with the concurrent guard in
            // CurlMultiPartUploader::startPart.
            if ($url === '') {
                throw new GislError("presignParts: presigned URL for part {$partNumber} is empty.");
            }
            $presigned[] = [
                'partNumber' => $partNumber,
                'url' => $url,
                'expiresAt' => $expiresAt,
            ];
        }
        return [
            'uploadId' => $resultUploadId,
            'presignedUrls' => $presigned,
        ];
    }

    /**
     * Extend the manifest TTL of an in-progress multipart upload session.
     *
     * The durable session manifest defaults to a 48 h TTL (decoupled from
     * the shorter presigned-URL TTL). For a long-running resume that spans
     * days, callers SHOULD invoke `keepaliveUpload` every **12-24 h** while
     * resuming — the 12-24 h band leaves >=24 h of slack against the 48 h
     * ceiling even with worst-case clock skew between client and server.
     * The server atomically refreshes the Redis EXPIRE for the manifest
     * key; the call is idempotent.
     *
     * TODO(HxUmVr3Y): replace hand-coded response shape on regen.
     *
     * @return array{uploadId: string, manifestExpiresAt: string}
     */
    public function keepaliveUpload(string $uploadId): array
    {
        if ($uploadId === '') {
            throw new GislError('keepaliveUpload: uploadId must be a non-empty string.');
        }
        // Server expects an empty body; sending '{}' keeps Content-Type:
        // application/json symmetric with the other JSON-bodied POSTs.
        $request = $this->buildRequest(
            method: 'POST',
            path: '/api/uploads/multipart/' . \rawurlencode($uploadId) . '/keepalive',
            body: $this->streamFactory->createStream('{}'),
            extraHeaders: ['Content-Type' => 'application/json'],
        );
        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        $resultUploadId = $data['upload_id'] ?? null;
        $manifestExpiresAt = $data['manifest_expires_at'] ?? null;
        if (!\is_string($resultUploadId) || !\is_string($manifestExpiresAt)) {
            throw new GislError('keepaliveUpload: malformed response envelope.');
        }
        return [
            'uploadId' => $resultUploadId,
            'manifestExpiresAt' => $manifestExpiresAt,
        ];
    }

    /**
     * Private walk-pagination helper for /status. Aggregates every page
     * into a single result. Limit pinned to 1000 (max per page) so we
     * make the minimum number of round-trips even for the worst-case
     * ~10 pages on a 10 000-part upload.
     *
     * @return array{
     *     uploadId: string,
     *     multipartUploadId: string,
     *     cloudKey: string,
     *     totalParts: int,
     *     uploadedParts: list<array{partNumber:int,etag:string,sizeBytes:int,lastModified:string}>,
     *     manifestExpiresAt: string,
     *     recommendedChunkSize: int,
     * }
     */
    private function walkUploadStatus(string $uploadId): array
    {
        $pageLimit = 1000;
        // Slow-path DoS guard (code-reviewer minor 6). Mirrors TS reference.
        $maxPages = 50;
        /** @var list<array{partNumber:int,etag:string,sizeBytes:int,lastModified:string}> $collected */
        $collected = [];
        $cursor = 0;
        $totalParts = 0;
        $multipartUploadId = '';
        $cloudKey = '';
        $manifestExpiresAt = '';
        $recommendedChunkSize = 0;
        $pageCount = 0;

        while (true) {
            if ($pageCount >= $maxPages) {
                throw new GislError(
                    "getUploadStatus: server returned more than {$maxPages} pages — refusing to continue.",
                );
            }
            $pageCount++;
            $query = "?cursor={$cursor}&limit={$pageLimit}";
            $path = '/api/uploads/multipart/' . \rawurlencode($uploadId) . '/status' . $query;
            $request = $this->buildRequest(method: 'GET', path: $path);
            /** @var array<string, mixed> $page */
            $page = $this->sendAndUnwrap($request);

            $pageTotalParts = $page['total_parts'] ?? null;
            if (!\is_int($pageTotalParts) || $pageTotalParts < 1) {
                throw new GislError('getUploadStatus: server page missing or invalid total_parts.');
            }
            $totalParts = $pageTotalParts;
            // Strict validation discipline (code-reviewer P7): use null-default
            // so the type-check catches a wire envelope that OMITS a required
            // field, instead of silently coercing a missing key to '' / 0 and
            // flowing it into MultipartCheckpointState.manifestExpiresAt.
            $pageUploadId = $page['upload_id'] ?? null;
            $pageMultipartUploadId = $page['multipart_upload_id'] ?? null;
            $pageCloudKey = $page['cloud_key'] ?? null;
            $pageManifestExpiresAt = $page['manifest_expires_at'] ?? null;
            $pageRecommendedChunkSize = $page['recommended_chunk_size'] ?? null;
            if (
                !\is_string($pageUploadId) || $pageUploadId !== $uploadId
                || !\is_string($pageMultipartUploadId) || $pageMultipartUploadId === ''
                || !\is_string($pageCloudKey) || $pageCloudKey === ''
                || !\is_string($pageManifestExpiresAt) || $pageManifestExpiresAt === ''
                || !\is_int($pageRecommendedChunkSize)
            ) {
                throw new GislError(
                    'getUploadStatus: server page missing required fields or returned a '
                    . "mismatching upload_id (expected {$uploadId}, got " . \var_export($pageUploadId, true) . ').',
                );
            }
            $multipartUploadId = $pageMultipartUploadId;
            $cloudKey = $pageCloudKey;
            $manifestExpiresAt = $pageManifestExpiresAt;
            $recommendedChunkSize = $pageRecommendedChunkSize;

            $uploadedParts = $page['uploaded_parts'] ?? [];
            if (!\is_array($uploadedParts)) {
                throw new GislError('getUploadStatus: server page uploaded_parts not an array.');
            }
            foreach ($uploadedParts as $p) {
                if (!\is_array($p)) {
                    throw new GislError('getUploadStatus: malformed uploaded_parts entry.');
                }
                $pn = $p['part_number'] ?? null;
                $et = $p['etag'] ?? null;
                $sb = $p['size_bytes'] ?? null;
                $lm = $p['last_modified'] ?? null;
                if (!\is_int($pn) || !\is_string($et) || !\is_int($sb) || !\is_string($lm)) {
                    throw new GislError('getUploadStatus: malformed uploaded_parts entry fields.');
                }
                $collected[] = [
                    'partNumber' => $pn,
                    'etag' => $et,
                    'sizeBytes' => $sb,
                    'lastModified' => $lm,
                ];
            }

            $isTruncated = $page['is_truncated'] ?? false;
            if ($isTruncated !== true) {
                break;
            }
            $nextMarker = $page['next_part_number_marker'] ?? null;
            if (!\is_int($nextMarker) || $nextMarker <= $cursor) {
                throw new GislError(
                    'getUploadStatus: server is_truncated=true but next_part_number_marker '
                    . "did not advance (was {$cursor}, got " . \var_export($nextMarker, true) . ').',
                );
            }
            $cursor = $nextMarker;
        }

        \usort($collected, fn ($a, $b) => $a['partNumber'] <=> $b['partNumber']);

        return [
            'uploadId' => $uploadId,
            'multipartUploadId' => $multipartUploadId,
            'cloudKey' => $cloudKey,
            'totalParts' => $totalParts,
            'uploadedParts' => $collected,
            'manifestExpiresAt' => $manifestExpiresAt,
            'recommendedChunkSize' => $recommendedChunkSize,
        ];
    }

    private function multipartInitiate(
        UploadSource $source,
        string $fileName,
        int $totalSize,
        int $firstChunkSize,
        ?\Gisl\Generated\OpenApi\Model\MultipartInitiateRequestMetadataHint $metadataHint,
        ?string $contentType = null,
    ): MultipartInitiateResponse {
        $boundary = $this->generateMultipartBoundary();
        $body = $this->buildMultipartInitiateBody(
            boundary: $boundary,
            source: $source,
            fileName: $fileName,
            firstChunkSize: $firstChunkSize,
            totalSize: $totalSize,
            metadataHint: $metadataHint,
            contentType: $contentType,
        );

        $request = $this->buildRequest(
            method: 'POST',
            path: '/api/uploads/multipart/initiate',
            body: $body,
            extraHeaders: ['Content-Type' => "multipart/form-data; boundary={$boundary}"],
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        return $this->hydrate(MultipartInitiateResponse::class, $data);
    }

    /**
     * PUT a chunk to S3 with bounded retry and full-jitter backoff.
     *
     * Returns the ETag header value on success; throws on terminal failure or
     * after `multipartMaxAttempts` retryable failures. Mirrors the TS
     * `attemptPut` + per-part retry loop, minus the cross-worker abort
     * controller (sequential workers don't have peers to wake).
     *
     * The chunk bytes are read from disk ONCE before the retry loop and held
     * in memory across attempts. The TS reference holds an immutable
     * `Blob.slice()` view across retries; the PHP equivalent is reading the
     * bytes once and rewrapping in a fresh PSR-7 stream per attempt. Costs
     * one chunk-sized buffer (~5-50 MiB) but avoids re-reading from disk on
     * every transient S3 failure.
     */
    private function putChunkWithRetry(
        int $partNumber,
        string $url,
        UploadSource $source,
        int $offset,
        int $length,
        string $uploadId,
    ): string {
        // Surface a read failure for THIS part as the typed
        // GislMultipartPartError (partNumber + uploadId), consistent with the
        // PUT-failure path below — a bare GislError from readChunk (short
        // read / seek failure) would otherwise lose the per-part context
        // (codex review; mirrors the TS reference).
        try {
            $chunkBytes = $this->readChunk($source, $offset, $length);
        } catch (GislError $e) {
            throw new GislMultipartPartError(
                "Failed to read bytes for part {$partNumber}: " . $e->getMessage(),
                $partNumber,
                $uploadId,
            );
        }

        $lastError = null;
        for ($attempt = 0; $attempt < $this->config->multipartMaxAttempts; $attempt++) {
            $request = $this->requestFactory
                ->createRequest('PUT', $url)
                ->withHeader('Content-Length', (string) $length)
                ->withBody($this->streamFactory->createStream($chunkBytes));

            try {
                $response = $this->httpClient->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                // Treat transport failures as retryable — the TS reference
                // distinguishes ECONNRESET / ECONNREFUSED / ETIMEDOUT here,
                // but PSR-18 collapses everything into ClientExceptionInterface
                // with no portable subtype. Bounded retry already caps the
                // damage from a permanent transport failure.
                $lastError = $e;
                $this->backoff($attempt);
                continue;
            }

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                $etag = $response->getHeaderLine('ETag');
                if ($etag === '') {
                    // Drain the body before throwing — keeps the transport
                    // connection released eagerly, matching the TS reference's
                    // explicit drain on this same fatal path.
                    (string) $response->getBody();
                    throw new GislError(
                        "S3 response missing ETag for part {$partNumber}.",
                    );
                }
                return $etag;
            }

            // Non-2xx — drain the body so the connection can be released by
            // the underlying transport, then decide retry vs fatal.
            (string) $response->getBody();

            if (!$this->isRetryableStatus($statusCode)) {
                throw new GislError(
                    "S3 chunk upload failed for part {$partNumber}: HTTP {$statusCode} (non-retryable).",
                );
            }

            $lastError = new GislError(
                "S3 chunk upload failed for part {$partNumber}: HTTP {$statusCode}.",
            );
            $this->backoff($attempt);
        }

        $detail = $lastError instanceof \Throwable ? $lastError->getMessage() : 'unknown error';
        // Mirrors the TS reference: only the after-all-attempts throw is the
        // typed GislMultipartPartError; the non-retryable mid-loop throw above
        // stays a plain GislError (matches the TS `{kind:'fatal'}` path).
        throw new GislMultipartPartError(
            "S3 chunk upload failed for part {$partNumber} after {$this->config->multipartMaxAttempts} attempts: {$detail}",
            $partNumber,
            $uploadId,
        );
    }

    private function isRetryableStatus(int $status): bool
    {
        // S3-PUT chunk-upload retry predicate. Deliberately kept INDEPENDENT of
        // RateLimitHeaders::isApiRetryableStatus (the GislApiError back-off
        // accessor) so a change to one cannot silently alter the other — this
        // preserves the pre-existing S3 upload behaviour byte-for-byte.
        // 408 (request timeout) is retried here and, since qz7MjNTy, in the TS
        // SDK's S3-PUT predicate too — the two SDKs are now aligned on this set.
        return $status === 408 || $status === 429 || ($status >= 500 && $status < 600);
    }

    /**
     * Cap on the per-retry sleep window. 30 s matches what most well-behaved
     * SDKs (AWS SDK, etc.) use as an upper bound — beyond this, repeated
     * retries are no longer "back off and retry" but "block the worker for
     * minutes," which is rarely what anyone wants. Also defends against
     * pathological config (high attempts × high base) producing minutes-long
     * sleeps OR int-overflow on `1 << attempt` for absurd attempt counts.
     */
    private const BACKOFF_CAP_MS = 30_000;

    private function backoff(int $attempt): void
    {
        if ($attempt + 1 >= $this->config->multipartMaxAttempts) {
            return;
        }
        $base = $this->config->multipartRetryBaseMs;
        if ($base <= 0) {
            return;
        }
        // Full-jitter: random_int(0, min(base * 2^attempt, cap)). Mirrors
        // fullJitterDelay at packages/typescript/src/client.ts but with an
        // explicit ceiling.  `1 << $attempt` is bounded by clamping the
        // exponent so we never produce a negative cap on 32-bit PHP nor a
        // multi-minute sleep on 64-bit.
        $shift = \max(0, \min($attempt, 30));
        $cap = \max(1, \min($base * (1 << $shift), self::BACKOFF_CAP_MS));
        $delayMs = \random_int(0, $cap);
        \usleep($delayMs * 1000);
    }

    private function readChunk(UploadSource $source, int $offset, int $length): string
    {
        // Byte reads funnel through UploadSource so the engine is agnostic to a
        // path vs a seekable stream (VOxtu0RZ-B4). A path source reopens a fresh
        // handle per read (concurrency-safe); a stream source seeks its held
        // handle (sequential-only, gated upstream).
        return $source->readRange($offset, $length);
    }

    private function fireProgress(?UploadOptions $options, int $uploaded, int $total): void
    {
        $cb = $options?->onProgress;
        if ($cb === null) {
            return;
        }
        if (!\is_callable($cb)) {
            throw new GislConfigError(
                'UploadOptions::$onProgress must be callable when set.',
            );
        }
        $cb($uploaded, $total);
    }

    public function createWorkflow(WorkflowCreatePayload $payload): WorkflowCreateResponse
    {
        $jsonBody = $this->jsonEncode($payload->toWire());
        $request = $this->buildRequest(
            method: 'POST',
            path: '/api/workflows',
            body: $this->streamFactory->createStream($jsonBody),
            extraHeaders: ['Content-Type' => 'application/json'],
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        return $this->hydrate(WorkflowCreateResponse::class, $data);
    }

    /**
     * Get current workflow status.
     *
     * For an anonymous (null-owner) workflow, pass `$capability` (the `cap`
     * from the anonymous workflow-create response) so a session-less caller can
     * read it — the SDK sends it as the `X-Workflow-Capability` header. Pass
     * null for authenticated reads. A wrong/missing cap on a null-owner
     * workflow is a 404.
     */
    public function getWorkflowStatus(
        string $workflowId,
        ?string $capability = null,
    ): WorkflowStatusResponse {
        // rawurlencode the path segment — workflow IDs come from the server
        // as ULID/UUID-shaped strings, but the SDK can't assume that, and a
        // workflowId containing `/`, `?`, `#`, or unicode would otherwise
        // alter the requested route silently.
        $encoded = \rawurlencode($workflowId);
        $request = $this->buildRequest(
            method: 'GET',
            path: "/api/workflows/{$encoded}/status",
            extraHeaders: $this->workflowCapabilityHeaders($capability),
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        return $this->hydrate(WorkflowStatusResponse::class, $data);
    }

    /**
     * List the caller's workflows — a cursor-paginated, user-scoped summary
     * list, most-recent-first. Each row is a lightweight {@see WorkflowSummary}
     * (id / status / created_at + per-job type+status + a deliverable-output
     * count); it does NOT inline per-op `result_metadata` or output details —
     * drill in via {@see getWorkflowStatus()} / {@see getWorkflowDownloads()}.
     *
     * Auth is REQUIRED (the list is user-scoped; an anonymous caller gets a
     * 401 → {@see \Gisl\Sdk\Errors\GislAuthError}). Walk pages by passing each
     * response's `nextCursor` as the next call's `$cursor` until `isTruncated`
     * is false, or use {@see workflows()} to auto-paginate. Mirrors
     * `packages/typescript/src/client.ts::listWorkflows`.
     *
     * @param string|null $cursor Opaque cursor from a prior page's `nextCursor`
     *                            (omit for the first page — treat as opaque).
     * @param int|null    $limit  Rows per page (1-100; server default 20).
     */
    public function listWorkflows(?string $cursor = null, ?int $limit = null): WorkflowListResponse
    {
        $params = [];
        if ($cursor !== null && $cursor !== '') {
            $params['cursor'] = $cursor;
        }
        if ($limit !== null) {
            $params['limit'] = (string) $limit;
        }
        $query = $params === [] ? '' : '?' . \http_build_query($params);

        $request = $this->buildRequest(
            method: 'GET',
            path: '/api/workflows' . $query,
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        return $this->hydrate(WorkflowListResponse::class, $data);
    }

    /**
     * Auto-paginating iterator over ALL of the caller's workflows, yielding
     * each {@see WorkflowSummary} most-recent-first across page boundaries —
     * the ergonomic companion to {@see listWorkflows()}. Walks `nextCursor`
     * until the server reports `isTruncated: false`. Mirrors the TS
     * `workflows()` async generator.
     *
     * @param int|null $limit Page-size hint passed to each underlying request.
     * @return \Generator<int, WorkflowSummary>
     */
    public function workflows(?int $limit = null): \Generator
    {
        $cursor = null;
        for (;;) {
            $page = $this->listWorkflows($cursor, $limit);
            foreach ($page->getWorkflows() ?? [] as $summary) {
                yield $summary;
            }
            $next = $page->getNextCursor();
            // Stop on a final page, an empty cursor, OR a non-advancing cursor
            // — a server that repeats the same cursor on a truncated page would
            // otherwise loop forever, refetching + re-yielding the same rows.
            if ($page->getIsTruncated() !== true || $next === null || $next === '' || $next === $cursor) {
                return;
            }
            $cursor = $next;
        }
    }

    /**
     * Fetch download URLs for a completed workflow. Mirrors TS
     * `getWorkflowDownloads(workflowId)` at packages/typescript/src/client.ts.
     *
     * The response carries one or more `JobDownload` entries — each job in
     * the workflow that produced output exposes its files (and optionally a
     * combined zip URL). Helper streaming-to-disk is left to the caller; PSR-7
     * `ResponseInterface::getBody()` already returns a streaming body, and
     * adding a `downloadResultTo($path)` helper overlaps too much with
     * existing PSR-7 idioms — readers can call `copy('php://memory', $stream)`
     * or write their own loop.
     *
     * For an anonymous (null-owner) workflow, pass `$capability` (the `cap`
     * from the anonymous workflow-create response) so a session-less caller can
     * read it — sent as the `X-Workflow-Capability` header. Pass null for
     * authenticated reads. A wrong/missing cap on a null-owner workflow is a 404.
     *
     * @param ?string $capability Anonymous-workflow capability token.
     */
    public function getWorkflowDownloads(
        string $workflowId,
        ?string $capability = null,
    ): WorkflowDownloadResponse {
        $encoded = \rawurlencode($workflowId);
        $request = $this->buildRequest(
            method: 'GET',
            path: "/api/workflows/{$encoded}/downloads",
            extraHeaders: $this->workflowCapabilityHeaders($capability),
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        return $this->hydrate(WorkflowDownloadResponse::class, $data);
    }

    /**
     * Stream Server-Sent Events for a workflow, yielding one
     * {@see GislSseEvent} per parsed frame.
     *
     * Mirrors `streamEvents()` at `packages/typescript/src/client.ts:1104`
     * and the SSE parser at `packages/typescript/src/sse.ts`. Wire details:
     *
     *   - `GET /api/workflows/{rawurlencode($workflowId)}/events`
     *   - The connection is held open by the server; the generator
     *     yields events as they arrive. The connection closes when the
     *     server ends the stream OR the caller `break`s out of the
     *     foreach (PHP destructs the generator and releases the body).
     *   - Comment lines (`:` prefix) are skipped — keep-alives.
     *   - `id:` and `retry:` are explicitly IGNORED (NOT surfaced on
     *     {@see GislSseEvent}). The SDK does not implement
     *     Last-Event-ID reconnection nor honour server-suggested retry
     *     intervals.
     *   - Each frame's `data:` body is JSON-decoded as an associative
     *     array. Frames whose body fails to parse are SKIPPED — the
     *     stream does NOT throw mid-flight on a single garbled frame,
     *     because long-running consumers should stay up across
     *     transient server hiccups. (TS reference falls back to a
     *     plain string; PHP rejects entirely so the typed `data`
     *     contract holds.)
     *
     * Non-2xx responses are routed through {@see unwrapEnvelope} so
     * the typed-error dispatch (auth, balance, validation, …) shipped
     * in B2.1 surfaces the same exceptions for SSE as for JSON
     * endpoints.
     *
     * For an anonymous (null-owner) workflow, pass `$capability` (the `cap`
     * from the anonymous workflow-create response) so a session-less caller
     * can read it — sent as the `X-Workflow-Capability` header. Pass null for
     * authenticated reads. A wrong/missing cap on a null-owner workflow is a 404.
     *
     * `$onParseError` (optional, TYNjcjpo): observe malformed-JSON frames. A
     * frame whose `data:` body fails to decode is SKIPPED (the stream stays
     * resilient) and, when this callback is supplied, reported to it as a typed
     * {@see GislSseParseFailure} instead of being silently dropped. Omit it to
     * keep the historical silent-drop default (TS parity). A callback that
     * throws PROPAGATES out of the generator (matching the `onProgress`-throw
     * convention) — do not throw from it unless you intend to abort the stream.
     *
     * @param ?string $capability Anonymous-workflow capability token.
     * @param ?callable(GislSseParseFailure):void $onParseError Malformed-frame observer.
     * @return \Generator<int, GislSseEvent, void, void>
     * @throws GislNetworkError on transport failure.
     * @throws GislApiError     on a non-2xx envelope (typed subclass
     *                          where the dispatch matches).
     */
    public function streamEvents(
        string $workflowId,
        ?string $capability = null,
        ?callable $onParseError = null,
    ): \Generator {
        $encoded = \rawurlencode($workflowId);
        $request = $this->buildRequest(
            method: 'GET',
            path: "/api/workflows/{$encoded}/events",
            extraHeaders: $this->workflowCapabilityHeaders($capability),
        );

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new GislNetworkError(
                "HTTP transport failed: {$e->getMessage()}",
                $e,
            );
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            // Route through the JSON envelope dispatcher — let the
            // typed-error tree (GislAuthError, GislBalanceExhaustedError,
            // …) throw. unwrapEnvelope always throws on non-success here:
            // a non-2xx with a `success: true` envelope is malformed and
            // the early-empty-body path also throws. Either way we never
            // return a plain "data" payload for a non-2xx wire response.
            $this->unwrapEnvelope($response);
            // Defense-in-depth — should be unreachable.
            throw new GislError(
                "SSE stream returned status {$statusCode} with a success envelope.",
            );
        }

        return $this->parseSseStream($response->getBody(), $onParseError);
    }

    /**
     * Generator-internal SSE parser. Reads the PSR-7 body in chunks,
     * splits on \n / \r\n / \r per the SSE spec, accumulates `data:`
     * lines into one frame, joins multiple `data:` lines with `\n`,
     * JSON-decodes on blank-line boundaries, and yields a
     * {@see GislSseEvent} per successfully-parsed frame.
     *
     * @param ?callable(GislSseParseFailure):void $onParseError Malformed-frame observer (TYNjcjpo).
     * @return \Generator<int, GislSseEvent, void, void>
     */
    private function parseSseStream(
        \Psr\Http\Message\StreamInterface $body,
        ?callable $onParseError = null,
    ): \Generator {
        $buffer = '';
        $eventType = '';
        /** @var list<string> $dataLines */
        $dataLines = [];

        while (!$body->eof()) {
            try {
                $chunk = $body->read(self::SSE_READ_CHUNK_BYTES);
            } catch (\RuntimeException $e) {
                // TDqmkWpX: a mid-stream transport read failure (PSR-7 read()
                // throws \RuntimeException on failure) must surface as a typed
                // GislNetworkError so BuilderInternals::awaitTerminal poll-falls-
                // back on it — it polls ONLY on GislNetworkError /
                // SseStreamEndedWithoutTerminal, so a raw \RuntimeException here
                // would bypass the intended genuine-transport-error fallback.
                throw new GislNetworkError(
                    "SSE stream read failed mid-stream: {$e->getMessage()}",
                );
            }
            if ($chunk === '') {
                // Some PSR-7 streams return '' before EOF on slow
                // network reads. Don't busy-loop: if we're not at EOF,
                // the next read() attempt should block until bytes
                // arrive. If we ARE at EOF the while() exits cleanly.
                continue;
            }
            $buffer .= $chunk;

            // SSE spec: lines terminated by \n, \r\n, or \r. Normalise
            // so a single split produces canonical lines.
            $buffer = \str_replace(["\r\n", "\r"], "\n", $buffer);
            $lines = \explode("\n", $buffer);
            // Last entry is potentially incomplete (no terminator yet);
            // hold it back into the buffer for the next read.
            $buffer = (string) \array_pop($lines);

            foreach ($lines as $line) {
                if ($line === '') {
                    // Blank line = frame terminator. Flush.
                    $event = $this->flushSseFrame($eventType, $dataLines, $onParseError);
                    $eventType = '';
                    $dataLines = [];
                    if ($event !== null) {
                        yield $event;
                    }
                    continue;
                }

                if ($line[0] === ':') {
                    // Comment / keep-alive. Skip.
                    continue;
                }

                $colonIndex = \strpos($line, ':');
                if ($colonIndex === false) {
                    // Field with no value — SSE spec treats the whole
                    // line as the field name with empty value. Only
                    // `event` / `data` matter to us; both are useless
                    // empty, so drop the line.
                    continue;
                }

                $field = \substr($line, 0, $colonIndex);
                // Strip a single optional space after the colon per the SSE spec.
                $valueStart = ($colonIndex + 1 < \strlen($line) && $line[$colonIndex + 1] === ' ')
                    ? $colonIndex + 2
                    : $colonIndex + 1;
                $fieldValue = \substr($line, $valueStart);

                switch ($field) {
                    case 'event':
                        $eventType = $fieldValue;
                        break;
                    case 'data':
                        $dataLines[] = $fieldValue;
                        break;
                    case 'id':
                    case 'retry':
                        // Intentionally ignored — see GislSseEvent
                        // class docblock for rationale.
                        break;
                    default:
                        // Unknown field — drop per SSE spec.
                        break;
                }
            }
        }

        // Flush any final frame at stream end (server closed without a
        // trailing blank line).
        if ($dataLines !== []) {
            $event = $this->flushSseFrame($eventType, $dataLines, $onParseError);
            if ($event !== null) {
                yield $event;
            }
        }
    }

    /**
     * Read-chunk size for SSE. 8 KiB is large enough that typical
     * progress / status frames (<1 KiB) come back in one read, while
     * still being small enough that the buffer never grows unbounded
     * if the server keeps the connection open for hours.
     */
    private const SSE_READ_CHUNK_BYTES = 8192;

    /**
     * Build one {@see GislSseEvent} from the accumulated frame state
     * or return null if the frame should be dropped (no data lines, or
     * malformed JSON).
     *
     * On a malformed-JSON frame (TYNjcjpo) the frame is dropped and, when
     * `$onParseError` is supplied, a typed {@see GislSseParseFailure} is
     * passed to it before returning null. A callback that throws propagates
     * (it is not caught here) — matching the `onProgress`-throw convention.
     *
     * @param list<string> $dataLines
     * @param ?callable(GislSseParseFailure):void $onParseError Malformed-frame observer.
     */
    private function flushSseFrame(
        string $eventType,
        array $dataLines,
        ?callable $onParseError = null,
    ): ?GislSseEvent {
        if ($dataLines === []) {
            return null;
        }
        $rawData = \implode("\n", $dataLines);
        try {
            /** @var mixed $decoded */
            $decoded = \json_decode($rawData, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Skip malformed frames. Long-running consumers must not
            // break on a single garbled payload — server-side glitches
            // / partial flushes happen in the wild.
            if (\defined('PHP_DEBUG') && PHP_DEBUG) {
                \error_log(
                    "GISL SDK: dropping SSE frame with non-JSON data: {$e->getMessage()}",
                );
            }
            // TYNjcjpo: surface the drop as a typed, non-throwing diagnostic
            // when the caller opted in. Mirrors the TS `onParseError` seam.
            if ($onParseError !== null) {
                $onParseError(new GislSseParseFailure(
                    raw: $rawData,
                    event: $eventType !== '' ? $eventType : 'message',
                    error: $e->getMessage(),
                ));
            }
            return null;
        }

        // Per SSE spec: an event with no `event:` field defaults to
        // "message". Mirrors the TS reference at sse.ts:50.
        $name = $eventType !== '' ? $eventType : 'message';
        return new GislSseEvent(event: $name, data: $decoded);
    }

    /**
     * Cancel a workflow. Idempotent — cancelling an already-cancelled
     * workflow returns 200 with the same shape (and the original
     * `cancelledAt`). Cancelling a `completed` / `failed` /
     * `partially_failed` / `expired` workflow returns 409, which the SDK
     * surfaces as a generic {@see GislApiError} (NOT a special class) so
     * callers can branch on `$e->statusCode === 409` if needed.
     *
     * The response's `billingEffect` field tells the caller what happened to
     * outstanding reservations:
     *  - `unspent_reservation_released` — the unspent portion of the
     *    reservation has been refunded as a separate `CreditTransaction`
     *    with `type: refund`.
     *  - `none` — no refund (all reserved credits were already consumed by
     *    completed jobs, or this is an idempotent re-cancel).
     *
     * Mirrors `packages/typescript/src/client.ts::cancelWorkflow`.
     */
    public function cancelWorkflow(string $workflowId): WorkflowCancelResponse
    {
        $encoded = \rawurlencode($workflowId);
        $request = $this->buildRequest(
            method: 'POST',
            path: "/api/workflows/{$encoded}/cancel",
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        return $this->hydrate(WorkflowCancelResponse::class, $data);
    }

    /**
     * Resume a workflow that is in `paused_insufficient_credits`.
     *
     * Resume succeeds only when `availableCredits` covers the next
     * reservation. If the balance is still insufficient, a
     * {@see GislBalanceExhaustedError} (402) surfaces and the workflow stays
     * paused. If the workflow is past its `expiresAt` (default 7-day TTL
     * from `pausedAt`), {@see GislWorkflowExpiredError} (422) surfaces and
     * the workflow has transitioned to `expired` — callers cannot un-expire
     * a workflow. Resuming a workflow that is not in
     * `paused_insufficient_credits` is a 409 (no-op) and surfaces as a
     * generic {@see GislApiError}.
     *
     * Mirrors `packages/typescript/src/client.ts::resumeWorkflow`.
     */
    public function resumeWorkflow(string $workflowId): WorkflowResumeResponse
    {
        $encoded = \rawurlencode($workflowId);
        $request = $this->buildRequest(
            method: 'POST',
            path: "/api/workflows/{$encoded}/resume",
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        return $this->hydrate(WorkflowResumeResponse::class, $data);
    }

    /**
     * Retry a single failed operation. Note: keyed on **operationId**, not
     * workflowId — the contract pins this to `POST /api/operations/{id}/retry`.
     *
     * Returns the new operation's id (and the original id for traceability).
     * Returns 409 when the operation is not failed or has already been
     * retried; the SDK surfaces 409 as a generic {@see GislApiError} so
     * callers can decide whether to ignore or surface the conflict.
     *
     * Mirrors `packages/typescript/src/client.ts::retryOperation`.
     */
    public function retryOperation(string $operationId): RetryResponse
    {
        $encoded = \rawurlencode($operationId);
        $request = $this->buildRequest(
            method: 'POST',
            path: "/api/operations/{$encoded}/retry",
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        return $this->hydrate(RetryResponse::class, $data);
    }

    /**
     * Get full metadata for an uploaded file. The shape varies by mime-type
     * family: image uploads carry `dimensions` + EXIF; documents carry
     * page-count fields; etc. The DTO exposes every nullable field — callers
     * read the ones relevant to their workflow.
     *
     * Mirrors `packages/typescript/src/client.ts::getMetadata`.
     */
    public function getMetadata(string $fileId): MetadataResponse
    {
        $encoded = \rawurlencode($fileId);
        $request = $this->buildRequest(
            method: 'GET',
            path: "/api/uploads/{$encoded}/metadata",
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        return $this->hydrate(MetadataResponse::class, $data);
    }

    /**
     * Poll {@see getWorkflowStatus()} until the workflow reaches a terminal
     * status (completed / failed / partially_failed / cancelled / expired /
     * paused_insufficient_credits — see {@see WorkflowConstants::TERMINAL_STATUSES}).
     *
     * Pass a {@see WaitOptions} to override the default 2 s poll interval
     * and 5 min overall deadline, and to register an `onPoll` callback that
     * fires once per polling cycle (including the very first status fetch)
     * with the current status string.
     *
     * Throws {@see GislTimeoutError} when the next interval would push the
     * total elapsed past `timeoutMs`. The deadline is tracked in nanoseconds
     * via `hrtime(true)` for monotonic-clock semantics (robust to NTP
     * adjustments and DST shifts) and sub-ms precision (lets test cases
     * with `timeoutMs: 0` trip after any time has passed instead of
     * looping forever on integer-ms truncation).
     *
     * Mirrors `packages/typescript/src/client.ts::waitForWorkflow`. Note
     * that PHP's `usleep` is a real-time sleep — tests should pass
     * `intervalMs: 0` (and a small `timeoutMs`) to keep the suite fast.
     */
    public function waitForWorkflow(
        string $workflowId,
        ?WaitOptions $options = null,
    ): WorkflowStatusResponse {
        $intervalMs = ($options !== null ? $options->intervalMs : null) ?? WorkflowConstants::DEFAULT_POLL_INTERVAL_MS;
        $timeoutMs = ($options !== null ? $options->timeoutMs : null) ?? WorkflowConstants::DEFAULT_POLL_TIMEOUT_MS;
        $onPoll = $options?->onPoll;

        if ($onPoll !== null && !\is_callable($onPoll)) {
            throw new GislConfigError(
                'WaitOptions::$onPoll must be callable when set.',
            );
        }

        $intervalNs = $intervalMs * 1_000_000;
        $timeoutNs = $timeoutMs * 1_000_000;
        $deadlineNs = \hrtime(true) + $timeoutNs;

        $capability = $options?->capability;

        while (true) {
            $status = $this->getWorkflowStatus($workflowId, $capability);
            $statusString = $status->getStatus() ?? '';

            if ($onPoll !== null) {
                $onPoll($statusString);
            }

            if (\in_array($statusString, WorkflowConstants::TERMINAL_STATUSES, true)) {
                return $status;
            }

            $nowNs = \hrtime(true);
            if ($nowNs + $intervalNs > $deadlineNs) {
                throw new GislTimeoutError(
                    "Workflow {$workflowId} did not complete within {$timeoutMs}ms",
                );
            }

            if ($intervalMs > 0) {
                \usleep($intervalMs * 1000);
            }
        }
    }

    // ---------------------------------------------------------------------
    // Auth (VOxtu0RZ-B2.4)
    // ---------------------------------------------------------------------

    /**
     * Authenticate with email/password and (when configured) capture the
     * session cookie for subsequent requests.
     *
     * On success the server issues a `gisl_session` cookie via
     * `Set-Cookie`. When `GislClientConfig::$useSessionCookie === true`,
     * this method extracts the cookie value and stores it on the client
     * instance so {@see buildRequest} can forward it as a `Cookie:` header
     * on every following call. With `useSessionCookie === false`, the
     * `Set-Cookie` header is ignored and the typed user-payload is returned
     * unchanged — bearer-token-only flows never opt in to cookie capture.
     *
     * Failure modes per ticket FX6mbTJD (mirrors TS):
     *  - **401** `invalid_credentials` → {@see GislAuthError}.
     *  - **403** account-state failures (`account_locked`,
     *    `account_disabled`, `account_deleted`,
     *    `account_deletion_expired`) → {@see GislAuthError}.
     *  - **429** rate-limit → {@see GislApiError}.
     *
     * Mirrors `packages/typescript/src/client.ts::login`.
     */
    public function login(LoginUserRequest $credentials): LoginUser200ResponseData
    {
        $jsonBody = $this->jsonEncode(
            (array) ObjectSerializer::sanitizeForSerialization($credentials),
        );
        $request = $this->buildRequest(
            method: 'POST',
            path: '/api/auth/login',
            body: $this->streamFactory->createStream($jsonBody),
            extraHeaders: ['Content-Type' => 'application/json'],
        );

        // Drop down to sendRaw so we can inspect Set-Cookie before the body
        // is consumed by unwrapEnvelope. PSR-7 streams are typically forward-
        // only; reading the body for envelope decoding does not invalidate
        // headers, but capturing the cookie BEFORE unwrap means a
        // 200-with-malformed-envelope cannot leave the client in a half-
        // authenticated state with a captured cookie but no returned DTO.
        $response = $this->sendRaw($request);

        if ($this->config->useSessionCookie) {
            $cookieValue = $this->extractSessionCookie($response);
            if ($cookieValue !== null) {
                $this->sessionCookie = $cookieValue;
            }
        }

        /** @var array<string, mixed> $data */
        $data = $this->unwrapEnvelope($response);
        return $this->hydrate(LoginUser200ResponseData::class, $data);
    }

    /**
     * Invalidate the current session.
     *
     * **Idempotent.** Calling logout without an active session returns 401,
     * but the SDK collapses both 200 and 401 into a single "logged out"
     * outcome — `logout()` returns `void` in either case so caller cleanup
     * paths do not need to special-case the not-currently-authenticated
     * branch. Other errors (5xx, network failures) still throw.
     *
     * When `useSessionCookie === true`, the captured cookie is cleared on
     * BOTH outcomes (success and the 401-already-logged-out swallow) so a
     * subsequent request never carries a stale `Cookie:` header.
     *
     * Mirrors `packages/typescript/src/client.ts::logout` — the 401-swallow
     * pattern lives in this method's catch arm, not in the lower-level
     * envelope decode path, so other endpoints that legitimately surface
     * 401 (`getWorkflowStatus`, etc.) keep throwing as before.
     */
    public function logout(): void
    {
        $request = $this->buildRequest(
            method: 'POST',
            path: '/api/auth/logout',
        );

        try {
            $this->sendAndExpectVoid($request);
        } catch (GislApiError $e) {
            // 401 = already-logged-out per the contract. Swallow as success.
            // Note: the bare logout-401 envelope may surface as the typed
            // {@see GislAuthError} or as the base {@see GislApiError}
            // depending on whether `error_type` is set; both subclasses
            // satisfy `instanceof GislApiError`, so this catch-by-base is
            // correct without a sibling branch on GislAuthError.
            if ($e->statusCode !== 401) {
                throw $e;
            }
        } finally {
            // Clear local cookie state on every outcome — including the
            // 401-swallow path. Leaving a stale cookie after logout would
            // make subsequent requests carry an invalid session token and
            // fail at the server with 401, which is precisely what
            // logout's contract is meant to avoid.
            $this->sessionCookie = null;
        }
    }

    /**
     * Read the `gisl_session` value out of the response's `Set-Cookie`
     * header(s). Returns null when the response does not set the cookie.
     *
     * Per RFC 6265 §4.1.1 the cookie value is everything between the
     * `=` and the first `;` — the SDK does not parse `Path`, `HttpOnly`,
     * or `SameSite` attributes since the value is forwarded verbatim
     * back to the same origin via {@see buildRequest}.
     */
    private function extractSessionCookie(ResponseInterface $response): ?string
    {
        foreach ($response->getHeader('Set-Cookie') as $headerValue) {
            // PSR-7 returns each Set-Cookie as its own array entry; the
            // RFC 6265 prefix is `gisl_session=<value>;...`. Match the
            // first attribute pair and ignore the rest.
            if (\preg_match('/^gisl_session=([^;]*)/', $headerValue, $matches) === 1) {
                return $matches[1];
            }
        }
        return null;
    }

    // ---------------------------------------------------------------------
    // Contact (VOxtu0RZ-B2.4)
    // ---------------------------------------------------------------------

    /**
     * Submit a contact-form message. The endpoint returns 204 No Content on
     * success, so this method resolves to `void`.
     *
     * Validation errors (e.g. missing `email`, non-empty honeypot
     * `website`) surface as {@see GislValidationError} from the standard
     * error envelope.
     *
     * Mirrors `packages/typescript/src/client.ts::submitContact`.
     */
    public function submitContact(ContactRequest $payload): void
    {
        $jsonBody = $this->jsonEncode(
            (array) ObjectSerializer::sanitizeForSerialization($payload),
        );
        $request = $this->buildRequest(
            method: 'POST',
            path: '/api/contact',
            body: $this->streamFactory->createStream($jsonBody),
            extraHeaders: ['Content-Type' => 'application/json'],
        );

        $this->sendAndExpectVoid($request);
    }

    // ---------------------------------------------------------------------
    // Credits (VOxtu0RZ-B2.4)
    // ---------------------------------------------------------------------

    /**
     * Fetch the caller's current credit position. The canonical billing-
     * state surface — UIs should drive spend-now affordances and tier-
     * upgrade prompts off this endpoint, NOT off the
     * {@see GislBalanceExhaustedError} (402) envelope which only surfaces
     * during workflow creation.
     *
     * Note the path: `/api/v2/credits/balance` (NOT `/api/credits/...`).
     *
     * Mirrors `packages/typescript/src/client.ts::getCreditsBalance`.
     */
    public function getCreditsBalance(): CreditsBalanceResponse
    {
        $request = $this->buildRequest(
            method: 'GET',
            path: '/api/v2/credits/balance',
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        return $this->hydrate(CreditsBalanceResponse::class, $data);
    }

    /**
     * Fetch a paginated page of credit-transaction history. Server defaults
     * are `limit=20`, `offset=0`, most-recent first. The SDK forwards each
     * option only when set so server-side defaults remain authoritative.
     *
     * Note the path: `/api/v2/credits/usage` (NOT `/api/credits/...`).
     *
     * Mirrors `packages/typescript/src/client.ts::getCreditsUsage`.
     */
    public function getCreditsUsage(?CreditsUsageOptions $options = null): CreditsUsageResponse
    {
        $params = [];
        if ($options !== null && $options->limit !== null) {
            $params['limit'] = (string) $options->limit;
        }
        if ($options !== null && $options->offset !== null) {
            $params['offset'] = (string) $options->offset;
        }
        $query = $params === [] ? '' : '?' . \http_build_query($params);

        $request = $this->buildRequest(
            method: 'GET',
            path: '/api/v2/credits/usage' . $query,
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        return $this->hydrate(CreditsUsageResponse::class, $data);
    }

    /**
     * Fetch the caller's effective account limits (the tier-resolved caps:
     * upload/merge size + total caps, surfaced override-aware). The success
     * envelope's `data` is unwrapped to {@see AccountLimits}.
     *
     * Note the path: `/api/v2/account/limits`.
     *
     * Mirrors `packages/typescript/src/client.ts::getAccountLimits`.
     */
    public function getAccountLimits(): AccountLimits
    {
        $request = $this->buildRequest(
            method: 'GET',
            path: '/api/v2/account/limits',
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        return $this->hydrate(AccountLimits::class, $data);
    }

    // ---------------------------------------------------------------------
    // Schema introspection
    // ---------------------------------------------------------------------

    /**
     * Fetch the per-tier operations schema with optional MIME / operation
     * filtering and HTTP conditional revalidation.
     *
     * Mirrors `packages/typescript/src/client.ts::getSchema`. Unlike the
     * other GET methods, this endpoint does NOT route through
     * {@see unwrapEnvelope} for the success path: 200 returns a
     * {@see OperationsSchemaResponse} body directly (not wrapped in
     * `{ success: true, data: ... }`), and 304 returns an empty body. The
     * SDK builds the request manually, sends via the PSR-18 client, and
     * returns the appropriate sealed-shape variant of {@see GetSchemaResult}:
     *   - {@see GetSchemaHitResult} on 200 (carries the typed schema body
     *     plus `etag` / `lastModified` cache markers)
     *   - {@see GetSchemaNotModifiedResult} on 304 (cache markers only;
     *     body is empty)
     *
     * Non-2xx / non-304 responses are routed through {@see unwrapEnvelope}
     * so the typed-error dispatch keeps working — e.g. a 422 validation
     * envelope still surfaces as {@see GislValidationError}.
     */
    public function getSchema(?GetSchemaOptions $options = null): GetSchemaResult
    {
        $options ??= new GetSchemaOptions();

        $params = [];
        if ($options->mimeType !== null) {
            $params['mime_type'] = $options->mimeType;
        }
        if ($options->operation !== null) {
            $params['operation'] = $options->operation;
        }
        $query = \http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $path = '/api/operations/schema' . ($query !== '' ? '?' . $query : '');

        $extraHeaders = [];
        if ($options->ifNoneMatch !== null) {
            $extraHeaders['If-None-Match'] = $options->ifNoneMatch;
        }
        if ($options->ifModifiedSince !== null) {
            $extraHeaders['If-Modified-Since'] = $options->ifModifiedSince;
        }

        $request = $this->buildRequest(
            method: 'GET',
            path: $path,
            extraHeaders: $extraHeaders,
        );

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new GislNetworkError(
                "HTTP transport failed: {$e->getMessage()}",
                $e,
            );
        }

        $statusCode = $response->getStatusCode();
        $etagHeader = $response->getHeaderLine('ETag');
        $lastModifiedHeader = $response->getHeaderLine('Last-Modified');
        $etag = $etagHeader !== '' ? $etagHeader : null;
        $lastModified = $lastModifiedHeader !== '' ? $lastModifiedHeader : null;

        if ($statusCode === 304) {
            return new GetSchemaNotModifiedResult($etag, $lastModified);
        }

        if ($statusCode >= 200 && $statusCode < 300) {
            $rawBody = (string) $response->getBody();
            if ($rawBody === '') {
                throw new GislError(
                    "Empty response body from /api/operations/schema (status {$statusCode}).",
                );
            }
            try {
                /** @var array<string, mixed> $decoded */
                $decoded = \json_decode($rawBody, associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new GislError(
                    "Server returned non-JSON body for /api/operations/schema (status {$statusCode}): "
                    . $e->getMessage(),
                    0,
                    $e,
                );
            }

            // The schema endpoint is NOT enveloped — the response body is
            // the OperationsSchemaResponse directly, not wrapped in
            // `{ success: true, data: ... }`. Hydrate straight from the
            // decoded body.
            $schema = $this->hydrate(OperationsSchemaResponse::class, $decoded);
            return new GetSchemaHitResult($schema, $etag, $lastModified);
        }

        // Error path — route through the typed-error dispatcher so a 422
        // validation envelope still surfaces as `GislValidationError`, etc.
        $this->unwrapEnvelope($response);

        // unwrapEnvelope() always throws on a non-success path, but PHP can't
        // see that statically — guard so the return type is well-formed.
        throw new GislError(
            "Unexpected fall-through from getSchema error path (status {$statusCode}).",
        );
    }

    // ---------------------------------------------------------------------
    // Planned-tier operations
    // ---------------------------------------------------------------------

    /**
     * Probe an uploaded file for workflow-readiness. Detects corruption,
     * unsupported codecs, and pre-assigns the processing class the server
     * would route the file to. Designed for the long-form merge edge case
     * where a single bad input would fail the whole workflow.
     *
     * Endpoint availability is `stable`. The probe runs asynchronously after
     * upload: until the result has landed this returns `422`
     * `feature_not_available` (surfaced as {@see GislFeatureNotAvailableError})
     * — i.e. that 422 means "probe not landed yet", NOT "not implemented".
     * Once landed it returns a `200` with any `probe_status`. For video uploads
     * the landed probe carries the codec + duration the server needs to admit
     * the parallel split, so calling this (or {@see waitForProbe()}) before
     * workflow-create is the structural unlock for the fast video path on the
     * multipart flow. Idempotent: probing the same `fileId` twice returns the
     * cached result.
     *
     * Mirrors `packages/typescript/src/client.ts::probeUpload`.
     */
    public function probeUpload(string $fileId): UploadProbeResponse
    {
        $encoded = \rawurlencode($fileId);
        $request = $this->buildRequest(
            method: 'POST',
            path: "/api/uploads/{$encoded}/probe",
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        return $this->hydrate(UploadProbeResponse::class, $data);
    }

    /**
     * Bounded poll of {@see probeUpload()} until the probe lands — the helper
     * frontends call between upload-complete and workflow-create so the server
     * sees the video's codec + duration and admits the ~3× parallel split.
     *
     * Loop (per the API wire contract):
     * - `422 feature_not_available` → probe not landed yet → keep polling
     *   (exponential full-jitter backoff, honouring a `Retry-After` header when
     *   present, clamped to the remaining budget).
     * - any `200` → STOP. Returns `landed: true` regardless of `probe_status`
     *   (ok / corrupt / unsupported_codec / missing_metadata) — the server +
     *   fan-out gate decide split-vs-single from the landed metadata; the SDK
     *   does not interpret it.
     * - `5xx` (prober crash) → retry a couple of times, then give up.
     * - timeout → give up.
     *
     * **Never bounces:** on give-up (timeout / repeated 5xx / transport) it
     * returns `landed: false` with a `reason` rather than throwing, so the
     * caller proceeds to create the workflow anyway (the server's size
     * heuristic routes it; worst case = today's single-task behaviour). Genuine
     * failures — `404 upload_not_found`, auth errors, or a cancelled token — DO
     * throw (they are not "probe not ready"), so a real problem is never
     * swallowed.
     *
     * `timeoutMs` bounds the OVERALL poll, checked BETWEEN completed attempts.
     * PSR-18 has no mid-request cancellation primitive, so a single in-flight
     * probe request is bounded only by the injected HTTP client's own timeout
     * (e.g. Guzzle's `timeout`/`connect_timeout`) — set that to keep one slow
     * probe from running past the wait budget. Cancellation is likewise
     * cooperative + between-polls (SDK convention): a cancelled token throws
     * {@see GislAbortError} at the next loop top.
     *
     * Mirrors `packages/typescript/src/client.ts::waitForProbe`.
     */
    public function waitForProbe(string $fileId, ?ProbeWaitOptions $options = null): ProbeWaitResult
    {
        $opts = $options ?? new ProbeWaitOptions();
        // A null timeoutMs uses the 30s default; a NEGATIVE value clamps to 0
        // (NOT the default) so a sub-zero budget fires zero probe requests
        // (with the pre-request deadline check below). Mirrors the TS
        // sanitiseBaseMs negative-clamp.
        $timeoutMs = $opts->timeoutMs === null ? 30_000 : \max(0, $opts->timeoutMs);
        $cancellation = $opts->cancellation;
        $onPoll = $opts->onPoll;

        $startNs = \hrtime(true);
        $deadlineNs = $startNs + ($timeoutMs * 1_000_000);
        $baseBackoffMs = 250;
        $maxProberRetries = 2;

        $attempt = 0;
        // Counts transient probe-call failures (5xx + transport) — repeated
        // transients give up so the caller creates anyway (never-bounce).
        $transientFailures = 0;
        while (true) {
            if ($cancellation?->isCancelled() === true) {
                throw new GislAbortError('Cancelled during probe wait.');
            }
            // Pre-request deadline check (mirrors the TS `remainingBeforeAttempt
            // <= 0` guard): a timeoutMs<=0 must give up BEFORE issuing any probe
            // HTTP call, so a 0-budget wait fires zero requests.
            if (\hrtime(true) >= $deadlineNs) {
                return new ProbeWaitResult(landed: false, reason: 'timeout');
            }
            ++$attempt;
            if (\is_callable($onPoll)) {
                $elapsedMs = (int) ((\hrtime(true) - $startNs) / 1_000_000);
                $onPoll(['attempt' => $attempt, 'elapsedMs' => $elapsedMs]);
            }

            $retryAfterMs = null;
            try {
                $probe = $this->probeUpload($fileId);
                return new ProbeWaitResult(landed: true, probe: $probe);
            } catch (GislFeatureNotAvailableError $e) {
                // Not landed yet — keep polling.
                $retryAfterMs = RateLimitHeaders::parseRetryAfterMs($e->responseHeaders['retry-after'] ?? null);
            } catch (GislNetworkError $e) {
                // Transport failure (transient) → retry a couple of times then
                // give up; the caller creates anyway (never-bounce).
                ++$transientFailures;
                if ($transientFailures > $maxProberRetries) {
                    return new ProbeWaitResult(landed: false, reason: 'prober_error');
                }
            } catch (GislApiError $e) {
                if ($e->statusCode >= 500) {
                    ++$transientFailures;
                    if ($transientFailures > $maxProberRetries) {
                        return new ProbeWaitResult(landed: false, reason: 'prober_error');
                    }
                    $retryAfterMs = RateLimitHeaders::parseRetryAfterMs($e->responseHeaders['retry-after'] ?? null);
                } else {
                    // 404 upload_not_found, auth, or any other typed error is a
                    // real failure, not "probe not ready" — propagate.
                    throw $e;
                }
            }

            $remainingMs = (int) (($deadlineNs - \hrtime(true)) / 1_000_000);
            if ($remainingMs <= 0) {
                return new ProbeWaitResult(landed: false, reason: 'timeout');
            }
            $backoffMs = $retryAfterMs ?? self::fullJitterMs($baseBackoffMs, $attempt - 1);
            $sleepMs = \min($backoffMs, $remainingMs);
            if ($sleepMs > 0) {
                \usleep($sleepMs * 1000);
            }
            if (\hrtime(true) >= $deadlineNs) {
                return new ProbeWaitResult(landed: false, reason: 'timeout');
            }
        }
    }

    /**
     * Best-effort probe-before-create for a VIDEO upload that went multipart.
     * No-op unless enabled AND `$isVideo` AND the upload exceeded the multipart
     * threshold (i.e. it was a multipart upload — small single-shot videos skip
     * the wait). Delegates to {@see waitForProbe()} (never-bounce): a give-up
     * just returns; genuine failures / a cancelled token propagate. The caller
     * passes `$isVideo` so the low-level client never imports ergonomic media
     * detection.
     *
     * Mirrors `packages/typescript/src/client.ts::maybeWaitForVideoProbe`.
     */
    public function maybeWaitForVideoProbe(
        string $fileId,
        bool $enabled,
        bool $isVideo,
        ?int $sizeBytes,
        ?int $timeoutMs = null,
        ?Cancellation $cancellation = null,
    ): void {
        if (!$enabled || !$isVideo) {
            return;
        }
        if ($sizeBytes === null || $sizeBytes <= $this->config->multipartThresholdBytes) {
            return;
        }
        $this->waitForProbe($fileId, new ProbeWaitOptions(timeoutMs: $timeoutMs, cancellation: $cancellation));
    }

    /**
     * Full-jitter exponential backoff: random(0, base * 2^attemptIndex) ms.
     * Mirrors the TS `fullJitterDelay`.
     */
    private static function fullJitterMs(int $baseMs, int $attemptIndex): int
    {
        if ($baseMs <= 0) {
            return 0;
        }
        $ceiling = $baseMs * (2 ** $attemptIndex);
        return \random_int(0, (int) $ceiling);
    }

    /**
     * Probe N uploaded files in sequence and partition the results by
     * outcome. Returns {@see PreflightClipsResult} so the caller can cleanly
     * drop bad clips before submitting a long-form merge workflow.
     *
     * **Aggregation contract** (mirrors the TS `Promise.allSettled`-shaped
     * reference): every fileId is probed even if siblings fail. The whole
     * call NEVER propagates a per-probe failure:
     *   - probe succeeds AND `probe_status === 'ok'` → goes into `ok`
     *   - probe succeeds AND `probe_status !== 'ok'` → goes into `rejected`
     *     (carries the typed probe response so the caller can read
     *     `probe_status`, `media_metadata`, etc.)
     *   - probe call itself threw → `{ fileId, error }` goes into `errors`
     *
     * The PHP path is sequential (single-threaded) where the TS reference
     * is parallel via `Promise.allSettled`. This is a deliberate divergence
     * — PHP's PSR-18 surface has no async primitive, and the cross-call
     * partitioning semantics (no per-probe propagation) are what callers
     * depend on, not the parallelism. Concurrent fan-out for Guzzle /
     * Symfony HttpClient is tracked separately.
     *
     * Empty input is well-defined: `preflightClips([])` returns an empty
     * {@see PreflightClipsResult} without making any HTTP request.
     *
     * @param list<string> $fileIds
     */
    public function preflightClips(array $fileIds): PreflightClipsResult
    {
        $ok = [];
        $rejected = [];
        $errors = [];

        foreach ($fileIds as $fileId) {
            try {
                $probe = $this->probeUpload($fileId);
            } catch (\Throwable $e) {
                $errors[] = new PreflightClipError($fileId, $e);
                continue;
            }

            if ($probe->getProbeStatus() === 'ok') {
                $ok[] = $probe;
            } else {
                $rejected[] = $probe;
            }
        }

        return new PreflightClipsResult($ok, $rejected, $errors);
    }

    /**
     * Decode a previously-embedded steganographic audio watermark.
     *
     * **Enterprise tier only.** Free / pro callers receive
     * {@see GislFeatureTierRestrictedError} (403). Currently
     * `availability: planned` — calls return
     * {@see GislFeatureNotAvailableError} (422) until the cross-repo Lambda
     * support ships. Decode requests are rate-limited independently from
     * workflow-create.
     *
     * Mirrors `packages/typescript/src/client.ts::decodeAudioWatermark`.
     */
    public function decodeAudioWatermark(
        AudioWatermarkDecodeRequest $payload,
    ): AudioWatermarkDecodeResponse {
        // Mirror the TS `*ToJSON` snake-case conversion via the generated
        // ObjectSerializer's sanitizer. The PHP getters are camelCase
        // (`getMethodHint`) but the wire shape is snake_case
        // (`method_hint`).
        $wire = ObjectSerializer::sanitizeForSerialization($payload);
        $jsonBody = $this->jsonEncode((array) $wire);

        $request = $this->buildRequest(
            method: 'POST',
            path: '/api/audio-watermark/decode',
            body: $this->streamFactory->createStream($jsonBody),
            extraHeaders: ['Content-Type' => 'application/json'],
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        return $this->hydrate(AudioWatermarkDecodeResponse::class, $data);
    }

    /**
     * Register a one-shot bearer URL (S3 presigned, GCS signed, Azure SAS,
     * Dropbox shared link, public HTTPS) and receive an opaque
     * `external_source_id` handle. Subsequent workflows reference the
     * handle via `WorkflowSource` of `type: external_import`.
     *
     * Per ADR-0005 §"SSRF posture": the server validates 8 rules at
     * registration time AND again at fetch time. HTTPS-only;
     * private/loopback/cloud-metadata IPs are rejected (403). The original
     * URL + password are encrypted at rest and never returned in any
     * response.
     *
     * Currently `availability: planned` — calls return
     * {@see GislFeatureNotAvailableError} (422) until the external-import
     * infrastructure ships.
     *
     * Auth-ownership: the import id this returns is owned by the
     * authenticated caller that created it. Referencing it from a client
     * with a different auth context 404s `upload_not_found` at
     * workflow-create — same ownership rule as
     * {@see \Gisl\Sdk\FileFirst\FileInput::uploadId()} (api PqpD9ySv).
     *
     * Mirrors `packages/typescript/src/client.ts::createExternalImport`.
     */
    public function createExternalImport(
        ExternalImportRequest $payload,
    ): ExternalImportCreatedResponse {
        $wire = ObjectSerializer::sanitizeForSerialization($payload);
        $jsonBody = $this->jsonEncode((array) $wire);

        $request = $this->buildRequest(
            method: 'POST',
            path: '/api/external-imports',
            body: $this->streamFactory->createStream($jsonBody),
            extraHeaders: ['Content-Type' => 'application/json'],
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        return $this->hydrate(ExternalImportCreatedResponse::class, $data);
    }

    // ---------------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------------

    /**
     * Anonymous-read capability header. An anonymous (null-owner) workflow
     * create returns a one-time `cap` token (WorkflowCreateResponse::getCap());
     * the session-less caller passes it back on status/downloads/events reads
     * via this header so the server can authorize the read. A wrong/missing cap
     * on a null-owner workflow returns 404 (no existence oracle), per contracts
     * ticket YQt88cq2. Mirrors `WORKFLOW_CAPABILITY_HEADER` in
     * `packages/typescript/src/client.ts`.
     */
    private const WORKFLOW_CAPABILITY_HEADER = 'X-Workflow-Capability';

    /**
     * Build the capability header set for a workflow read. Empty when no token
     * is supplied (authenticated reads — the session authorizes those).
     *
     * @return array<string, string>
     */
    private function workflowCapabilityHeaders(?string $capability): array
    {
        return ($capability !== null && $capability !== '')
            ? [self::WORKFLOW_CAPABILITY_HEADER => $capability]
            : [];
    }

    /**
     * @param array<string, string> $extraHeaders
     */
    private function buildRequest(
        string $method,
        string $path,
        mixed $body = null,
        array $extraHeaders = [],
    ): RequestInterface {
        $request = $this->requestFactory->createRequest(
            $method,
            $this->config->baseUrl . $path,
        );

        $request = $request
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', 'giveitsmaller-sdk-php/0.1.0');

        if ($this->config->apiKey !== null) {
            $request = $request->withHeader('Authorization', "Bearer {$this->config->apiKey}");
        }

        // When configured for cookie auth and login() has captured a session
        // cookie, forward it on every subsequent request so the server's
        // session middleware can identify the caller. Mirrors the TS client's
        // `credentials: 'include'` fetch flag — same effect, different transport.
        if ($this->config->useSessionCookie && $this->sessionCookie !== null) {
            $request = $request->withHeader('Cookie', "gisl_session={$this->sessionCookie}");
        }

        foreach ($this->config->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        foreach ($extraHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        // A dedicated `locale` unconditionally wins over any Accept-Language
        // supplied via config headers OR per-call extraHeaders. Applied LAST so
        // the invariant holds regardless of header source; PSR-7 withHeader
        // replaces case-insensitively, so a differently-cased accept-language is
        // also superseded. Mirrors packages/typescript/src/client.ts:523-534,
        // which strips Accept-Language from the merged header set before setting
        // locale (same net effect: locale always wins).
        if ($this->config->locale !== null) {
            $request = $request->withHeader('Accept-Language', $this->config->locale);
        }

        if ($body !== null) {
            // PSR-7 stream OR string-able. The streamFactory path handles both.
            if (\is_string($body)) {
                $body = $this->streamFactory->createStream($body);
            }
            $request = $request->withBody($body);
        }

        return $request;
    }

    /**
     * Send the request via the PSR-18 client, normalise transport failures
     * into {@see GislNetworkError}, then unwrap the success envelope.
     *
     * @return mixed Decoded `data` field on `{ success: true, data: ... }`.
     *               Throws on `{ success: false, ... }` with a typed
     *               `GislApiError` (or `GislAuthError` for 401).
     * @throws GislNetworkError
     * @throws GislApiError
     * @throws GislError
     */
    private function sendAndUnwrap(RequestInterface $request): mixed
    {
        $response = $this->sendRaw($request);
        return $this->unwrapEnvelope($response);
    }

    /**
     * Send the request via the PSR-18 client and return the raw PSR-7
     * response, normalising transport failures into {@see GislNetworkError}.
     * Used by callers that need to read response headers (e.g. {@see login}
     * extracting the `Set-Cookie` header) before envelope decoding, and as
     * the underlying primitive for {@see sendAndUnwrap} and
     * {@see sendAndExpectVoid}.
     *
     * @throws GislNetworkError
     */
    private function sendRaw(RequestInterface $request): ResponseInterface
    {
        try {
            return $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new GislNetworkError(
                "HTTP transport failed: {$e->getMessage()}",
                $e,
            );
        }
    }

    /**
     * Send a request whose contract returns a void result. The endpoint
     * MAY return either:
     *   - 204 No Content with an empty body (the contract default for
     *     {@see submitContact}); short-circuited here without going
     *     through {@see unwrapEnvelope}.
     *   - 200 with a `{ success: true, data: ... }` envelope (some
     *     gateways bridge 204 to 200 for compatibility); routed through
     *     {@see unwrapEnvelope} so the `data` field is parsed but
     *     discarded.
     *
     * Failure envelopes at any status route through {@see unwrapEnvelope}
     * so typed-error dispatch (validation, auth, balance, etc.) keeps
     * working unchanged. {@see logout}'s 401 swallow lives at the public-
     * method layer, not here.
     *
     * @throws GislNetworkError
     * @throws GislApiError
     * @throws GislError
     */
    private function sendAndExpectVoid(RequestInterface $request): void
    {
        $response = $this->sendRaw($request);

        if ($response->getStatusCode() === 204) {
            // Per the contract, 204 carries no body. Skip the envelope
            // decode so a void endpoint never trips the empty-body guard
            // inside unwrapEnvelope.
            return;
        }

        // Delegate to unwrapEnvelope for both 200-success-with-body and
        // failure-at-any-status. The decoded data field is ignored by
        // design — the public method's return type is void.
        $this->unwrapEnvelope($response);
    }

    /**
     * Lowercased response-header map mirroring TS `headersToRecord`
     * (packages/typescript/src/client.ts). Keys are lowercased (RFC 9110
     * case-insensitive); multi-value headers are comma-joined.
     *
     * @return array<string, string>
     */
    private function responseHeadersToMap(ResponseInterface $response): array
    {
        $map = [];
        foreach ($response->getHeaders() as $name => $values) {
            $map[\strtolower((string) $name)] = \implode(', ', $values);
        }
        return $map;
    }

    /**
     * Strip the `{ success: bool, data | error, ... }` wire envelope.
     *
     * @return mixed The contents of `data` on success.
     * @throws GislApiError on `success: false`.
     * @throws GislError    when the envelope can't be parsed at all.
     */
    private function unwrapEnvelope(ResponseInterface $response): mixed
    {
        $statusCode = $response->getStatusCode();
        $rawBody = (string) $response->getBody();

        if ($rawBody === '') {
            // 204 No Content responses have no envelope. The SDK methods
            // landing in VOxtu0RZ-B2 (submitContact, etc.) return void in
            // that case; the three methods this scaffold exposes always
            // produce a body, so empty here is unexpected.
            throw new GislError(
                "Empty response body from {$response->getStatusCode()} (expected JSON envelope).",
            );
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = \json_decode($rawBody, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new GislError(
                "Server returned non-JSON body (status {$statusCode}): " . $e->getMessage(),
                0,
                $e,
            );
        }

        $success = $decoded['success'] ?? null;

        if ($success === true) {
            if (!\array_key_exists('data', $decoded)) {
                throw new GislError(
                    "Success envelope missing `data` field (status {$statusCode}).",
                );
            }
            return $decoded['data'];
        }

        // Capture response-header surface for error construction. Mirrors
        // packages/typescript/src/client.ts:630-633,639-642.
        $responseHeaders = $this->responseHeadersToMap($response);
        $contentLanguage = $response->hasHeader('Content-Language')
            ? $response->getHeaderLine('Content-Language')
            : null;

        // Failure envelope: { success: false, error, details?, message_key?, ... }
        $errorCode = isset($decoded['error']) && \is_string($decoded['error'])
            ? $decoded['error']
            : 'unknown_error';
        $message = isset($decoded['message']) && \is_string($decoded['message'])
            ? $decoded['message']
            : "Request failed with status {$statusCode} ({$errorCode}).";

        // Localisation triple per ticket I26 — surfaced on every typed error so
        // consumers can drive client-side i18n catalogs without unwrapping the
        // typed payload. Wire keys are snake_case; the typed-payload subclasses
        // also carry camelCase copies on the typed DTO. Mirrors
        // packages/typescript/src/client.ts:495-503.
        $messageKey = isset($decoded['message_key']) && \is_string($decoded['message_key'])
            ? $decoded['message_key']
            : null;
        $locale = isset($decoded['locale']) && \is_string($decoded['locale'])
            ? $decoded['locale']
            : null;
        /** @var array<string, mixed>|null $messageParams */
        $messageParams = isset($decoded['message_params']) && \is_array($decoded['message_params'])
            ? $decoded['message_params']
            : null;

        $errorType = isset($decoded['error_type']) && \is_string($decoded['error_type'])
            ? $decoded['error_type']
            : null;

        // Validation-envelope branch first — preserve the TS dispatch order so
        // a future caller matching on `instanceof GislValidationError` keeps
        // working. Detection is shape-based on `details`: each entry must be
        // an array with a `message` string. Envelopes whose `details` use a
        // different key (legacy `reason` from v1) fall through to the generic
        // GislApiError path so existing callers reading `$e->payload['details']`
        // keep working.
        $details = $decoded['details'] ?? null;
        if (\is_array($details) && $details !== [] && $this->isValidationDetails($details)) {
            $typedValidation = $this->tryDeserialize(ValidationErrorEnvelope::class, $decoded);
            if ($typedValidation instanceof ValidationErrorEnvelope) {
                throw new GislValidationError(
                    $message,
                    $statusCode,
                    $errorCode,
                    $typedValidation,
                    $decoded,
                    $messageKey,
                    $locale,
                    $messageParams,
                    $responseHeaders,
                    $contentLanguage,
                );
            }
        }

        // Dispatch by (statusCode, errorType) onto the structured envelope shapes
        // emitted by the v2 contracts. Each typed branch builds the typed DTO
        // via ObjectSerializer::deserialize; if construction throws OR the
        // resulting DTO fails the per-branch validity check, fall through to
        // the generic GislApiError rather than handing the caller a
        // half-constructed typed error. Mirrors
        // packages/typescript/src/client.ts:517-621.
        if (($statusCode === 401 || $statusCode === 403)
            && $errorType !== null
            && \in_array($errorType, AuthErrorType::getAllowableEnumValues(), true)
        ) {
            $typed = $this->tryDeserialize(AuthErrorResponse::class, $decoded);
            if ($typed instanceof AuthErrorResponse && \is_string($typed->getErrorType())) {
                throw new GislAuthError(
                    $message,
                    $statusCode,
                    $errorCode,
                    $decoded,
                    $messageKey,
                    $locale,
                    $messageParams,
                    $typed,
                    $responseHeaders,
                    $contentLanguage,
                );
            }
        }

        if ($statusCode === 402 && $errorType === 'balance_exhausted') {
            $typed = $this->tryDeserialize(BalanceExhaustedResponse::class, $decoded);
            if ($typed instanceof BalanceExhaustedResponse && \is_string($typed->getRequiredAction())) {
                throw new GislBalanceExhaustedError(
                    $message,
                    $statusCode,
                    $errorCode,
                    $typed,
                    $decoded,
                    $messageKey,
                    $locale,
                    $messageParams,
                    $responseHeaders,
                    $contentLanguage,
                );
            }
        }

        if ($statusCode === 403 && $errorType === 'tier_restriction') {
            $typed = $this->tryDeserialize(TierRestrictionResponse::class, $decoded);
            if ($typed instanceof TierRestrictionResponse
                && \is_string($typed->getRestrictionKind())
                && \is_string($typed->getCurrentTier())
            ) {
                throw new GislTierRestrictedError(
                    $message,
                    $statusCode,
                    $errorCode,
                    $typed,
                    $decoded,
                    $messageKey,
                    $locale,
                    $messageParams,
                    $responseHeaders,
                    $contentLanguage,
                );
            }
        }

        if ($statusCode === 403 && $errorType === 'feature_tier_restricted') {
            $typed = $this->tryDeserialize(FeatureTierRestrictedResponse::class, $decoded);
            if ($typed instanceof FeatureTierRestrictedResponse && $this->areFeatureViolations($typed->getViolations())) {
                throw new GislFeatureTierRestrictedError(
                    $message,
                    $statusCode,
                    $errorCode,
                    $typed,
                    $decoded,
                    $messageKey,
                    $locale,
                    $messageParams,
                    $responseHeaders,
                    $contentLanguage,
                );
            }
        }

        if ($statusCode === 422 && $errorType === 'feature_not_available') {
            $typed = $this->tryDeserialize(FeatureNotAvailableResponse::class, $decoded);
            if ($typed instanceof FeatureNotAvailableResponse && $this->areFeatureViolations($typed->getViolations())) {
                throw new GislFeatureNotAvailableError(
                    $message,
                    $statusCode,
                    $errorCode,
                    $typed,
                    $decoded,
                    $messageKey,
                    $locale,
                    $messageParams,
                    $responseHeaders,
                    $contentLanguage,
                );
            }
        }

        if ($statusCode === 422 && $errorType === 'workflow_expired') {
            $typed = $this->tryDeserialize(WorkflowExpiredResponse::class, $decoded);
            if ($typed instanceof WorkflowExpiredResponse && $typed->getExpiredAt() instanceof \DateTimeInterface) {
                throw new GislWorkflowExpiredError(
                    $message,
                    $statusCode,
                    $errorCode,
                    $typed,
                    $decoded,
                    $messageKey,
                    $locale,
                    $messageParams,
                    $responseHeaders,
                    $contentLanguage,
                );
            }
        }

        // Probe-pending 422 on POST /api/workflows (per contracts av1J0rEF).
        // Recovery: caller polls /api/uploads/{id}/probe until terminal,
        // then retries the workflow-create. `typedPayload->getJobRef()`
        // names which job triggered the rejection. Mirrors
        // `packages/typescript/src/client.ts` GislProbePendingError branch.
        if ($statusCode === 422 && $errorType === 'probe_pending') {
            // Validate `job_ref` on the RAW wire before deserialize — the
            // generated ObjectSerializer coerces non-string values (numeric,
            // array) to strings, so a malformed `job_ref: 123` would pass
            // a post-deserialize `is_string()` check as `"123"`. Pin the
            // wire-shape integrity upstream of deserialization. (Codex r1.)
            $rawJobRef = $decoded['job_ref'] ?? null;
            $typed = $this->tryDeserialize(ProbePendingResponse::class, $decoded);
            if (
                $typed instanceof ProbePendingResponse
                && \is_string($rawJobRef)
                && $rawJobRef !== ''
            ) {
                throw new GislProbePendingError(
                    $message,
                    $statusCode,
                    $errorCode,
                    $typed,
                    $decoded,
                    $messageKey,
                    $locale,
                    $messageParams,
                    $responseHeaders,
                    $contentLanguage,
                );
            }
        }

        // Upload cap errors. Mirrors
        // `packages/typescript/src/client.ts` handleResponse (the
        // `tryThrowCap` helper + the 422 size/duration + 413 branches). Same
        // construct-validate-fallthrough discipline as the branches above.
        if ($statusCode === 422 && $errorType === 'upload_size_exceeds_tier') {
            $typed = $this->tryDeserialize(UploadSizeExceedsTierResponse::class, $decoded);
            if (
                $typed instanceof UploadSizeExceedsTierResponse
                && $typed->getCurrentTier() !== null
                && $typed->getMaxSizeBytes() !== null
            ) {
                throw new GislUploadCapExceededError(
                    $message,
                    $statusCode,
                    $errorCode,
                    GislUploadCapExceededError::KIND_SIZE_TIER,
                    $typed,
                    $decoded,
                    $messageKey,
                    $locale,
                    $messageParams,
                    $responseHeaders,
                    $contentLanguage,
                );
            }
        }

        if ($statusCode === 422 && $errorType === 'upload_duration_exceeds_tier') {
            $typed = $this->tryDeserialize(UploadDurationExceedsTierResponse::class, $decoded);
            if (
                $typed instanceof UploadDurationExceedsTierResponse
                && $typed->getCurrentTier() !== null
                && $typed->getMaxDurationSeconds() !== null
            ) {
                throw new GislUploadCapExceededError(
                    $message,
                    $statusCode,
                    $errorCode,
                    GislUploadCapExceededError::KIND_DURATION_TIER,
                    $typed,
                    $decoded,
                    $messageKey,
                    $locale,
                    $messageParams,
                    $responseHeaders,
                    $contentLanguage,
                );
            }
        }

        // 422 auth-side-effect domain rejection (per contracts ADR-0019).
        // Flat AuthRejectionEnvelope — NO `details[]` — on register /
        // verify-email / api-keys (`error_type: unprocessable_entity`) and
        // profile PATCH email-unchanged (`error_type: email_same`). The
        // `validation_error` branch of the same auth-422 `oneOf` carries
        // `details[]` and is already routed to GislValidationError by the
        // shape-based branch above. Mirrors
        // `packages/typescript/src/client.ts` GislAuthRejectionError branch.
        if (
            $statusCode === 422
            && \in_array($errorType, [
                AuthRejectionEnvelope::ERROR_TYPE_UNPROCESSABLE_ENTITY,
                AuthRejectionEnvelope::ERROR_TYPE_EMAIL_SAME,
            ], true)
        ) {
            $typed = $this->tryDeserialize(AuthRejectionEnvelope::class, $decoded);
            if (
                $typed instanceof AuthRejectionEnvelope
                && \is_string($typed->getErrorType())
                && $typed->getErrorType() !== ''
            ) {
                throw new GislAuthRejectionError(
                    $message,
                    $statusCode,
                    $errorCode,
                    $typed,
                    $decoded,
                    $messageKey,
                    $locale,
                    $messageParams,
                    $responseHeaders,
                    $contentLanguage,
                );
            }
        }

        // 413 = the absolute across-tier cap. The contract models 413 as a
        // plain ErrorEnvelope (no `error_type` discriminator, no typed
        // payload), so dispatch purely on status with a null typed payload
        // — `absolute_413` tells the caller there is intentionally none.
        if ($statusCode === 413) {
            throw new GislUploadCapExceededError(
                $message,
                $statusCode,
                $errorCode,
                GislUploadCapExceededError::KIND_ABSOLUTE_413,
                null,
                $decoded,
                $messageKey,
                $locale,
                $messageParams,
                $responseHeaders,
                $contentLanguage,
            );
        }

        // SDK-3 (Wb6ebOMM) resume-support endpoint error codes. API-2 / PR
        // #283 specced these as plain ErrorEnvelope envelopes discriminated
        // by string on `error_type`. No typed payload to build — dispatch
        // on the (statusCode, errorType) tuple. Mirrors the TS reference
        // (`packages/typescript/src/client.ts` handleResponse, the 4 new
        // branches after the 413 absolute-cap one). The HxUmVr3Y contract
        // regen will produce typed responses for these; today the 3 typed
        // subclasses carry only the localisation triple + raw envelope.
        if ($statusCode === 404 && $errorType === 'MULTIPART_SESSION_NOT_FOUND') {
            throw new GislMultipartSessionNotFoundError(
                $message,
                $statusCode,
                $errorCode,
                $decoded,
                $messageKey,
                $locale,
                $messageParams,
                $responseHeaders,
                $contentLanguage,
            );
        }
        if ($statusCode === 403 && $errorType === 'MULTIPART_SESSION_OWNERSHIP') {
            throw new GislMultipartSessionOwnershipError(
                $message,
                $statusCode,
                $errorCode,
                $decoded,
                $messageKey,
                $locale,
                $messageParams,
                $responseHeaders,
                $contentLanguage,
            );
        }
        if ($statusCode === 403 && $errorType === 'MULTIPART_SESSION_AUTH_REQUIRED') {
            throw new GislMultipartSessionAuthRequiredError(
                $message,
                $statusCode,
                $errorCode,
                $decoded,
                $messageKey,
                $locale,
                $messageParams,
                $responseHeaders,
                $contentLanguage,
            );
        }
        // 422 `FILE_TOO_LARGE_FOR_MULTIPART` — pre-S3 capacity reject on the
        // resume-support presign endpoint. No typed payload today; the
        // `KIND_V2_MULTIPART` discriminant tells the caller this is the
        // SDK-3 cap variant.
        if ($statusCode === 422 && $errorType === 'FILE_TOO_LARGE_FOR_MULTIPART') {
            throw new GislUploadCapExceededError(
                $message,
                $statusCode,
                $errorCode,
                GislUploadCapExceededError::KIND_V2_MULTIPART,
                null,
                $decoded,
                $messageKey,
                $locale,
                $messageParams,
                $responseHeaders,
                $contentLanguage,
            );
        }

        // Generic fallback.
        //
        // **PHP-only divergence from TS.** TS routes 401 with no recognised
        // `error_type` to base `GislApiError` (`packages/typescript/src/client.ts`
        // `tryThrowStructured` falls through, then line ~623 throws the base
        // class). PHP retains a 401-always-`GislAuthError` shortcut here so
        // legacy callers matching on `instanceof GislAuthError` for the
        // pre-typed `invalid_api_key` envelope (`GislClientTest::testFailureEnvelope401ThrowsGislAuthError`,
        // shipped in B1 scaffold) keep working. The typed payload is `null`
        // in this fallback path. 403 has no symmetric fallback — TS doesn't
        // either.
        if ($statusCode === 401) {
            throw new GislAuthError(
                $message,
                $statusCode,
                $errorCode,
                $decoded,
                $messageKey,
                $locale,
                $messageParams,
                null,
                $responseHeaders,
                $contentLanguage,
            );
        }

        throw new GislApiError(
            $message,
            $statusCode,
            $errorCode,
            $decoded,
            $messageKey,
            $locale,
            $messageParams,
            $responseHeaders,
            $contentLanguage,
        );
    }

    /**
     * Shape check for the `details` array of a validation envelope. Each
     * entry must be an associative array carrying at least a `message`
     * string — the only field marked required on
     * `ValidationErrorEnvelopeDetailsInner` in the v2 contract. `field`,
     * `operation`, `option`, plus the localisation triple are all optional.
     *
     * Envelopes whose `details` use a different key (e.g. legacy `reason`
     * from the v1 contract used in `testFailureEnvelope4xxThrowsGislApiErrorWithPayload`)
     * intentionally return false here so the dispatch falls through to the
     * generic {@see GislApiError} path.
     *
     * @param array<int|string, mixed> $details
     */
    private function isValidationDetails(array $details): bool
    {
        // Reject associative shapes — validation envelopes always emit a list.
        if (!\array_is_list($details)) {
            return false;
        }
        foreach ($details as $entry) {
            if (!\is_array($entry)) {
                return false;
            }
            if (!isset($entry['message']) || !\is_string($entry['message'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Validity check for the `violations` array carried by
     * {@see FeatureTierRestrictedResponse} and {@see FeatureNotAvailableResponse}.
     * Both contracts require a non-empty list of {@see FeatureViolation}
     * with a string `feature` field — anything less means the typed DTO
     * deserialised but won't carry the contract-pinned shape, so we fall
     * through to the generic `GislApiError`.
     *
     * @param mixed $violations
     */
    private function areFeatureViolations(mixed $violations): bool
    {
        // Note on empty-array divergence from TS: TS dispatches to the typed
        // subclass even with `violations: []` (`[].every(...) === true`). PHP
        // cannot match — the generated DTO's `setViolations` throws on
        // `count < 1` (the contract pins `minItems: 1`), so `tryDeserialize`
        // returns null upstream and we never reach this check. Empty
        // violations is a malformed-envelope case anyway: the server
        // contract guarantees at least one violation when the envelope
        // exists, so falling through to base `GislApiError` is the correct
        // behaviour for that wire shape. Code-reviewer round 2 flagged the
        // divergence; this comment is the agreed resolution.
        if (!\is_array($violations) || $violations === []) {
            return false;
        }
        foreach ($violations as $violation) {
            if (!\is_object($violation)) {
                return false;
            }
            if (!\method_exists($violation, 'getFeature')) {
                return false;
            }
            if (!\is_string($violation->getFeature())) {
                return false;
            }
        }
        return true;
    }

    /**
     * Defense-in-depth wrapper around {@see ObjectSerializer::deserialize}.
     * Returns null on any throwable so callers can fall through to the
     * generic dispatch instead of throwing a half-constructed typed error.
     *
     * @template T of object
     * @param class-string<T>      $modelClass
     * @param array<string, mixed> $data
     * @return T|null
     */
    private function tryDeserialize(string $modelClass, array $data): ?object
    {
        try {
            /** @var T $instance */
            $instance = ObjectSerializer::deserialize($data, $modelClass, []);
            return $instance;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Hydrate a generated DTO from the unwrapped `data` payload via the
     * generated `ObjectSerializer::deserialize`. This is recursive — nested
     * fields like `WorkflowStatusResponse::jobs` (`JobStatus[]`) and
     * `UploadResponse::constraints_applied` come back as their typed
     * objects, not raw arrays. Direct construction (`new $modelClass($data)`)
     * is shallow and would leave nested fields as untyped arrays — broken
     * for any caller using getter chains like `$result->getJobs()[0]->getJobId()`.
     *
     * @template T of object
     * @param class-string<T>      $modelClass
     * @param array<string, mixed> $data
     * @return T
     */
    private function hydrate(string $modelClass, array $data): object
    {
        /** @var T $instance */
        $instance = ObjectSerializer::deserialize($data, $modelClass, []);
        return $instance;
    }

    /**
     * @param array<mixed, mixed> $data
     */
    private function jsonEncode(array $data): string
    {
        try {
            return \json_encode($data, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new GislError("Failed to JSON-encode request body: " . $e->getMessage(), 0, $e);
        }
    }

    private function generateMultipartBoundary(): string
    {
        // 32 hex chars; collision-free in practice for upload boundaries.
        return '----GislSdkBoundary' . \bin2hex(\random_bytes(16));
    }

    /**
     * Reject a multipart-part Content-Type that would inject CR/LF/NUL into the
     * raw header bytes. Authoritative wire-assembly guard: `UploadOptions`
     * validates `$contentType` at construction, but the property is public and
     * mutable, so this re-checks the actual value the moment it is written into
     * the form-data header — bypass-proof regardless of post-construction
     * mutation. Mirrors the filename guard in the body builders.
     */
    private function assertContentTypeHeaderSafe(?string $contentType): void
    {
        if ($contentType !== null && \preg_match('/[\r\n\x00]/', $contentType) === 1) {
            throw new GislConfigError(
                'contentType contains illegal characters for a multipart Content-Type '
                . 'header (no CR, LF, or NUL allowed): ' . \var_export($contentType, true),
            );
        }
    }

    /**
     * Reject a multipart-part filename that would break the Content-Disposition
     * header OR violate the upload contract. Authoritative wire-assembly guard:
     * {@see UploadOptions} validates `$filename` at construction, but the
     * property is public + mutable, so this re-checks the actual value the
     * moment it is written into the form-data header — bypass-proof regardless
     * of post-construction mutation (fFwaKsN5, codex r2). Rejects `"`/CR/LF/NUL
     * (header injection) PLUS path separators + over-255-bytes (the upload
     * contract's `^[^/\\]+$`, max 255). A path input's basename is always bare
     * and short, so only a hostile filename override is affected.
     */
    private function assertFilenameHeaderSafe(string $fileName): void
    {
        if (\preg_match('/["\r\n\x00]/', $fileName) === 1) {
            throw new GislConfigError(
                'Filename contains illegal characters for multipart Content-Disposition '
                . '(no `"`, CR, LF, or NUL allowed): ' . \var_export($fileName, true),
            );
        }
        if (\str_contains($fileName, '/') || \str_contains($fileName, '\\') || \strlen($fileName) > 255) {
            throw new GislConfigError(
                'Filename must be a bare name with no path separators (`/` or `\\`) '
                . 'and at most 255 bytes: ' . \var_export($fileName, true),
            );
        }
    }

    private function buildSingleShotMultipartBody(
        string $boundary,
        UploadSource $source,
        string $fileName,
        ?string $contentType = null,
    ): \Psr\Http\Message\StreamInterface {
        // RFC 7578 §4.2 requires Content-Disposition `filename` values to be
        // quoted-string per RFC 2616. A filename containing `"`, CR, or LF
        // would either break the header or inject body content. Reject
        // loudly rather than silently sanitising — bad filenames are bugs in
        // the caller's data pipeline that should surface clearly.
        $this->assertFilenameHeaderSafe($fileName);
        $this->assertContentTypeHeaderSafe($contentType);

        $contents = $source->readAll();

        // Single FormData field named "file" with filename. Mirrors the TS
        // singleUpload() at packages/typescript/src/client.ts:687-701.
        $crlf = "\r\n";
        $body = "--{$boundary}{$crlf}"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"{$fileName}\"{$crlf}"
            . "Content-Type: " . ($contentType ?? 'application/octet-stream') . $crlf
            . $crlf
            . $contents . $crlf
            . "--{$boundary}--{$crlf}";

        return $this->streamFactory->createStream($body);
    }

    /**
     * Build the multipart/form-data body for `/api/uploads/multipart/initiate`.
     *
     * Wire shape (mirrors packages/typescript/src/client.ts:714-753):
     *   - `file`           — the first chunk (raw bytes), with filename
     *   - `filename`       — repeated as a plain text field for servers that
     *                        prefer it apart from Content-Disposition
     *   - `total_size`     — total file size in bytes, base-10 string
     *   - `metadata_hint`  — optional JSON-stringified hint for server-side
     *                        first-chunk classification (snake_case keys via
     *                        the generated `*ToJSON` shape)
     */
    private function buildMultipartInitiateBody(
        string $boundary,
        UploadSource $source,
        string $fileName,
        int $firstChunkSize,
        int $totalSize,
        ?\Gisl\Generated\OpenApi\Model\MultipartInitiateRequestMetadataHint $metadataHint,
        ?string $contentType = null,
    ): \Psr\Http\Message\StreamInterface {
        $this->assertFilenameHeaderSafe($fileName);
        $this->assertContentTypeHeaderSafe($contentType);

        $firstChunkBytes = $this->readChunk($source, 0, $firstChunkSize);

        $crlf = "\r\n";
        $body = "--{$boundary}{$crlf}"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"{$fileName}\"{$crlf}"
            . "Content-Type: " . ($contentType ?? 'application/octet-stream') . $crlf
            . $crlf
            . $firstChunkBytes . $crlf
            . "--{$boundary}{$crlf}"
            . "Content-Disposition: form-data; name=\"filename\"{$crlf}"
            . $crlf
            . $fileName . $crlf
            . "--{$boundary}{$crlf}"
            . "Content-Disposition: form-data; name=\"total_size\"{$crlf}"
            . $crlf
            . $totalSize . $crlf;

        if ($metadataHint !== null) {
            // Generated DTOs are tagged-array friendly via ObjectSerializer's
            // sanitizer — produces a stdClass with snake_case keys
            // (`duration_seconds`, `width`, `height`) so the server's
            // first-chunk classifier sees the contract-pinned wire shape, not
            // the camelCase PHP getters. JSON_FORCE_OBJECT keeps even an
            // all-null hint object encoded as `{}` rather than `[]`.
            $hintWire = ObjectSerializer::sanitizeForSerialization($metadataHint);
            try {
                $hintJson = \json_encode($hintWire, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
            } catch (\JsonException $e) {
                throw new GislError("Failed to JSON-encode metadata_hint: {$e->getMessage()}", 0, $e);
            }
            $body .= "--{$boundary}{$crlf}"
                . "Content-Disposition: form-data; name=\"metadata_hint\"{$crlf}"
                . $crlf
                . $hintJson . $crlf;
        }

        $body .= "--{$boundary}--{$crlf}";

        return $this->streamFactory->createStream($body);
    }
}
