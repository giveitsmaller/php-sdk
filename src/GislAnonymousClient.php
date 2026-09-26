<?php

declare(strict_types=1);

namespace Gisl\Sdk;

use Gisl\Generated\OpenApi\Model\AudioWatermarkDecodeRequest;
use Gisl\Generated\OpenApi\Model\BillingCheckoutRequest;
use Gisl\Generated\OpenApi\Model\ExternalImportRequest;
use Gisl\Generated\OpenApi\Model\LoginUserRequest;
use Gisl\Generated\OpenApi\Model\UploadResponse;
use Gisl\Generated\OpenApi\Model\WorkflowCreateResponse;
use Gisl\Generated\OpenApi\Model\WorkflowDownloadResponse;
use Gisl\Generated\OpenApi\Model\WorkflowStatusResponse;
use Gisl\Generated\OpenApi\Model\RetryResponse;
use Gisl\Sdk\Errors\GislFeatureRequiresAuthError;
use Gisl\Sdk\Http\MultipartPartUploader;
use Gisl\Sdk\Http\UploadSource;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The client {@see Gisl::anonymous()} returns: an ergonomic client with NO
 * credential, gated to the methods the API accepts from a guest (`OuegCUtq`).
 *
 * PHP has no Proxy, so the TS `wrapAnonymous` gate is this subclass: every
 * {@see GislClient} method NOT in {@see Gisl::ANONYMOUS_ALLOWLIST} is
 * overridden to throw {@see GislFeatureRequiresAuthError} before any I/O.
 * Because PHP dispatches `$this->method()` virtually, the gate also holds for
 * the SDK's own internal calls — the ergonomic `credits()` reaching
 * `getCreditsBalance()`, or a `probe_pending` recovery reaching
 * `waitForProbe()`, meet it exactly as a caller would.
 * `AnonymousAllowlistConformanceTest` fails if a public method is neither
 * allowlisted nor overridden here, and pins the allowlist to the contract's
 * endpoint auth in both directions.
 *
 * It also threads the workflow capability token: the `cap` an anonymous create
 * returns is remembered per workflow and sent as `X-Workflow-Capability` on
 * that workflow's status / downloads / events reads (and so on every poll of
 * {@see waitForWorkflow()}), so `run()` needs nothing from the caller. An
 * explicit `$capability` argument wins over the remembered one. A workflow
 * this client did not create has no remembered `cap`.
 */
final class GislAnonymousClient extends GislErgonomicClient
{
    /**
     * The largest file a guest can upload: the contract's single-shot cap
     * (`UploadThresholds.single_shot_max_bytes`). The API's own guest cap is
     * 10 MiB, but multipart needs an account (compression_api security.yaml
     * requires auth on multipart initiate), so nothing above single-shot can
     * reach it.
     */
    public const MAX_UPLOAD_BYTES = GislClientConfig::DEFAULT_MULTIPART_THRESHOLD_BYTES;

    /**
     * workflowId => `cap`. An object, not an array, so a
     * {@see withPresetDefaults()} clone shares it with its parent (the TS
     * derive shares the same gated client the same way).
     *
     * @var \ArrayObject<string, string>
     */
    private readonly \ArrayObject $capabilityByWorkflow;

    public function __construct(
        GislClientConfig $config,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?PresetDefaults $presetDefaults = null,
        ?PresetDefaults $scopedPresetDefaults = null,
        ?MultipartPartUploader $partUploader = null,
    ) {
        parent::__construct(
            $config,
            $httpClient,
            $requestFactory,
            $streamFactory,
            $presetDefaults,
            $scopedPresetDefaults,
            $partUploader,
        );
        /** @var \ArrayObject<string, string> $capabilityByWorkflow */
        $capabilityByWorkflow = new \ArrayObject();
        $this->capabilityByWorkflow = $capabilityByWorkflow;
    }

    // -----------------------------------------------------------------
    // Allowlisted, with behaviour
    // -----------------------------------------------------------------

    /**
     * A guest upload is single-shot (`POST /api/uploads`): multipart needs an
     * account, so a file over {@see MAX_UPLOAD_BYTES} is refused here before
     * any request, as is a resume (`resumeUploadId` walks the auth-only
     * `/status` and `/presign`). A non-seekable stream the caller asked to
     * buffer is buffered HERE, so its size is known before the check.
     */
    public function uploadFile(
        mixed $filePathOrResource,
        ?UploadOptions $options = null,
    ): UploadResponse {
        if (\is_string($options?->resumeUploadId) && $options->resumeUploadId !== '') {
            throw new GislFeatureRequiresAuthError(
                operation: 'uploadFile',
                message: 'Resuming a multipart upload (resumeUploadId) is not available on an anonymous '
                    . 'client: multipart uploads require an account. Use Gisl::create(apiKey: ...).',
            );
        }
        if (!\is_resource($filePathOrResource) || $options?->bufferNonSeekable !== true) {
            $this->assertSingleShotSize($filePathOrResource);
            return parent::uploadFile($filePathOrResource, $options);
        }
        // Buffered here so the size is known; the parent then sees a seekable
        // stream and does not buffer again. BOUNDED at the guest cap + 1 byte
        // (codex on the anonymous PR): an oversized or endless stream must not
        // be copied in full before it is refused.
        $buffered = self::bufferAtMostCapPlusOne($filePathOrResource);
        try {
            $this->assertSingleShotSize($buffered);
            return parent::uploadFile($buffered, $options);
        } finally {
            if ($buffered !== $filePathOrResource && \is_resource($buffered)) {
                \fclose($buffered);
            }
        }
    }

    /**
     * A seekable stream is returned as is. A non-seekable one is copied into
     * php://temp, reading at most MAX_UPLOAD_BYTES + 1 bytes: one byte past the
     * cap is enough to refuse it, and nothing more is read.
     *
     * @param resource $resource
     * @return resource
     */
    private static function bufferAtMostCapPlusOne(mixed $resource): mixed
    {
        $meta = \stream_get_meta_data($resource);
        if ($meta['seekable'] === true) {
            return $resource;
        }
        $copy = \fopen('php://temp/maxmemory:' . (self::MAX_UPLOAD_BYTES + 1), 'w+b');
        if ($copy === false) {
            throw new Errors\GislConfigError('Unable to open a php://temp buffer for the non-seekable stream.');
        }
        $copied = \stream_copy_to_stream($resource, $copy, self::MAX_UPLOAD_BYTES + 1);
        if ($copied === false || !\rewind($copy)) {
            \fclose($copy);
            throw new Errors\GislConfigError('Unable to buffer the non-seekable stream (copy to php://temp failed).');
        }
        if ($copied > self::MAX_UPLOAD_BYTES) {
            \fclose($copy);
            throw new GislFeatureRequiresAuthError(
                operation: 'uploadFile',
                message: 'This stream is larger than ' . self::MAX_UPLOAD_BYTES . ' bytes. Anonymous uploads are '
                    . 'single-shot, up to ' . self::MAX_UPLOAD_BYTES . ' bytes; larger files need an account '
                    . '(multipart upload requires authentication). Use Gisl::create(apiKey: ...).',
            );
        }
        return $copy;
    }

    /**
     * Refuse a file the single-shot path cannot carry. An input the SDK
     * cannot size (a missing path, a non-seekable stream) is left to
     * {@see GislClient::uploadFile()}'s own error.
     */
    private function assertSingleShotSize(mixed $filePathOrResource): void
    {
        try {
            $size = \is_resource($filePathOrResource)
                ? UploadSource::fromStream($filePathOrResource)->size()
                : (\is_string($filePathOrResource) ? UploadSource::fromPath($filePathOrResource)->size() : null);
        } catch (Errors\GislConfigError) {
            return;
        }
        if ($size !== null && $size > self::MAX_UPLOAD_BYTES) {
            throw new GislFeatureRequiresAuthError(
                operation: 'uploadFile',
                message: "This file is {$size} bytes. Anonymous uploads are single-shot, up to "
                    . self::MAX_UPLOAD_BYTES . ' bytes; larger files need an account (multipart upload '
                    . 'requires authentication). Use Gisl::create(apiKey: ...).',
            );
        }
    }

    public function createWorkflow(WorkflowCreatePayload $payload): WorkflowCreateResponse
    {
        $created = parent::createWorkflow($payload);
        $cap = $created->getCap();
        $workflowId = $created->getWorkflowId();
        if (\is_string($cap) && $cap !== '' && \is_string($workflowId) && $workflowId !== '') {
            $this->capabilityByWorkflow[$workflowId] = $cap;
        }
        return $created;
    }

    public function getWorkflowStatus(
        string $workflowId,
        ?string $capability = null,
    ): WorkflowStatusResponse {
        return parent::getWorkflowStatus($workflowId, $capability ?? $this->rememberedCapability($workflowId));
    }

    public function getWorkflowDownloads(
        string $workflowId,
        ?string $capability = null,
    ): WorkflowDownloadResponse {
        return parent::getWorkflowDownloads($workflowId, $capability ?? $this->rememberedCapability($workflowId));
    }

    /**
     * @param ?callable(GislSseParseFailure):void $onParseError
     * @return \Generator<int, GislSseEvent, void, void>
     */
    public function streamEvents(
        string $workflowId,
        ?string $capability = null,
        ?callable $onParseError = null,
    ): \Generator {
        return parent::streamEvents(
            $workflowId,
            $capability ?? $this->rememberedCapability($workflowId),
            $onParseError,
        );
    }

    /**
     * A no-op here. The probe endpoint is auth-only and the wait is best-effort
     * by design (a give-up already proceeds to create), so a guest skips it and
     * the API answers the create itself — for a guest's video, with its typed
     * anonymous refusal rather than an auth error from the probe.
     */
    public function maybeWaitForVideoProbe(
        string $fileId,
        bool $enabled,
        bool $isVideo,
        ?int $sizeBytes,
        ?int $timeoutMs = null,
        ?Cancellation $cancellation = null,
    ): void {
    }

    private function rememberedCapability(string $workflowId): ?string
    {
        return $this->capabilityByWorkflow[$workflowId] ?? null;
    }

    // -----------------------------------------------------------------
    // Not available to a guest: each reaches an auth-only endpoint, except
    // login (see its note). Thrown before any I/O.
    // -----------------------------------------------------------------

    public function getUploadStatus(string $uploadId): never
    {
        throw self::requiresAuth(__FUNCTION__);
    }

    /** @param list<int> $partNumbers */
    public function presignParts(string $uploadId, array $partNumbers, int $totalParts): never
    {
        throw self::requiresAuth(__FUNCTION__);
    }

    public function keepaliveUpload(string $uploadId): never
    {
        throw self::requiresAuth(__FUNCTION__);
    }

    public function listWorkflows(?string $cursor = null, ?int $limit = null, ?bool $archived = null): never
    {
        throw self::requiresAuth(__FUNCTION__);
    }

    public function workflows(?int $limit = null, ?bool $archived = null): never
    {
        throw self::requiresAuth(__FUNCTION__);
    }

    public function cancelWorkflow(string $workflowId): never
    {
        throw self::requiresAuth(__FUNCTION__);
    }

    public function archiveWorkflow(string $workflowId): never
    {
        throw self::requiresAuth(__FUNCTION__);
    }

    public function restoreWorkflow(string $workflowId): never
    {
        throw self::requiresAuth(__FUNCTION__);
    }

    public function resumeWorkflow(string $workflowId): never
    {
        throw self::requiresAuth(__FUNCTION__);
    }

    /**
     * The contract marks retry `optional`, but the API requires auth on it
     * (compression_api security.yaml, measured 2026-09-26). The API wins.
     */
    public function retryOperation(string $operationId): never
    {
        throw self::requiresAuth(__FUNCTION__);
    }

    /**
     * `POST /api/auth/login` accepts guests, but logging in turns this into a
     * session client, and an anonymous client carries no credential of any
     * kind. To log in, use `Gisl::create(useSessionCookie: true)`.
     */
    public function login(LoginUserRequest $credentials): never
    {
        throw self::requiresAuth(__FUNCTION__);
    }

    public function logout(): never
    {
        throw self::requiresAuth(__FUNCTION__);
    }

    public function createCheckoutSession(BillingCheckoutRequest $payload): never
    {
        throw self::requiresAuth(__FUNCTION__);
    }

    public function getCreditsBalance(): never
    {
        throw self::requiresAuth(__FUNCTION__);
    }

    public function getCreditsUsage(?CreditsUsageOptions $options = null): never
    {
        throw self::requiresAuth(__FUNCTION__);
    }

    public function getAccountLimits(): never
    {
        throw self::requiresAuth(__FUNCTION__);
    }

    public function getProfile(): never
    {
        throw self::requiresAuth(__FUNCTION__);
    }

    public function probeUpload(string $fileId): never
    {
        throw self::requiresAuth(__FUNCTION__);
    }

    public function waitForProbe(string $fileId, ?ProbeWaitOptions $options = null): never
    {
        throw self::requiresAuth(__FUNCTION__);
    }

    /** @param list<string> $fileIds */
    public function preflightClips(array $fileIds): never
    {
        throw self::requiresAuth(__FUNCTION__);
    }

    public function decodeAudioWatermark(AudioWatermarkDecodeRequest $payload): never
    {
        throw self::requiresAuth(__FUNCTION__);
    }

    public function createExternalImport(ExternalImportRequest $payload): never
    {
        throw self::requiresAuth(__FUNCTION__);
    }

    private static function requiresAuth(string $operation): GislFeatureRequiresAuthError
    {
        return new GislFeatureRequiresAuthError(
            operation: $operation,
            message: "Operation '{$operation}' is not available on an anonymous client: the API requires "
                . 'authentication for it. Use Gisl::create(apiKey: ...) for authenticated access.',
        );
    }
}
