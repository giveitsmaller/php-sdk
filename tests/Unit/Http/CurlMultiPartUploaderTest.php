<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Http;

use Gisl\Sdk\Http\CurlMultiPartUploader;
use Gisl\Sdk\Tests\Capability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic, no-network coverage for the curl_multi uploader. The real
 * on-the-wire behaviour (concurrent PUTs, ETag extraction, 5xx retry, fatal
 * abort) is validated end-to-end against real S3 by the e2e suite — testing
 * curl in-process would require a subprocess fake-S3 server, which is exactly
 * what the e2e harness already provides against the real thing. These cover
 * the parts that DON'T need a server.
 */
#[CoversClass(CurlMultiPartUploader::class)]
final class CurlMultiPartUploaderTest extends TestCase
{
    public function testIsSupportedReflectsCurlExtension(): void
    {
        self::assertSame(\extension_loaded('curl'), CurlMultiPartUploader::isSupported());
    }

    public function testEmptyPartListUploadsNothingAndReturnsEmptyMap(): void
    {
        Capability::require('ext-curl', CurlMultiPartUploader::isSupported(), 'the concurrent uploader needs curl_multi');

        $uploader = new CurlMultiPartUploader(maxAttempts: 3, retryBaseMs: 0);
        $calls = 0;
        $result = $uploader->uploadParts(
            filePath: __FILE__, // never read — no parts
            parts: [],
            uploadId: '01936fb2-0000-7000-8000-000000000aaa',
            concurrency: 4,
            onPartComplete: static function () use (&$calls): void {
                $calls++;
            },
        );

        self::assertSame([], $result);
        self::assertSame(0, $calls);
    }
}
