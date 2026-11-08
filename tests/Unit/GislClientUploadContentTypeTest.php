<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\Errors\GislConfigError;
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
 * RWWBYklu — multipart `file` part Content-Type forwarding.
 *
 * Covers BOTH upload paths that emit a form-data `file` part:
 *   - single-shot `POST /api/uploads` (`buildSingleShotMultipartBody`)
 *   - multipart `POST /api/uploads/multipart/initiate` first chunk
 *     (`buildMultipartInitiateBody`)
 *
 * For each path: a caller-supplied `UploadOptions(contentType: ...)` must land
 * on the part header, and an absent contentType must fall back to
 * `application/octet-stream` (RFC 7578 §4.4 unknown-binary default).
 *
 * No active parity fixture asserts the MULTIPART-INITIATE part content-type
 * (the upload_multipart family runs, but its initiate `file` part declares no
 * content-type, so the comparator never checks it), so the multipart
 * assertions here are the only guard for that half.
 */
#[CoversClass(GislClient::class)]
final class GislClientUploadContentTypeTest extends TestCase
{
    // 8 MiB — DEFAULT_MULTIPART_FIRST_CHUNK_SIZE_BYTES; the multipart-initiate
    // tests force the chunked path by setting the threshold here and uploading
    // a file larger than it.
    private const FIRST_CHUNK_SIZE = 8_388_608;
    // 16 MiB — CON-1 (contracts z4GDTUMx) minimum recommended_chunk_size; the
    // mocked initiate envelope must carry a CON-1-valid value or the generated
    // model rejects it at deserialization.
    private const CHUNK_SIZE       = 16_777_216;
    private const TOTAL_SIZE       = 25_165_824; // FIRST_CHUNK_SIZE + CHUNK_SIZE
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

    // ---------------------------------------------------------------
    // Single-shot — POST /api/uploads
    // ---------------------------------------------------------------

    public function testSingleShotForwardsCallerContentTypeOnFilePart(): void
    {
        $filePath = $this->writeSmallFile('hello world');

        $captured = [];
        $http = $this->stubClient([
            $this->uploadResponse(\basename($filePath)),
        ], $captured);

        $client = $this->makeClient($http);
        $client->uploadFile($filePath, new UploadOptions(contentType: 'text/plain'));

        self::assertCount(1, $captured);
        $request = $captured[0];
        self::assertSame('/api/uploads', $request->getUri()->getPath());

        $body = (string) $request->getBody();
        self::assertStringContainsString('name="file"', $body);
        self::assertStringContainsString(
            "Content-Disposition: form-data; name=\"file\"; filename=\"" . \basename($filePath) . "\"\r\n"
            . "Content-Type: text/plain\r\n",
            $body,
        );
        self::assertStringNotContainsString('Content-Type: application/octet-stream', $body);
    }

    public function testSingleShotDefaultsToOctetStreamWhenContentTypeOmitted(): void
    {
        $filePath = $this->writeSmallFile('hello world');

        $captured = [];
        $http = $this->stubClient([
            $this->uploadResponse(\basename($filePath)),
        ], $captured);

        $client = $this->makeClient($http);
        // No UploadOptions at all — the default path.
        $client->uploadFile($filePath);

        self::assertCount(1, $captured);
        $body = (string) $captured[0]->getBody();
        self::assertStringContainsString(
            "Content-Disposition: form-data; name=\"file\"; filename=\"" . \basename($filePath) . "\"\r\n"
            . "Content-Type: application/octet-stream\r\n",
            $body,
        );
    }

    public function testSingleShotDefaultsToOctetStreamWhenOptionsHasNullContentType(): void
    {
        $filePath = $this->writeSmallFile('hello world');

        $captured = [];
        $http = $this->stubClient([
            $this->uploadResponse(\basename($filePath)),
        ], $captured);

        $client = $this->makeClient($http);
        // UploadOptions present but contentType left null — still defaults.
        $client->uploadFile($filePath, new UploadOptions());

        self::assertCount(1, $captured);
        $body = (string) $captured[0]->getBody();
        self::assertStringContainsString("Content-Type: application/octet-stream\r\n", $body);
        self::assertStringNotContainsString('Content-Type: text/plain', $body);
    }

    // ---------------------------------------------------------------
    // Multipart initiate — POST /api/uploads/multipart/initiate
    // ---------------------------------------------------------------

    public function testMultipartInitiateForwardsCallerContentTypeOnFirstChunkPart(): void
    {
        $filePath = $this->writeBigFile(self::TOTAL_SIZE);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->initiateEnvelope()),
            $this->s3Response('"etag-part-2"'),
            $this->jsonResponse(200, $this->completeEnvelope()),
        ], $captured);

        $client = $this->makeClient($http);
        $client->uploadFile($filePath, new UploadOptions(contentType: 'image/png'));

        // First captured request is the multipart initiate.
        $initiate = $captured[0];
        self::assertStringEndsWith('/api/uploads/multipart/initiate', (string) $initiate->getUri());

        $body = (string) $initiate->getBody();
        self::assertStringContainsString(
            "Content-Disposition: form-data; name=\"file\"; filename=\"" . \basename($filePath) . "\"\r\n"
            . "Content-Type: image/png\r\n",
            $body,
        );
        // The first-chunk part must NOT fall back to octet-stream when the
        // caller declared a type. (Other parts — filename/total_size — carry
        // no Content-Type header, so the negative check is scoped to the file
        // part's expected default string.)
        self::assertStringNotContainsString(
            "name=\"file\"; filename=\"" . \basename($filePath) . "\"\r\n"
            . "Content-Type: application/octet-stream\r\n",
            $body,
        );
    }

    public function testMultipartInitiateDefaultsToOctetStreamWhenContentTypeOmitted(): void
    {
        $filePath = $this->writeBigFile(self::TOTAL_SIZE);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->initiateEnvelope()),
            $this->s3Response('"etag-part-2"'),
            $this->jsonResponse(200, $this->completeEnvelope()),
        ], $captured);

        $client = $this->makeClient($http);
        // No options — multipart path, default content-type.
        $client->uploadFile($filePath);

        $initiate = $captured[0];
        self::assertStringEndsWith('/api/uploads/multipart/initiate', (string) $initiate->getUri());

        $body = (string) $initiate->getBody();
        self::assertStringContainsString(
            "Content-Disposition: form-data; name=\"file\"; filename=\"" . \basename($filePath) . "\"\r\n"
            . "Content-Type: application/octet-stream\r\n",
            $body,
        );
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

    public function testRejectsContentTypeWithCrlfInjection(): void
    {
        // The contentType is concatenated verbatim into the raw multipart
        // Content-Type header bytes; a CRLF would inject extra MIME headers or
        // body content. UploadOptions validates at construction (fail-fast).
        $this->expectException(GislConfigError::class);
        new UploadOptions(contentType: "text/plain\r\nX-Injected: 1");
    }

    public function testRejectsContentTypeWithNulByte(): void
    {
        $this->expectException(GislConfigError::class);
        new UploadOptions(contentType: "text/plain\x00");
    }

    public function testRejectsFilenameWithCrlfInjection(): void
    {
        // fFwaKsN5: the filename is concatenated into the multipart
        // Content-Disposition header; CR/LF/NUL is rejected at construction.
        $this->expectException(GislConfigError::class);
        new UploadOptions(filename: "photo.jpg\r\nX-Injected: 1");
    }

    public function testRejectsFilenameWithPathSeparator(): void
    {
        // fFwaKsN5 (codex r1): the upload contract requires a BARE filename
        // (^[^/\\]+$) — a path separator would be rejected server-side, so fail
        // locally with an actionable error.
        $this->expectException(GislConfigError::class);
        new UploadOptions(filename: 'dir/photo.jpg');
    }

    public function testRejectsFilenameWithBackslashSeparator(): void
    {
        $this->expectException(GislConfigError::class);
        new UploadOptions(filename: 'dir\\photo.jpg');
    }

    public function testRejectsFilenameLongerThan255Bytes(): void
    {
        $this->expectException(GislConfigError::class);
        new UploadOptions(filename: \str_repeat('a', 256) . '.jpg');
    }

    public function testRejectsFilenameWithDoubleQuote(): void
    {
        // codex r3: a `"` breaks the quoted-string Content-Disposition filename;
        // the preflight guard rejects it in lock-step with the body builder so a
        // bad name can't slip past a fan-out preflight and fail at wire time.
        $this->expectException(GislConfigError::class);
        new UploadOptions(filename: 'bad"name.jpg');
    }

    public function testRejectsContentTypeMutatedAfterConstructionAtUploadTime(): void
    {
        // contentType is a public mutable property, so the ctor guard can be
        // bypassed by reassigning it post-construction. The authoritative
        // wire-assembly guard in the body builder must still reject the
        // injection at upload time, before any bytes reach the header.
        $http = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new \LogicException('HTTP must not be reached: the content-type guard should fire first');
            }
        };
        $client = $this->makeClient($http);
        $filePath = $this->writeSmallFile('hello');

        $options = new UploadOptions();
        $options->contentType = "text/plain\r\nX-Injected: 1";

        $this->expectException(GislConfigError::class);
        $client->uploadFile($filePath, $options);
    }

    public function testRejectsFilenameMutatedToAPathSeparatorAtUploadTime(): void
    {
        // fFwaKsN5 (codex r2): filename is a public mutable property, so the ctor
        // guard can be bypassed by reassigning it post-construction. The
        // authoritative wire-assembly guard in the body builder must still reject
        // a path-separator filename at upload time, before any bytes reach the
        // header (matches the contentType mutation-bypass invariant).
        $http = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new \LogicException('HTTP must not be reached: the filename guard should fire first');
            }
        };
        $client = $this->makeClient($http);
        $filePath = $this->writeSmallFile('hello');

        $options = new UploadOptions();
        $options->filename = '../../etc/passwd';

        $this->expectException(GislConfigError::class);
        $client->uploadFile($filePath, $options);
    }

    private function makeClient(ClientInterface $http): GislClient
    {
        return new GislClient(
            config: new GislClientConfig(
                baseUrl: 'https://api.example.com',
                apiKey: 'sk_test',
                multipartThresholdBytes: self::FIRST_CHUNK_SIZE, // 8 MiB
                multipartRetryBaseMs: 0,
            ),
            httpClient: $http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
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

    private function uploadResponse(string $originalName): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => [
                'file_id' => '01936fb2-0000-7000-8000-000000000001',
                'original_name' => $originalName,
                'mime_type' => 'text/plain',
                'size_bytes' => 11,
            ],
        ]);
    }

    private function s3Response(string $etag): ResponseInterface
    {
        return new Response(200, ['ETag' => $etag], '');
    }

    /**
     * @return array<string, mixed>
     */
    private function initiateEnvelope(): array
    {
        return [
            'success' => true,
            'data' => [
                'upload_id' => self::UPLOAD_ID,
                'mime_type' => 'image/jpeg',
                'first_chunk_etag' => '"etag-part-1"',
                'first_chunk_size_bytes' => self::FIRST_CHUNK_SIZE,
                'total_parts' => 2,
                'recommended_chunk_size' => self::CHUNK_SIZE,
                'presigned_urls' => [
                    [
                        'part_number' => 2,
                        'url' => 'https://s3.example.com/upload/2',
                        'expires_at' => '2026-12-31T00:00:00Z',
                    ],
                ],
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
    private function completeEnvelope(): array
    {
        return [
            'success' => true,
            'data' => [
                'upload_id' => self::UPLOAD_ID,
                'status' => 'completed',
            ],
        ];
    }

    private function writeSmallFile(string $contents): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'gisl-ct-test-');
        if ($path === false) {
            self::fail('tempnam failed');
        }
        $this->tempFiles[] = $path;
        \file_put_contents($path, $contents);
        return $path;
    }

    private function writeBigFile(int $size): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'gisl-ct-multipart-test-');
        if ($path === false) {
            self::fail('tempnam failed');
        }
        $this->tempFiles[] = $path;

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
}
