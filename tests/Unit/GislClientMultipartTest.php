<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Generated\OpenApi\Model\UploadResponse;
use Gisl\Sdk\Errors\GislError;
use Gisl\Sdk\Errors\GislMultipartPartCountError;
use Gisl\Sdk\Errors\GislMultipartPartError;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\Http\MultipartPartUploader;
use Gisl\Sdk\UploadOptions;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Multipart sequential upload coverage. The single-shot path is exercised in
 * {@see GislClientTest}; this file covers the chunked path.
 */
#[CoversClass(GislClient::class)]
final class GislClientMultipartTest extends TestCase
{
    private const FIRST_CHUNK_SIZE = 8_388_608;  // 8 MiB — DEFAULT_MULTIPART_FIRST_CHUNK_SIZE_BYTES
    // 16 MiB — CON-1 (contracts z4GDTUMx) raised MultipartInitiateResponse
    // recommended_chunk_size minimum 5 MiB -> 16 MiB; the mocked initiate
    // envelope must carry a CON-1-valid value or the regenerated generated
    // model rejects it at deserialize (InvalidArgumentException).
    private const CHUNK_SIZE       = 16_777_216;
    private const TOTAL_SIZE       = 25_165_824; // FIRST_CHUNK_SIZE + CHUNK_SIZE = 1 remaining chunk
    // UUID v7 — matches the regex the generated DTO enforces.
    private const UPLOAD_ID        = '01936fb2-0000-7000-8000-000000000aaa';

    private HttpFactory $factory;
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (\file_exists($path)) {
                @\unlink($path);
            }
        }
    }

    public function testHappyPathRoutesToMultipartAndSynthesisesUploadResponse(): void
    {
        $filePath = $this->writeBigFile(self::TOTAL_SIZE);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->initiateEnvelope(remainingChunks: 1, chunkSize: self::CHUNK_SIZE)),
            $this->s3Response('"etag-part-2"'),
            $this->jsonResponse(200, $this->completeEnvelope('completed')),
        ], $captured);

        $client = $this->makeClient($http);
        $progress = [];
        $options = new UploadOptions(
            onProgress: static function (int $uploaded, int $total) use (&$progress): void {
                $progress[] = [$uploaded, $total];
            },
        );

        $result = $client->uploadFile($filePath, $options);

        self::assertInstanceOf(UploadResponse::class, $result);
        self::assertSame(self::UPLOAD_ID, $result->getFileId());
        self::assertSame('image/jpeg', $result->getMimeType());
        self::assertSame(self::TOTAL_SIZE, $result->getSizeBytes());
        self::assertSame(\basename($filePath), $result->getOriginalName());

        // Three requests: initiate, S3 PUT, complete.
        self::assertCount(3, $captured);

        [$initiate, $s3Put, $complete] = $captured;
        self::assertSame('POST', $initiate->getMethod());
        self::assertStringEndsWith('/api/uploads/multipart/initiate', (string) $initiate->getUri());
        self::assertStringStartsWith('multipart/form-data; boundary=', $initiate->getHeaderLine('Content-Type'));

        self::assertSame('PUT', $s3Put->getMethod());
        self::assertSame('https://s3.example.com/upload/2', (string) $s3Put->getUri());
        self::assertSame((string) self::CHUNK_SIZE, $s3Put->getHeaderLine('Content-Length'));
        // S3 PUTs MUST NOT carry the SDK's API auth header — those creds are
        // not for AWS.
        self::assertSame('', $s3Put->getHeaderLine('Authorization'));

        self::assertSame('POST', $complete->getMethod());
        self::assertStringEndsWith('/api/uploads/multipart/complete', (string) $complete->getUri());
        $completeBody = \json_decode((string) $complete->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([
            'upload_id' => self::UPLOAD_ID,
            'parts' => [['part_number' => 2, 'etag' => '"etag-part-2"']],
        ], $completeBody);

        // Progress should fire after first chunk and after the remaining chunk.
        self::assertSame([
            [self::FIRST_CHUNK_SIZE, self::TOTAL_SIZE],
            [self::TOTAL_SIZE, self::TOTAL_SIZE],
        ], $progress);
    }

    public function testDelegatesPartPutsToInjectedConcurrentUploader(): void
    {
        $filePath = $this->writeBigFile(self::TOTAL_SIZE);

        // No S3 PUT in the queue — the injected uploader owns the part PUTs,
        // so only initiate + complete go through the PSR-18 client.
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->initiateEnvelope(remainingChunks: 1, chunkSize: self::CHUNK_SIZE)),
            $this->jsonResponse(200, $this->completeEnvelope('completed')),
        ], $captured);

        $fake = new class implements MultipartPartUploader {
            /** @var list<array{part: int, offset: int, length: int, concurrency: int}> */
            public array $seen = [];

            public function uploadParts(
                string $filePath,
                array $parts,
                string $uploadId,
                int $concurrency,
                callable $onPartComplete,
            ): array {
                $etags = [];
                foreach ($parts as $d) {
                    $pn = $d['partNumber'];
                    $this->seen[] = [
                        'part' => $pn,
                        'offset' => $d['offset'],
                        'length' => $d['length'],
                        'concurrency' => $concurrency,
                    ];
                    $etags[$pn] = "\"etag-{$pn}\"";
                    $onPartComplete($pn, $d['length']);
                }

                return $etags;
            }
        };

        $progress = [];
        $client = $this->makeClient($http, partUploader: $fake);
        $result = $client->uploadFile(
            $filePath,
            new UploadOptions(
                onProgress: static function (int $u, int $t) use (&$progress): void {
                    $progress[] = [$u, $t];
                },
            ),
        );

        self::assertSame(self::UPLOAD_ID, $result->getFileId());
        // Only initiate + complete hit HTTP — the part PUT went via the uploader.
        self::assertCount(2, $captured);
        // Uploader got the single remaining part (#2) at the right offset/length,
        // with the config's multipartConcurrency (default 4).
        self::assertSame([[
            'part' => 2,
            'offset' => self::FIRST_CHUNK_SIZE,
            'length' => self::CHUNK_SIZE,
            'concurrency' => 4,
        ]], $fake->seen);
        // /complete carries the uploader's ETag, part_number ascending.
        $completeBody = \json_decode((string) $captured[1]->getBody(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([
            'upload_id' => self::UPLOAD_ID,
            'parts' => [['part_number' => 2, 'etag' => '"etag-2"']],
        ], $completeBody);
        // Progress fires after the first chunk (initiate) + after the part.
        self::assertSame([
            [self::FIRST_CHUNK_SIZE, self::TOTAL_SIZE],
            [self::TOTAL_SIZE, self::TOTAL_SIZE],
        ], $progress);
    }

    public function testRetryOn5xxThenSucceeds(): void
    {
        $filePath = $this->writeBigFile(self::TOTAL_SIZE);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->initiateEnvelope(remainingChunks: 1, chunkSize: self::CHUNK_SIZE)),
            // First S3 PUT 503, second S3 PUT 200.
            new Response(503, [], 'service unavailable'),
            $this->s3Response('"etag-part-2"'),
            $this->jsonResponse(200, $this->completeEnvelope('completed')),
        ], $captured);

        $client = $this->makeClient($http, multipartRetryBaseMs: 0);
        $result = $client->uploadFile($filePath);

        self::assertSame(self::UPLOAD_ID, $result->getFileId());
        // initiate + 2x S3 PUT + complete = 4
        self::assertCount(4, $captured);
    }

    public function testRetryOn408ThenSucceeds(): void
    {
        // qz7MjNTy — 408 Request Timeout is a retryable S3-PUT status (cross-SDK
        // parity with the TS predicate). First S3 PUT 408, second 200 -> succeeds.
        $filePath = $this->writeBigFile(self::TOTAL_SIZE);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->initiateEnvelope(remainingChunks: 1, chunkSize: self::CHUNK_SIZE)),
            new Response(408, [], 'request timeout'),
            $this->s3Response('"etag-part-2"'),
            $this->jsonResponse(200, $this->completeEnvelope('completed')),
        ], $captured);

        $client = $this->makeClient($http, multipartRetryBaseMs: 0);
        $result = $client->uploadFile($filePath);

        self::assertSame(self::UPLOAD_ID, $result->getFileId());
        // initiate + 2x S3 PUT + complete = 4
        self::assertCount(4, $captured);
    }

    public function testNonRetryable4xxOnS3IsFatal(): void
    {
        $filePath = $this->writeBigFile(self::TOTAL_SIZE);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->initiateEnvelope(remainingChunks: 1, chunkSize: self::CHUNK_SIZE)),
            new Response(403, [], 'forbidden'),
        ], $captured);

        $client = $this->makeClient($http, multipartRetryBaseMs: 0);

        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/non-retryable/');
        $client->uploadFile($filePath);
    }

    public function testRetryExhaustionThrowsGislError(): void
    {
        $filePath = $this->writeBigFile(self::TOTAL_SIZE);

        // Three failures with default multipartMaxAttempts=3.
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->initiateEnvelope(remainingChunks: 1, chunkSize: self::CHUNK_SIZE)),
            new Response(500, [], ''),
            new Response(500, [], ''),
            new Response(500, [], ''),
        ], $captured);

        $client = $this->makeClient($http, multipartRetryBaseMs: 0);

        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/after 3 attempts/');
        $client->uploadFile($filePath);
    }

    /**
     * Parity with TS upload-streaming.test.ts: after the retries exhaust,
     * the throw is the TYPED GislMultipartPartError carrying the failing
     * part number + uploadId (not a bare GislError).
     */
    public function testRetryExhaustionThrowsTypedMultipartPartError(): void
    {
        $filePath = $this->writeBigFile(self::TOTAL_SIZE);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->initiateEnvelope(remainingChunks: 1, chunkSize: self::CHUNK_SIZE)),
            new Response(500, [], ''),
            new Response(500, [], ''),
            new Response(500, [], ''),
        ], $captured);

        $client = $this->makeClient($http, multipartRetryBaseMs: 0);

        try {
            $client->uploadFile($filePath);
            self::fail('Expected GislMultipartPartError');
        } catch (GislMultipartPartError $e) {
            self::assertInstanceOf(GislError::class, $e);
            self::assertSame(2, $e->partNumber);
            self::assertSame(self::UPLOAD_ID, $e->uploadId);
        }
    }

    /**
     * <=10 000-part S3 ceiling guard (Model A), reachable PHP path.
     *
     * Mirrors the TS upload-streaming.test.ts "client-side recompute"
     * case. The guard fires AFTER the initiate round-trip (totalParts /
     * chunkSize only exist on the initiate response) on the CLIENT-COMPUTED
     * part count: a 165 GiB input at the 16 MiB minimum chunk size yields
     * ~10 561 parts (post-SDK-2 #84; was ~12 000 with the old 5 MiB
     * minimum + ~50 GiB input). A sparse file gives the huge logical size
     * with ~0 bytes on disk (the guard throws before any chunk is read
     * past the 8 MiB first chunk).
     *
     * PHP <-> TS divergence (see testServerOverReportingPartsIsRejectedByGeneratedModel
     * below): in PHP the SERVER-reported `total_parts` branch of the guard
     * is unreachable — the generated MultipartInitiateResponse model
     * enforces the contract `maximum: 10000` (post-SDK-2 #84; was 500) and
     * throws at deserialization before the SDK guard runs. TS's generator
     * does not validate response bodies, so its server-branch IS reachable
     * and is covered there. Only the computed-parts branch is live in PHP.
     */
    public function testPartCountGuardThrowsViaComputedPartsForOversizeFile(): void
    {
        // 165 GiB sparse file — large enough that the client recompute
        // 1 + ceil((165GiB - 8MiB) / 16MiB) ~= 10561 > 10000 -> guard
        // fires. Realigned to 16 MiB chunk (SDK-2 #84) from the prior 5
        // MiB literal — see ticket 2hMemNSN.
        $filePath = $this->writeSparseFile(165 * 1024 * 1024 * 1024);

        // Valid, in-contract initiate: total_parts <= 10000 (post-SDK-2),
        // chunk = 16 MiB (the generated-model minimum). Use a small
        // total_parts so the plan-consistency guard fires AFTER the count
        // ceiling — the test asserts the count guard.
        $envelope = $this->initiateEnvelope(remainingChunks: 1, chunkSize: self::CHUNK_SIZE);
        $envelope['data']['total_parts'] = 7680;

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $envelope),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->uploadFile($filePath);
            self::fail('Expected GislMultipartPartCountError');
        } catch (GislMultipartPartCountError $e) {
            self::assertInstanceOf(GislError::class, $e);
            self::assertSame(10_000, $e->maxParts);
            self::assertGreaterThan(10_000, $e->requiredParts);
            // Guard fires before any S3 PUT — only the initiate call happened.
            self::assertCount(1, $captured);
        }
    }

    /**
     * Documents the PHP<->TS divergence: an out-of-contract initiate
     * response with total_parts > 10000 is rejected by the generated
     * MultipartInitiateResponse model's `maximum: 10000` validation at
     * deserialization time, BEFORE the SDK-level <=10k guard can run.
     * SDK-2 (#84) raised the contract maximum from 500 to 10000 in
     * lockstep with the 5->16 MiB chunk bump, so the new ceiling = the
     * S3 physical limit and the contract-ceiling-vs-S3-physical-ceiling
     * gap that ticket aBsqlMCc tracked under SDK-1 is now resolved.
     * SDK-1 keeps the S3-physical 10000 guard per the card; this test
     * pins the actual current PHP behaviour so the divergence is not
     * silently "fixed" or regressed.
     */
    public function testServerOverReportingPartsIsRejectedByGeneratedModel(): void
    {
        $filePath = $this->writeBigFile(self::TOTAL_SIZE);

        $envelope = $this->initiateEnvelope(remainingChunks: 0, chunkSize: self::CHUNK_SIZE);
        $envelope['data']['total_parts'] = 10_001; // out of contract (max 10000)

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $envelope),
        ], $captured);

        $client = $this->makeClient($http);

        // The generated model setter throws InvalidArgumentException for
        // total_parts > 10000 — surfaces before the SDK guard. (TS
        // divergence: its lax generator would let the guard fire instead.)
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/total_parts.*10000/');
        $client->uploadFile($filePath);
    }

    /**
     * Plan-consistency guard (codex review; TS<->PHP parity with
     * upload-streaming.test.ts "inconsistent initiate plan"). An initiate
     * plan that is internally inconsistent BELOW the <=10k cap (server
     * total_parts disagrees with the client recompute / presigned count)
     * must fail fast with a GislError BEFORE any S3 PUT — not upload a
     * mismatched range set and fail opaquely at /multipart/complete.
     */
    public function testInconsistentInitiatePlanFailsFastBeforeAnyPut(): void
    {
        $filePath = $this->writeBigFile(self::TOTAL_SIZE);

        // Consistent base (total_parts=2, 1 presigned), then override
        // total_parts to a value that disagrees with the client recompute
        // (2) but stays under the 10k ceiling.
        $envelope = $this->initiateEnvelope(remainingChunks: 1, chunkSize: self::CHUNK_SIZE);
        $envelope['data']['total_parts'] = 7;

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $envelope),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->uploadFile($filePath);
            self::fail('Expected GislError for an inconsistent initiate plan');
        } catch (GislMultipartPartCountError $e) {
            self::fail('Should NOT be the <=10k path: ' . $e->getMessage());
        } catch (GislError $e) {
            self::assertMatchesRegularExpression('/internally inconsistent/', $e->getMessage());
            // Failed before any S3 PUT — only the initiate call happened.
            self::assertCount(1, $captured);
        }
    }

    public function testTransportFailureRetriesAcrossAttempts(): void
    {
        $filePath = $this->writeBigFile(self::TOTAL_SIZE);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->initiateEnvelope(remainingChunks: 1, chunkSize: self::CHUNK_SIZE)),
            $this->transportException('connect failed'),
            $this->s3Response('"etag-part-2"'),
            $this->jsonResponse(200, $this->completeEnvelope('completed')),
        ], $captured);

        $client = $this->makeClient($http, multipartRetryBaseMs: 0);
        $result = $client->uploadFile($filePath);

        self::assertSame(self::UPLOAD_ID, $result->getFileId());
        self::assertCount(4, $captured);
    }

    public function testMissingEtagOnS3IsFatal(): void
    {
        $filePath = $this->writeBigFile(self::TOTAL_SIZE);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->initiateEnvelope(remainingChunks: 1, chunkSize: self::CHUNK_SIZE)),
            new Response(200, [], ''), // 2xx but no ETag header
        ], $captured);

        $client = $this->makeClient($http, multipartRetryBaseMs: 0);

        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/missing ETag/');
        $client->uploadFile($filePath);
    }

    public function testCompleteWithUnexpectedStatusFails(): void
    {
        $filePath = $this->writeBigFile(self::TOTAL_SIZE);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->initiateEnvelope(remainingChunks: 1, chunkSize: self::CHUNK_SIZE)),
            $this->s3Response('"etag-part-2"'),
            $this->jsonResponse(200, $this->completeEnvelope('aborted')),
        ], $captured);

        $client = $this->makeClient($http, multipartRetryBaseMs: 0);

        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/unexpected status: aborted/');
        $client->uploadFile($filePath);
    }

    public function testInitiateEnvelopeMissingConstraintsAppliedFails(): void
    {
        $filePath = $this->writeBigFile(self::TOTAL_SIZE);

        $envelope = $this->initiateEnvelope(remainingChunks: 1, chunkSize: self::CHUNK_SIZE);
        unset($envelope['data']['constraints_applied']);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $envelope),
            $this->s3Response('"etag-part-2"'),
            // The complete request will not be sent; we'll throw before it.
        ], $captured);

        $client = $this->makeClient($http, multipartRetryBaseMs: 0);

        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/constraints_applied/');
        $client->uploadFile($filePath);
    }

    public function testMultipleChunksAreUploadedInOrder(): void
    {
        // Two remaining chunks of CHUNK_SIZE each — total = 8 MiB + 10 MiB.
        $totalSize = self::FIRST_CHUNK_SIZE + 2 * self::CHUNK_SIZE;
        $filePath = $this->writeBigFile($totalSize);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->initiateEnvelope(remainingChunks: 2, chunkSize: self::CHUNK_SIZE)),
            $this->s3Response('"etag-2"'),
            $this->s3Response('"etag-3"'),
            $this->jsonResponse(200, $this->completeEnvelope('completed')),
        ], $captured);

        $client = $this->makeClient($http);
        $result = $client->uploadFile($filePath);

        self::assertSame(self::UPLOAD_ID, $result->getFileId());
        self::assertCount(4, $captured);
        self::assertSame('https://s3.example.com/upload/2', (string) $captured[1]->getUri());
        self::assertSame('https://s3.example.com/upload/3', (string) $captured[2]->getUri());

        $completeBody = \json_decode((string) $captured[3]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([
            ['part_number' => 2, 'etag' => '"etag-2"'],
            ['part_number' => 3, 'etag' => '"etag-3"'],
        ], $completeBody['parts']);
    }

    public function testFileIdComesFromCompleteEnvelopeNotInitiate(): void
    {
        // Idempotency replay / server-side rebind scenario: complete may
        // return a different upload_id than initiate. The synthesised
        // UploadResponse must carry the COMPLETE envelope's upload_id, which
        // is the authoritative one (matches TS reference behaviour).
        $filePath = $this->writeBigFile(self::TOTAL_SIZE);
        $rebindUploadId = '01936fb2-0000-7000-8000-000000000ccc';

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->initiateEnvelope(remainingChunks: 1, chunkSize: self::CHUNK_SIZE)),
            $this->s3Response('"etag-part-2"'),
            $this->jsonResponse(200, [
                'success' => true,
                'data' => ['upload_id' => $rebindUploadId, 'status' => 'completed'],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $result = $client->uploadFile($filePath);

        self::assertSame($rebindUploadId, $result->getFileId());

        // The complete REQUEST body still carries the initiate's upload_id
        // (that is the request the SERVER expects — it points to the upload
        // session being completed). The rebind only affects the RESPONSE.
        $completeRequestBody = \json_decode((string) $captured[2]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(self::UPLOAD_ID, $completeRequestBody['upload_id']);
    }

    public function testCompleteEnvelopeMissingUploadIdFails(): void
    {
        $filePath = $this->writeBigFile(self::TOTAL_SIZE);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->initiateEnvelope(remainingChunks: 1, chunkSize: self::CHUNK_SIZE)),
            $this->s3Response('"etag-part-2"'),
            $this->jsonResponse(200, [
                'success' => true,
                'data' => ['status' => 'completed'], // upload_id missing
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/Multipart complete response missing upload_id/');
        $client->uploadFile($filePath);
    }

    public function testEmptyMetadataHintEncodesAsJsonObjectNotArray(): void
    {
        // An all-null hint object's wire shape must be `{}` (JSON object)
        // rather than `[]` (JSON array). The server's first-chunk classifier
        // expects an object shape; an array would be silently dropped or
        // explicitly rejected by JSON-schema validation.
        $filePath = $this->writeBigFile(self::TOTAL_SIZE);
        $hint = new \Gisl\Generated\OpenApi\Model\MultipartInitiateRequestMetadataHint([]);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->initiateEnvelope(remainingChunks: 1, chunkSize: self::CHUNK_SIZE)),
            $this->s3Response('"etag-part-2"'),
            $this->jsonResponse(200, $this->completeEnvelope('completed')),
        ], $captured);

        $client = $this->makeClient($http);
        $client->uploadFile($filePath, new UploadOptions(metadataHint: $hint));

        $initiateBody = (string) $captured[0]->getBody();
        // The hint field carries `{}` — extract its content from the form-data
        // section and check the literal token. `[]` would indicate the bug.
        self::assertMatchesRegularExpression(
            '/name="metadata_hint"\r\n\r\n\{[^}]*\}\r\n/',
            $initiateBody,
        );
        self::assertStringNotContainsString(
            'name="metadata_hint"' . "\r\n\r\n[]" . "\r\n",
            $initiateBody,
        );
    }

    public function testMetadataHintIsForwardedAsJsonField(): void
    {
        $filePath = $this->writeBigFile(self::TOTAL_SIZE);
        $hint = new \Gisl\Generated\OpenApi\Model\MultipartInitiateRequestMetadataHint([
            'duration_seconds' => 42,
            'width' => 1920,
            'height' => 1080,
        ]);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->initiateEnvelope(remainingChunks: 1, chunkSize: self::CHUNK_SIZE)),
            $this->s3Response('"etag-part-2"'),
            $this->jsonResponse(200, $this->completeEnvelope('completed')),
        ], $captured);

        $client = $this->makeClient($http);
        $client->uploadFile($filePath, new UploadOptions(metadataHint: $hint));

        $initiateBody = (string) $captured[0]->getBody();
        self::assertStringContainsString('name="metadata_hint"', $initiateBody);
        // Snake-case keys at the wire boundary.
        self::assertStringContainsString('"duration_seconds":42', $initiateBody);
        self::assertStringContainsString('"width":1920', $initiateBody);
        self::assertStringContainsString('"height":1080', $initiateBody);
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /**
     * @param list<ResponseInterface|\Throwable> $queue
     * @param-out list<RequestInterface> $captured
     */
    private function stubClient(array $queue, array &$captured = []): ClientInterface
    {
        $captured = [];
        return new class ($queue, $captured) implements ClientInterface {
            /** @var list<ResponseInterface|\Throwable> */
            private array $queue;
            /** @var list<RequestInterface> */
            private array $captured;

            /**
             * @param list<ResponseInterface|\Throwable> $queue
             * @param list<RequestInterface>             $captured
             */
            public function __construct(array $queue, array &$captured)
            {
                $this->queue = $queue;
                $this->captured = &$captured;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;
                $next = \array_shift($this->queue);
                if ($next === null) {
                    throw new \RuntimeException('Stub PSR-18 client: response queue exhausted');
                }
                if ($next instanceof \Throwable) {
                    if (!$next instanceof ClientExceptionInterface) {
                        throw new \LogicException(
                            'Queued throwables must implement ClientExceptionInterface; got ' . \get_class($next),
                        );
                    }
                    throw $next;
                }
                return $next;
            }
        };
    }

    private function makeClient(
        ClientInterface $http,
        ?int $multipartRetryBaseMs = null,
        ?MultipartPartUploader $partUploader = null,
    ): GislClient {
        return new GislClient(
            config: new GislClientConfig(
                baseUrl: 'https://api.example.com',
                apiKey: 'sk_test',
                multipartThresholdBytes: self::FIRST_CHUNK_SIZE, // 8 MiB
                multipartRetryBaseMs: $multipartRetryBaseMs ?? 0,
            ),
            httpClient: $http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
            partUploader: $partUploader,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            \json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function s3Response(string $etag): ResponseInterface
    {
        return new Response(200, ['ETag' => $etag], '');
    }

    private function transportException(string $message): ClientExceptionInterface
    {
        return new class ($message) extends \RuntimeException implements ClientExceptionInterface {
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function initiateEnvelope(int $remainingChunks, int $chunkSize): array
    {
        $presigned = [];
        for ($i = 0; $i < $remainingChunks; $i++) {
            $partNumber = $i + 2; // first chunk is part 1 (uploaded via initiate)
            $presigned[] = [
                'part_number' => $partNumber,
                'url' => "https://s3.example.com/upload/{$partNumber}",
                'expires_at' => '2026-12-31T00:00:00Z',
            ];
        }

        return [
            'success' => true,
            'data' => [
                'upload_id' => self::UPLOAD_ID,
                'mime_type' => 'image/jpeg',
                'first_chunk_etag' => '"etag-part-1"',
                'first_chunk_size_bytes' => self::FIRST_CHUNK_SIZE,
                'total_parts' => 1 + $remainingChunks,
                'recommended_chunk_size' => $chunkSize,
                'presigned_urls' => $presigned,
                'constraints_applied' => [
                    'max_size_bytes' => 100_000_000,
                    'max_duration_seconds' => 600,
                    'processing_class_pre_assignment' => 'short_form',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function completeEnvelope(string $status): array
    {
        return [
            'success' => true,
            'data' => [
                'upload_id' => self::UPLOAD_ID,
                'status' => $status,
            ],
        ];
    }

    private function writeBigFile(int $size): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'gisl-multipart-test-');
        if ($path === false) {
            self::fail('tempnam failed');
        }
        $this->tempFiles[] = $path;

        // Write deterministic bytes so the wire body content is predictable.
        $fh = \fopen($path, 'wb');
        if ($fh === false) {
            self::fail("Could not open {$path} for writing");
        }
        try {
            $chunkBuf = \str_repeat('A', 4096);
            $remaining = $size;
            while ($remaining > 0) {
                $writeLen = (int) \min($remaining, 4096);
                $written = \fwrite($fh, \substr($chunkBuf, 0, $writeLen));
                if ($written === false || $written === 0) {
                    self::fail("Short write to {$path}");
                }
                $remaining -= $written;
            }
        } finally {
            \fclose($fh);
        }
        return $path;
    }

    /**
     * Create a SPARSE file: filesize() reports $size but only ~1 byte is
     * actually on disk (seek to end-1, write 1 byte). Lets a unit test drive
     * the >50 GiB computed-part-count path without writing 50+ GiB. The
     * upload only ever reads the first 8 MiB (zeros) before the <=10k guard
     * throws, so the sparse zero-fill is sufficient.
     */
    private function writeSparseFile(int $size): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'gisl-multipart-sparse-');
        if ($path === false) {
            self::fail('tempnam failed');
        }
        $this->tempFiles[] = $path;

        $fh = \fopen($path, 'wb');
        if ($fh === false) {
            self::fail("Could not open {$path} for writing");
        }
        try {
            if (\fseek($fh, $size - 1) !== 0) {
                self::fail("Could not seek to {$size} in {$path}");
            }
            if (\fwrite($fh, "\0") === false) {
                self::fail("Short write to {$path}");
            }
        } finally {
            \fclose($fh);
        }

        \clearstatcache(true, $path);
        if (\filesize($path) !== $size) {
            self::fail("Sparse file size mismatch for {$path}");
        }
        return $path;
    }
}
