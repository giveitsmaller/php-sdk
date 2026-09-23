<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Ergonomic\Merge;
use Gisl\Sdk\Errors\GislApiError;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\Http\UploadSource;
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
 * KS04SnqR: a NON-seekable stream (stdin, a pipe) is accepted only when the
 * caller opts in, by buffering it to a seekable php://temp copy. The default
 * (reject) is unchanged, and the SDK's own copy never outlives the upload.
 */
#[CoversClass(UploadSource::class)]
final class BufferNonSeekableTest extends TestCase
{
    private HttpFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    /**
     * @param list<ResponseInterface|\Throwable> $queue
     * @param list<RequestInterface>              $captured
     * @param-out list<RequestInterface>          $captured
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

    private function makeClient(ClientInterface $http): GislClient
    {
        return new GislClient(
            config: new GislClientConfig(baseUrl: 'https://api.example.com'),
            httpClient: $http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    /** @return resource */
    private static function pipe(string $bytes)
    {
        $pipe = \popen('printf ' . \escapeshellarg($bytes), 'r');
        self::assertIsResource($pipe);
        self::assertFalse(\stream_get_meta_data($pipe)['seekable'], 'the fixture must really be non-seekable');
        return $pipe;
    }

    /** Open php://temp handles: how a leaked SDK-owned buffer would show up. */
    private static function openTempBuffers(): int
    {
        $count = 0;
        foreach (\get_resources('stream') as $stream) {
            if (\str_starts_with(\stream_get_meta_data($stream)['uri'] ?? '', 'php://temp')) {
                $count++;
            }
        }
        return $count;
    }

    private static function uploadOk(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) \json_encode([
            'success' => true,
            'data' => [
                'file_id' => '01936fb1-7bb3-7000-8000-000000000010',
                'original_name' => 'upload.bin',
                'mime_type' => 'application/octet-stream',
                'size_bytes' => 5,
            ],
        ]));
    }

    public function testBufferingYieldsASeekableCopyOfEveryByte(): void
    {
        $pipe = self::pipe('hello');
        $copy = UploadSource::bufferNonSeekable($pipe);
        \pclose($pipe);

        self::assertTrue(\stream_get_meta_data($copy)['seekable']);
        self::assertSame('hello', \stream_get_contents($copy));
        \fclose($copy);
    }

    public function testASeekableStreamIsReturnedUnchanged(): void
    {
        $stream = \fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        self::assertSame($stream, UploadSource::bufferNonSeekable($stream));
        \fclose($stream);
    }

    public function testTheDefaultStillRejectsANonSeekableStream(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([], $captured));
        $pipe = self::pipe('hello');
        try {
            $client->uploadFile($pipe);
            self::fail('expected non_seekable_stream');
        } catch (GislConfigError $e) {
            self::assertSame('non_seekable_stream', $e->getReason());
            self::assertCount(0, $captured);
        } finally {
            \pclose($pipe);
        }
    }

    public function testOptedInUploadSendsThePipedBytesAndClosesItsCopy(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([self::uploadOk()], $captured));
        $pipe = self::pipe('hello');
        $before = self::openTempBuffers();

        $client->uploadFile($pipe, new UploadOptions(bufferNonSeekable: true));
        \pclose($pipe);

        self::assertCount(1, $captured);
        self::assertStringContainsString('hello', (string) $captured[0]->getBody());
        self::assertSame($before, self::openTempBuffers(), 'the SDK-owned buffer leaked');
    }

    public function testTheCopyIsClosedWhenTheUploadFails(): void
    {
        $client = $this->makeClient($this->stubClient([
            new Response(500, ['Content-Type' => 'application/json'], '{"success":false,"error":"INTERNAL"}'),
        ]));
        $pipe = self::pipe('hello');
        $before = self::openTempBuffers();
        // Keep call arguments in exception traces: the caught error's trace then
        // holds the SDK's copy alive, so ONLY an explicit fclose() closes it -
        // refcounting alone would pass this test with the fclose() deleted.
        $previous = \ini_set('zend.exception_ignore_args', '0');

        try {
            $client->uploadFile($pipe, new UploadOptions(bufferNonSeekable: true));
            self::fail('expected the 500 to surface');
        } catch (GislApiError) {
            self::assertSame($before, self::openTempBuffers(), 'the SDK-owned buffer leaked on failure');
        } finally {
            \pclose($pipe);
            \ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    public function testFileInputAndMergeResourceBufferOnlyWhenAsked(): void
    {
        $pipe = self::pipe('abc');
        $input = FileInput::resource($pipe, filename: 'in.bin', bufferNonSeekable: true);
        $asset = Merge::resource(self::pipe('xyz'), bufferNonSeekable: true);
        \pclose($pipe);

        self::assertTrue(\stream_get_meta_data($input->resource)['seekable']);
        self::assertSame('abc', \stream_get_contents($input->resource));
        self::assertTrue(\stream_get_meta_data($asset->resource)['seekable']);
        self::assertSame('xyz', \stream_get_contents($asset->resource));

        $raw = self::pipe('raw');
        self::assertSame($raw, FileInput::resource($raw)->resource, 'no flag, no copy');
        \pclose($raw);
    }
}
