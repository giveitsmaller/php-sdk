<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Errors\GislError;
use Gisl\Sdk\Errors\GislMultipartPartCountError;
use Gisl\Sdk\Errors\GislMultipartPartError;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\Http\MultipartPartUploader;
use Gisl\Sdk\MultipartCheckpointState;
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
 * SDK-3 (Wb6ebOMM) — uploadFile($filePath, resumeUploadId=$id) resume path
 * tests. Mirrors `packages/typescript/tests/unit/upload-resume.test.ts`.
 */
#[CoversClass(GislClient::class)]
final class GislClientUploadResumeTest extends TestCase
{
    private const CHUNK = 16_777_216; // 16 MiB

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

    public function testRejectsSubThresholdFile(): void
    {
        $filePath = $this->writeSparseFile(1 * 1024 * 1024); // 1 MB, below 10 MB threshold
        $captured = [];
        $client = $this->makeClient($this->stubClient([], $captured));

        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/at-or-below the multipart threshold/');
        $client->uploadFile($filePath, new UploadOptions(resumeUploadId: '01936fb2-0000-7000-8000-000000000aaa'));
    }

    public function testHappyPathResumePutsOnlyMissingPart(): void
    {
        $totalParts = 3;
        $fileSize = ($totalParts - 1) * self::CHUNK + 4 * 1024 * 1024;
        $filePath = $this->writeBigFile($fileSize);

        $captured = [];
        // Queue: status (parts 1,2 uploaded) -> presign batch -> S3 PUT part 3 -> complete
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->statusEnvelope(
                uploadId: '01936fb2-0000-7000-8000-000000000aaa',
                totalParts: $totalParts,
                isTruncated: false,
                nextMarker: 2,
                parts: [1, 2],
            )),
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'upload_id' => '01936fb2-0000-7000-8000-000000000aaa',
                    'presigned_urls' => [
                        ['part_number' => 3, 'url' => 'https://s3.example.com/p3', 'expires_at' => '2026-05-21T12:00:00Z'],
                    ],
                ],
            ]),
            $this->s3Response('"new-etag-3"'),
            $this->jsonResponse(200, [
                'success' => true,
                'data' => ['upload_id' => '01936fb2-0000-7000-8000-000000000aaa', 'status' => 'completed'],
            ]),
        ], $captured);

        $checkpoints = [];
        $progress = [];
        $client = $this->makeClient($http);
        $result = $client->uploadFile(
            $filePath,
            new UploadOptions(
                onProgress: static function (int $up, int $tot) use (&$progress): void {
                    $progress[] = [$up, $tot];
                },
                resumeUploadId: '01936fb2-0000-7000-8000-000000000aaa',
                onCheckpoint: static function (MultipartCheckpointState $s) use (&$checkpoints): void {
                    $checkpoints[] = $s;
                },
            ),
        );

        self::assertSame('01936fb2-0000-7000-8000-000000000aaa', $result->getFileId());
        self::assertSame($fileSize, $result->getSizeBytes());

        // No /initiate call. The 4 captured requests are: GET /status, POST /presign, PUT S3, POST /complete.
        self::assertCount(4, $captured);
        self::assertStringNotContainsString('/initiate', (string) $captured[0]->getUri());

        // /complete carries ALL parts (server-recorded 1,2 + newly-PUT 3).
        $completeBody = \json_decode((string) $captured[3]->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('01936fb2-0000-7000-8000-000000000aaa', $completeBody['upload_id']);
        self::assertSame([1, 2, 3], \array_map(fn ($p) => $p['part_number'], $completeBody['parts']));

        // onProgress entry-seeded from /status (2 parts * CHUNK).
        self::assertGreaterThanOrEqual(2, \count($progress));
        self::assertSame(2 * self::CHUNK, $progress[0][0]);
        self::assertSame($fileSize, $progress[\count($progress) - 1][0]);

        // onCheckpoint fired on entry + after the 1 new PUT.
        self::assertGreaterThanOrEqual(2, \count($checkpoints));
        self::assertSame([1, 2], $checkpoints[0]->uploadedPartNumbers);
        self::assertSame([1, 2, 3], $checkpoints[\count($checkpoints) - 1]->uploadedPartNumbers);

        // JSON round-trip parity with TS.
        $serialised = \json_encode($checkpoints[\count($checkpoints) - 1], JSON_THROW_ON_ERROR);
        $round = \json_decode($serialised, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('01936fb2-0000-7000-8000-000000000aaa', $round['uploadId']);
        self::assertSame(3, $round['totalParts']);
        self::assertSame([1, 2, 3], $round['uploadedPartNumbers']);
    }

    public function testInterruptMidUploadResumesMissingParts(): void
    {
        $totalParts = 5;
        $fileSize = ($totalParts - 1) * self::CHUNK + 4 * 1024 * 1024;
        $filePath = $this->writeBigFile($fileSize);

        $captured = [];
        $http = $this->stubClient([
            // /status: parts 1, 2, 4 uploaded — missing [3, 5]
            $this->jsonResponse(200, $this->statusEnvelope(
                uploadId: '01936fb2-0000-7000-8000-000000000bbb',
                totalParts: $totalParts,
                isTruncated: false,
                nextMarker: 4,
                parts: [1, 2, 4],
            )),
            // /presign batch for [3, 5]
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'upload_id' => '01936fb2-0000-7000-8000-000000000bbb',
                    'presigned_urls' => [
                        ['part_number' => 3, 'url' => 'https://s3.example.com/u2-p3', 'expires_at' => '2026-05-21T12:00:00Z'],
                        ['part_number' => 5, 'url' => 'https://s3.example.com/u2-p5', 'expires_at' => '2026-05-21T12:00:00Z'],
                    ],
                ],
            ]),
            $this->s3Response('"u2-etag-3"'),
            $this->s3Response('"u2-etag-5"'),
            $this->jsonResponse(200, [
                'success' => true,
                'data' => ['upload_id' => '01936fb2-0000-7000-8000-000000000bbb', 'status' => 'completed'],
            ]),
        ], $captured);

        $checkpoints = [];
        $client = $this->makeClient($http);
        $client->uploadFile(
            $filePath,
            new UploadOptions(
                resumeUploadId: '01936fb2-0000-7000-8000-000000000bbb',
                onCheckpoint: static function (MultipartCheckpointState $s) use (&$checkpoints): void {
                    $checkpoints[] = $s;
                },
            ),
        );

        // 1 entry checkpoint + 2 post-PUT.
        self::assertCount(3, $checkpoints);
        self::assertSame([1, 2, 4], $checkpoints[0]->uploadedPartNumbers);
        self::assertSame([1, 2, 3, 4, 5], $checkpoints[\count($checkpoints) - 1]->uploadedPartNumbers);

        // /presign batch carried [3, 5] in order.
        $presignBody = \json_decode((string) $captured[1]->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame([3, 5], $presignBody['part_numbers']);
    }

    public function testConcurrentUploaderDrivesResumeMissingParts(): void
    {
        // totalParts=5, parts [1,2,4] recorded -> missing [3,5]. An injected
        // uploader owns the part PUTs, completing them OUT OF ORDER (5 then 3)
        // to prove the checkpoint union reflects all completed parts even
        // before ETags are folded into $uploaded.
        $totalParts = 5;
        $fileSize = ($totalParts - 1) * self::CHUNK + 4 * 1024 * 1024; // 68 MiB
        $filePath = $this->writeSparseFile($fileSize);

        // No S3 PUTs queued — uploader handles them. Captured = [/status, /presign, /complete].
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->statusEnvelope(
                uploadId: '01936fb2-0000-7000-8000-00000000c0c0',
                totalParts: $totalParts,
                isTruncated: false,
                nextMarker: 4,
                parts: [1, 2, 4],
            )),
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'upload_id' => '01936fb2-0000-7000-8000-00000000c0c0',
                    'presigned_urls' => [
                        ['part_number' => 3, 'url' => 'https://s3.example.com/c-p3', 'expires_at' => '2026-05-21T12:00:00Z'],
                        ['part_number' => 5, 'url' => 'https://s3.example.com/c-p5', 'expires_at' => '2026-05-21T12:00:00Z'],
                    ],
                ],
            ]),
            $this->jsonResponse(200, [
                'success' => true,
                'data' => ['upload_id' => '01936fb2-0000-7000-8000-00000000c0c0', 'status' => 'completed'],
            ]),
        ], $captured);

        $fake = new class implements MultipartPartUploader {
            /** @var list<array{partNumber: int, url: string, offset: int, length: int}> */
            public array $seen = [];
            public int $sawConcurrency = -1;

            public function uploadParts(
                string $filePath,
                array $parts,
                string $uploadId,
                int $concurrency,
                callable $onPartComplete,
            ): array {
                $this->seen = $parts;
                $this->sawConcurrency = $concurrency;
                // Complete OUT OF ORDER: highest part first.
                $byPart = [];
                foreach ($parts as $d) {
                    $byPart[$d['partNumber']] = $d;
                }
                \krsort($byPart);
                $etags = [];
                foreach ($byPart as $pn => $d) {
                    $onPartComplete($pn, $d['length']);
                    $etags[$pn] = "\"conc-etag-{$pn}\"";
                }

                return $etags;
            }
        };

        $checkpoints = [];
        $progress = [];
        $client = $this->makeClient($http, $fake);
        $result = $client->uploadFile(
            $filePath,
            new UploadOptions(
                onProgress: static function (int $up, int $tot) use (&$progress): void {
                    $progress[] = [$up, $tot];
                },
                resumeUploadId: '01936fb2-0000-7000-8000-00000000c0c0',
                onCheckpoint: static function (MultipartCheckpointState $s) use (&$checkpoints): void {
                    $checkpoints[] = $s->uploadedPartNumbers;
                },
            ),
        );

        self::assertSame('01936fb2-0000-7000-8000-00000000c0c0', $result->getFileId());

        // Uploader handled the PUTs -> only /status, /presign, /complete hit HTTP.
        self::assertCount(3, $captured);
        foreach ($captured as $req) {
            self::assertStringNotContainsString('s3.example.com', (string) $req->getUri());
        }

        // Uploader received exactly the missing parts [3, 5] with correct
        // offsets/lengths + the configured concurrency (default 4). Part 5 is
        // the short tail (68 MiB - 56 MiB = 12 MiB).
        self::assertSame(4, $fake->sawConcurrency);
        self::assertSame([
            ['partNumber' => 3, 'url' => 'https://s3.example.com/c-p3', 'offset' => 8_388_608 + self::CHUNK, 'length' => self::CHUNK],
            ['partNumber' => 5, 'url' => 'https://s3.example.com/c-p5', 'offset' => 8_388_608 + 3 * self::CHUNK, 'length' => $fileSize - (8_388_608 + 3 * self::CHUNK)],
        ], $fake->seen);

        // /complete carries ALL parts ascending: recorded 1,2,4 + uploader 3,5.
        $completeBody = \json_decode((string) $captured[2]->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame([1, 2, 3, 4, 5], \array_map(fn ($p) => $p['part_number'], $completeBody['parts']));
        $etagByPart = [];
        foreach ($completeBody['parts'] as $p) {
            $etagByPart[$p['part_number']] = $p['etag'];
        }
        self::assertSame('"conc-etag-3"', $etagByPart[3]);
        self::assertSame('"conc-etag-5"', $etagByPart[5]);
        self::assertSame('"etag-1"', $etagByPart[1]);

        // Checkpoints: entry [1,2,4]; after part 5 completes (out of order)
        // [1,2,4,5]; after part 3 [1,2,3,4,5]. Union reflects completed parts
        // before their ETags are folded into $uploaded.
        self::assertSame([1, 2, 4], $checkpoints[0]);
        self::assertSame([1, 2, 4, 5], $checkpoints[1]);
        self::assertSame([1, 2, 3, 4, 5], $checkpoints[\count($checkpoints) - 1]);

        // Progress ends at the full file size.
        self::assertSame($fileSize, $progress[\count($progress) - 1][0]);
    }

    /**
     * Criterion 4 — typed-error parity (retry-exhaustion). Sequential resume
     * path (no uploader injected): every PUT on the single missing part
     * returns 503. After multipartMaxAttempts (default 3) the throw is the
     * TYPED GislMultipartPartError carrying the failing part number +
     * uploadId — mirrors the fresh-path
     * GislClientMultipartTest::testRetryExhaustionThrowsTypedMultipartPartError.
     */
    public function testSequentialResumeRetryExhaustionThrowsTypedMultipartPartError(): void
    {
        $totalParts = 3;
        $fileSize = ($totalParts - 1) * self::CHUNK + 4 * 1024 * 1024;
        $filePath = $this->writeBigFile($fileSize);

        $captured = [];
        // /status (parts 1,2; missing [3]) -> /presign -> 3x S3 503 (attempts).
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->statusEnvelope(
                uploadId: '01936fb2-0000-7000-8000-0000000e0e01',
                totalParts: $totalParts,
                isTruncated: false,
                nextMarker: 2,
                parts: [1, 2],
            )),
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'upload_id' => '01936fb2-0000-7000-8000-0000000e0e01',
                    'presigned_urls' => [
                        ['part_number' => 3, 'url' => 'https://s3.example.com/e1-p3', 'expires_at' => '2026-05-21T12:00:00Z'],
                    ],
                ],
            ]),
            new Response(503, [], 'service unavailable'),
            new Response(503, [], 'service unavailable'),
            new Response(503, [], 'service unavailable'),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->uploadFile($filePath, new UploadOptions(resumeUploadId: '01936fb2-0000-7000-8000-0000000e0e01'));
            self::fail('Expected GislMultipartPartError');
        } catch (GislMultipartPartError $e) {
            self::assertInstanceOf(GislError::class, $e);
            self::assertSame(3, $e->partNumber);
            self::assertSame('01936fb2-0000-7000-8000-0000000e0e01', $e->uploadId);
            // /status + /presign + 3 PUT attempts = 5. No /complete.
            self::assertCount(5, $captured);
            foreach ($captured as $req) {
                self::assertStringNotContainsString('/complete', (string) $req->getUri());
            }
        }
    }

    /**
     * Criterion 4 — typed-error parity (non-retryable 4xx). Sequential resume:
     * the missing part PUT returns 403. A non-retryable status is fatal on the
     * FIRST attempt — surfaces as a bare GislError (NOT the typed
     * GislMultipartPartError, NOT retried). Mirrors fresh-path
     * GislClientMultipartTest::testNonRetryable4xxOnS3IsFatal.
     */
    public function testSequentialResumeNonRetryable4xxOnS3IsBareGislError(): void
    {
        $totalParts = 3;
        $fileSize = ($totalParts - 1) * self::CHUNK + 4 * 1024 * 1024;
        $filePath = $this->writeBigFile($fileSize);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->statusEnvelope(
                uploadId: '01936fb2-0000-7000-8000-0000000e0e02',
                totalParts: $totalParts,
                isTruncated: false,
                nextMarker: 2,
                parts: [1, 2],
            )),
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'upload_id' => '01936fb2-0000-7000-8000-0000000e0e02',
                    'presigned_urls' => [
                        ['part_number' => 3, 'url' => 'https://s3.example.com/e2-p3', 'expires_at' => '2026-05-21T12:00:00Z'],
                    ],
                ],
            ]),
            new Response(403, [], 'forbidden'),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->uploadFile($filePath, new UploadOptions(resumeUploadId: '01936fb2-0000-7000-8000-0000000e0e02'));
            self::fail('Expected GislError');
        } catch (GislMultipartPartError $e) {
            self::fail('Non-retryable 4xx must NOT surface as the typed part error: ' . $e->getMessage());
        } catch (GislError $e) {
            self::assertMatchesRegularExpression('/non-retryable/', $e->getMessage());
            // /status + /presign + exactly ONE S3 PUT (403 not retried). No /complete.
            self::assertCount(3, $captured);
            $s3Puts = \array_filter(
                $captured,
                fn ($r) => \str_contains((string) $r->getUri(), 's3.example.com'),
            );
            self::assertCount(1, $s3Puts);
        }
    }

    /**
     * Criterion 4 — typed-error parity (missing/empty ETag). Sequential
     * resume: S3 returns 200 with no ETag header. The SDK refuses to record a
     * part with no ETag — bare GislError. Mirrors fresh-path
     * GislClientMultipartTest::testMissingEtagOnS3IsFatal.
     */
    public function testSequentialResumeMissingEtagOnS3IsBareGislError(): void
    {
        $totalParts = 3;
        $fileSize = ($totalParts - 1) * self::CHUNK + 4 * 1024 * 1024;
        $filePath = $this->writeBigFile($fileSize);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->statusEnvelope(
                uploadId: '01936fb2-0000-7000-8000-0000000e0e03',
                totalParts: $totalParts,
                isTruncated: false,
                nextMarker: 2,
                parts: [1, 2],
            )),
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'upload_id' => '01936fb2-0000-7000-8000-0000000e0e03',
                    'presigned_urls' => [
                        ['part_number' => 3, 'url' => 'https://s3.example.com/e3-p3', 'expires_at' => '2026-05-21T12:00:00Z'],
                    ],
                ],
            ]),
            new Response(200, [], ''), // 2xx but no ETag header
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->uploadFile($filePath, new UploadOptions(resumeUploadId: '01936fb2-0000-7000-8000-0000000e0e03'));
            self::fail('Expected GislError');
        } catch (GislMultipartPartError $e) {
            self::fail('Missing ETag must NOT surface as the typed part error: ' . $e->getMessage());
        } catch (GislError $e) {
            self::assertMatchesRegularExpression('/missing ETag/', $e->getMessage());
            // /status + /presign + 1 S3 PUT. No /complete.
            self::assertCount(3, $captured);
            foreach ($captured as $req) {
                self::assertStringNotContainsString('/complete', (string) $req->getUri());
            }
        }
    }

    /**
     * Criterion 4 — typed-error parity (concurrent propagation). When the
     * injected uploader throws a GislMultipartPartError mid-batch, the resume
     * path must let it propagate UNTOUCHED (same part number + uploadId) and
     * must NOT attempt /multipart/complete with a half-finished part set.
     */
    public function testConcurrentResumeUploaderThrowPropagatesAndSkipsComplete(): void
    {
        $totalParts = 5;
        $fileSize = ($totalParts - 1) * self::CHUNK + 4 * 1024 * 1024;
        $filePath = $this->writeSparseFile($fileSize);

        // Only /status + /presign should hit HTTP — the uploader throws before
        // any ETag is folded, so /complete must never be reached.
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->statusEnvelope(
                uploadId: '01936fb2-0000-7000-8000-0000000e0e04',
                totalParts: $totalParts,
                isTruncated: false,
                nextMarker: 4,
                parts: [1, 2, 4],
            )),
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'upload_id' => '01936fb2-0000-7000-8000-0000000e0e04',
                    'presigned_urls' => [
                        ['part_number' => 3, 'url' => 'https://s3.example.com/e4-p3', 'expires_at' => '2026-05-21T12:00:00Z'],
                        ['part_number' => 5, 'url' => 'https://s3.example.com/e4-p5', 'expires_at' => '2026-05-21T12:00:00Z'],
                    ],
                ],
            ]),
        ], $captured);

        $fake = new class implements MultipartPartUploader {
            public function uploadParts(
                string $filePath,
                array $parts,
                string $uploadId,
                int $concurrency,
                callable $onPartComplete,
            ): array {
                // Fail on the first missing part of the batch (part 3).
                throw new GislMultipartPartError(
                    'concurrent uploader: part 3 exhausted retries',
                    3,
                    $uploadId,
                );
            }
        };

        $client = $this->makeClient($http, $fake);

        try {
            $client->uploadFile($filePath, new UploadOptions(resumeUploadId: '01936fb2-0000-7000-8000-0000000e0e04'));
            self::fail('Expected GislMultipartPartError');
        } catch (GislMultipartPartError $e) {
            // Propagated untouched — same part + uploadId the uploader raised.
            self::assertSame(3, $e->partNumber);
            self::assertSame('01936fb2-0000-7000-8000-0000000e0e04', $e->uploadId);
            self::assertSame('concurrent uploader: part 3 exhausted retries', $e->getMessage());
            // Only /status + /presign happened — NO /complete request.
            self::assertCount(2, $captured);
            foreach ($captured as $req) {
                self::assertStringNotContainsString('/complete', (string) $req->getUri());
                self::assertStringNotContainsString('s3.example.com', (string) $req->getUri());
            }
        }
    }

    /**
     * Criterion 1 — batching boundary (>100 missing parts). A 150-part session
     * with 120 missing parts is presigned + uploaded in batches of <=100. The
     * resume path must:
     *   - issue exactly TWO /presign calls (batch sizes 100 + 20),
     *   - drive the uploader once per batch with the right slices,
     *   - fold ETags across the batch boundary so /complete carries ALL 150
     *     parts in ascending order with the correct per-part ETags.
     */
    public function testConcurrentResumeBatchesMissingPartsAcrossTheHundredBoundary(): void
    {
        $totalParts = 150;
        // The contract pins recommended_chunk_size to a >=16 MiB floor (the SDK
        // rejects anything smaller), so a 150-part plan is ~2.4 GB: part 1 =
        // firstChunkSize (8 MiB), parts 2..149 = CHUNK each, part 150 a short
        // tail. The wrong-file guard accepts a size in
        // [firstChunkSize + (parts-2)*CHUNK + 1, firstChunkSize + (parts-1)*CHUNK].
        // The concurrent uploader is faked (never reads bytes), so we back this
        // with a SPARSE file — correct filesize(), ~no disk.
        $firstChunk = 8_388_608; // SDK DEFAULT_MULTIPART_FIRST_CHUNK_SIZE_BYTES
        $fileSize = $firstChunk + ($totalParts - 2) * self::CHUNK + 4096; // short tail (part 150)
        $filePath = $this->writeSparseFile($fileSize);

        $uploadId = '01936fb2-0000-7000-8000-0000000b0b01';

        // Recorded parts = 1..30 (30 parts). Missing = 31..150 (120 parts) ->
        // two batches: [31..130] (100) then [131..150] (20).
        $recorded = \range(1, 30);
        $missing = \range(31, 150);
        $batch1 = \array_slice($missing, 0, 100);   // 31..130
        $batch2 = \array_slice($missing, 100, 20);  // 131..150

        $presignEnvelope = static function (string $uid, array $partNumbers): array {
            $urls = [];
            foreach ($partNumbers as $pn) {
                $urls[] = [
                    'part_number' => $pn,
                    'url' => "https://s3.example.com/b-p{$pn}",
                    'expires_at' => '2026-05-21T12:00:00Z',
                ];
            }
            return [
                'success' => true,
                'data' => ['upload_id' => $uid, 'presigned_urls' => $urls],
            ];
        };

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->statusEnvelope(
                uploadId: $uploadId,
                totalParts: $totalParts,
                isTruncated: false,
                nextMarker: 30,
                parts: $recorded,
            )),
            $this->jsonResponse(200, $presignEnvelope($uploadId, $batch1)),
            $this->jsonResponse(200, $presignEnvelope($uploadId, $batch2)),
            $this->jsonResponse(200, [
                'success' => true,
                'data' => ['upload_id' => $uploadId, 'status' => 'completed'],
            ]),
        ], $captured);

        $fake = new class implements MultipartPartUploader {
            /** @var list<list<int>> */
            public array $batches = [];

            public function uploadParts(
                string $filePath,
                array $parts,
                string $uploadId,
                int $concurrency,
                callable $onPartComplete,
            ): array {
                $partNumbers = \array_map(static fn ($d) => $d['partNumber'], $parts);
                $this->batches[] = $partNumbers;
                $etags = [];
                foreach ($parts as $d) {
                    $pn = $d['partNumber'];
                    $onPartComplete($pn, $d['length']);
                    $etags[$pn] = "\"batch-etag-{$pn}\"";
                }

                return $etags;
            }
        };

        $client = $this->makeClient($http, $fake);
        $result = $client->uploadFile($filePath, new UploadOptions(resumeUploadId: $uploadId));
        self::assertSame($uploadId, $result->getFileId());

        // Captured = /status + 2x /presign + /complete = 4. Exactly TWO presigns.
        self::assertCount(4, $captured);
        $presignCalls = \array_values(\array_filter(
            $captured,
            fn ($r) => \str_contains((string) $r->getUri(), '/presign'),
        ));
        self::assertCount(2, $presignCalls);
        // /presign bodies carry the batch part numbers in order.
        $presign1Body = \json_decode((string) $presignCalls[0]->getBody(), true, 16, JSON_THROW_ON_ERROR);
        $presign2Body = \json_decode((string) $presignCalls[1]->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame($batch1, $presign1Body['part_numbers']);
        self::assertSame($batch2, $presign2Body['part_numbers']);
        self::assertCount(100, $presign1Body['part_numbers']);
        self::assertCount(20, $presign2Body['part_numbers']);

        // Uploader driven once per batch with the matching slices.
        self::assertSame([$batch1, $batch2], $fake->batches);

        // /complete carries ALL 150 parts ascending, ETags folded across the
        // boundary: recorded 1..30 keep "etag-N", uploaded 31..150 get
        // "batch-etag-N".
        $completeBody = \json_decode((string) $captured[3]->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame($uploadId, $completeBody['upload_id']);
        $partNumbers = \array_map(fn ($p) => $p['part_number'], $completeBody['parts']);
        self::assertSame(\range(1, 150), $partNumbers);
        $etagByPart = [];
        foreach ($completeBody['parts'] as $p) {
            $etagByPart[$p['part_number']] = $p['etag'];
        }
        // Recorded boundary part.
        self::assertSame('"etag-30"', $etagByPart[30]);
        // Last part of batch 1 + first part of batch 2 — ETags fold correctly
        // across the 100-part boundary.
        self::assertSame('"batch-etag-130"', $etagByPart[130]);
        self::assertSame('"batch-etag-131"', $etagByPart[131]);
        self::assertSame('"batch-etag-150"', $etagByPart[150]);
    }

    public function testShortCircuitsWhenAllPartsAlreadyUploaded(): void
    {
        $totalParts = 3;
        $fileSize = ($totalParts - 1) * self::CHUNK + 4 * 1024 * 1024;
        $filePath = $this->writeSparseFile($fileSize);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->statusEnvelope(
                uploadId: '01936fb2-0000-7000-8000-000000000ccc',
                totalParts: $totalParts,
                isTruncated: false,
                nextMarker: 3,
                parts: [1, 2, 3],
            )),
            $this->jsonResponse(200, [
                'success' => true,
                'data' => ['upload_id' => '01936fb2-0000-7000-8000-000000000ccc', 'status' => 'completed'],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $result = $client->uploadFile($filePath, new UploadOptions(resumeUploadId: '01936fb2-0000-7000-8000-000000000ccc'));
        self::assertSame('01936fb2-0000-7000-8000-000000000ccc', $result->getFileId());

        // Only 2 captured requests: /status + /complete. No /presign, no S3 PUT.
        self::assertCount(2, $captured);
        foreach ($captured as $req) {
            self::assertStringNotContainsString('/presign', (string) $req->getUri());
            self::assertStringNotContainsString('s3.example.com', (string) $req->getUri());
        }
    }

    public function testRejectsStatusRecommendedChunkSizeBelowContractMinimum(): void
    {
        $totalParts = 3;
        $fileSize = ($totalParts - 1) * self::CHUNK + 4 * 1024 * 1024;
        $filePath = $this->writeSparseFile($fileSize);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'upload_id' => '01936fb2-0000-7000-8000-000000000fff',
                    'multipart_upload_id' => 'srv',
                    'cloud_key' => 'k',
                    'total_parts' => $totalParts,
                    'uploaded_parts' => [],
                    'next_part_number_marker' => 0,
                    'is_truncated' => false,
                    'manifest_expires_at' => '2026-05-21T12:00:00Z',
                    // Below MULTIPART_CHUNK_SIZE = 16 MiB.
                    'recommended_chunk_size' => 1_048_576,
                ],
            ]),
        ], $captured);
        $client = $this->makeClient($http);
        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/outside the contract range/');
        $client->uploadFile($filePath, new UploadOptions(resumeUploadId: '01936fb2-0000-7000-8000-000000000fff'));
    }

    public function testRejectsStatusTotalPartsAbove10000WithTypedError(): void
    {
        $filePath = $this->writeSparseFile(50 * 1024 * 1024);
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'upload_id' => '01936fb2-0000-7000-8000-0000000aaaaa',
                    'multipart_upload_id' => 'srv',
                    'cloud_key' => 'k',
                    'total_parts' => 10_001,
                    'uploaded_parts' => [],
                    'next_part_number_marker' => 0,
                    'is_truncated' => false,
                    'manifest_expires_at' => '2026-05-21T12:00:00Z',
                    'recommended_chunk_size' => self::CHUNK,
                ],
            ]),
        ], $captured);
        $client = $this->makeClient($http);
        try {
            $client->uploadFile($filePath, new UploadOptions(resumeUploadId: '01936fb2-0000-7000-8000-0000000aaaaa'));
            self::fail('Expected GislMultipartPartCountError');
        } catch (GislMultipartPartCountError $e) {
            self::assertSame(10_001, $e->requiredParts);
            self::assertSame(10_000, $e->maxParts);
        }
    }

    public function testRejectsStatusUploadIdMismatch(): void
    {
        $totalParts = 3;
        $fileSize = ($totalParts - 1) * self::CHUNK + 4 * 1024 * 1024;
        $filePath = $this->writeSparseFile($fileSize);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    // Server returns a different upload_id than requested.
                    'upload_id' => '01936fb2-0000-7000-8000-000000bbbbbb',
                    'multipart_upload_id' => 'srv',
                    'cloud_key' => 'k',
                    'total_parts' => $totalParts,
                    'uploaded_parts' => [],
                    'next_part_number_marker' => 0,
                    'is_truncated' => false,
                    'manifest_expires_at' => '2026-05-21T12:00:00Z',
                    'recommended_chunk_size' => self::CHUNK,
                ],
            ]),
        ], $captured);
        $client = $this->makeClient($http);
        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/mismatching upload_id/');
        $client->uploadFile($filePath, new UploadOptions(resumeUploadId: '01936fb2-0000-7000-8000-0000ccccccc1'));
    }

    public function testCheckpointCallbackThrowDoesNotTriggerDuplicatePut(): void
    {
        // Pins the architect-flagged invariant: a user-callback throw must
        // not dispatch a second PUT for the same part (would double-record
        // etags and corrupt /complete). PHP-side mirror of TS
        // `upload-resume.test.ts:checkpoint-callback throw fails the upload`.
        $totalParts = 2;
        $fileSize = ($totalParts - 1) * self::CHUNK + 4 * 1024 * 1024;
        $filePath = $this->writeBigFile($fileSize);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->statusEnvelope(
                uploadId: '01936fb2-0000-7000-8000-000000ddddd1',
                totalParts: $totalParts,
                isTruncated: false,
                nextMarker: 1,
                parts: [1],
            )),
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'upload_id' => '01936fb2-0000-7000-8000-000000ddddd1',
                    'presigned_urls' => [
                        ['part_number' => 2, 'url' => 'https://s3.example.com/u6-p2', 'expires_at' => '2026-05-21T12:00:00Z'],
                    ],
                ],
            ]),
            $this->s3Response('"new-etag-2"'),
        ], $captured);

        $cb = 0;
        $client = $this->makeClient($http);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/callback boom/');
        try {
            $client->uploadFile(
                $filePath,
                new UploadOptions(
                    resumeUploadId: '01936fb2-0000-7000-8000-000000ddddd1',
                    onCheckpoint: static function () use (&$cb): void {
                        $cb++;
                        // First call = entry checkpoint; second call = post-PUT.
                        if ($cb === 2) {
                            throw new \RuntimeException('callback boom');
                        }
                    },
                ),
            );
        } finally {
            // Critically: exactly ONE S3 PUT for part 2. The callback throw
            // must NOT have triggered a retry-loop that re-PUTs the same
            // part. Captured = [/status, /presign, S3-PUT-part-2]. No
            // 4th call (no duplicate PUT, no /complete attempted).
            self::assertCount(3, $captured);
            $s3Puts = \array_filter(
                $captured,
                fn ($r) => \str_contains((string) $r->getUri(), 's3.example.com'),
            );
            self::assertCount(1, $s3Puts);
        }
    }

    public function testRefusesWhenPart1IsMissing(): void
    {
        $totalParts = 3;
        $fileSize = ($totalParts - 1) * self::CHUNK + 4 * 1024 * 1024;
        $filePath = $this->writeSparseFile($fileSize);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->statusEnvelope(
                uploadId: '01936fb2-0000-7000-8000-000000000ddd',
                totalParts: $totalParts,
                isTruncated: false,
                nextMarker: 2,
                parts: [2], // part 1 missing
            )),
        ], $captured);

        $client = $this->makeClient($http);
        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/part 1 .* is missing/');
        $client->uploadFile($filePath, new UploadOptions(resumeUploadId: '01936fb2-0000-7000-8000-000000000ddd'));
    }

    public function testRefusesWrongFileSizeMismatch(): void
    {
        $totalParts = 3;
        $fileSize = ($totalParts * self::CHUNK + 1024 * 1024 * 1024); // far exceeds plan
        $filePath = $this->writeSparseFile($fileSize);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->statusEnvelope(
                uploadId: '01936fb2-0000-7000-8000-000000000eee',
                totalParts: $totalParts,
                isTruncated: false,
                nextMarker: 3,
                parts: [1, 2, 3],
            )),
        ], $captured);

        $client = $this->makeClient($http);
        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/Wrong file for this uploadId/');
        $client->uploadFile($filePath, new UploadOptions(resumeUploadId: '01936fb2-0000-7000-8000-000000000eee'));
    }

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

    private function makeClient(ClientInterface $http, ?MultipartPartUploader $partUploader = null): GislClient
    {
        return new GislClient(
            config: new GislClientConfig(
                baseUrl: 'https://api.example.com',
                apiKey: 'sk_test',
                multipartThresholdBytes: 8_388_608, // 8 MiB
                multipartRetryBaseMs: 0,
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

    /**
     * @param list<int> $parts
     * @return array<string, mixed>
     */
    private function statusEnvelope(
        string $uploadId,
        int $totalParts,
        bool $isTruncated,
        int $nextMarker,
        array $parts,
    ): array {
        return [
            'success' => true,
            'data' => [
                'upload_id' => $uploadId,
                'multipart_upload_id' => 'srv-' . $uploadId,
                'cloud_key' => 'uploads/' . $uploadId,
                'total_parts' => $totalParts,
                'uploaded_parts' => \array_map(
                    fn (int $n) => [
                        'part_number' => $n,
                        'etag' => "\"etag-{$n}\"",
                        'size_bytes' => self::CHUNK,
                        'last_modified' => '2026-05-19T12:00:00Z',
                    ],
                    $parts,
                ),
                'next_part_number_marker' => $nextMarker,
                'is_truncated' => $isTruncated,
                'manifest_expires_at' => '2026-05-21T12:00:00Z',
                'recommended_chunk_size' => self::CHUNK,
            ],
        ];
    }

    private function writeBigFile(int $size): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'gisl-resume-test-');
        if ($path === false) {
            self::fail('tempnam failed');
        }
        $this->tempFiles[] = $path;
        $fh = \fopen($path, 'wb');
        if ($fh === false) {
            self::fail("Could not open {$path} for writing");
        }
        try {
            $chunkBuf = \str_repeat('B', 4096);
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
     * Create a SPARSE file whose `filesize()` reports `$size` but which consumes
     * ~no disk. The resume wrong-file guard only reads `filesize()`, and the
     * concurrent uploader is faked in these tests (it never reads the bytes), so
     * a sparse fixture lets a many-part resume (whose real plan would be GBs at
     * the contract-mandated >=16 MiB chunk) run without materialising the bytes.
     * The same holds for a resume refused or short-circuited before any part is
     * PUT (Ao7EFBtA).
     * Do NOT use this where the sequential path PUTs a part — that path reads chunks.
     */
    private function writeSparseFile(int $size): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'gisl-resume-sparse-');
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
                self::fail("Could not write sparse terminator to {$path}");
            }
        } finally {
            \fclose($fh);
        }
        \clearstatcache(true, $path);
        self::assertSame($size, \filesize($path));
        return $path;
    }
}
