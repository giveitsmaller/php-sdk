<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Ergonomic;

use Gisl\Sdk\Ergonomic\Handle;
use Gisl\Sdk\Ergonomic\OperationBuilder;
use Gisl\Sdk\Ergonomic\SubmitOptions;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @phpstan-import-type Captured from OperationBuilderRunTest
 */
#[CoversClass(OperationBuilder::class)]
final class OperationBuilderSubmitTest extends TestCase
{
    public function test_submit_uploads_then_creates_workflow_and_returns_handle(): void
    {
        $tempPath = self::writeTempFile('hello world bytes');

        $captured = [];
        $http = self::stubClient([
            self::jsonResponse(200, [
                'success' => true,
                'data' => [
                    'file_id' => '01936fb1-7bb3-7000-8000-000000000001',
                    'original_name' => 'fixture.bin',
                    'mime_type' => 'application/octet-stream',
                    'size_bytes' => 17,
                ],
            ]),
            self::jsonResponse(201, [
                'success' => true,
                'data' => [
                    'workflow_id' => '01936fb2-0000-7000-8000-0000000000c1',
                    'status' => 'pending',
                    // webhook_secret has a fixed-length 64-char constraint
                    // in the OpenAPI spec; the generated validator rejects
                    // anything shorter or longer.
                    'webhook_secret' => 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789',
                    'created_at' => '2026-05-27T11:00:00Z',
                    'jobs' => [],
                    'delivery_plan' => [
                        'mode' => 'individual',
                        'selection_type' => 'terminal',
                        'outputs' => [],
                        'hidden_outputs' => [],
                    ],
                    'processing_plan' => ['jobs' => []],
                    'warnings' => [],
                ],
            ]),
        ], $captured);

        $client = self::makeClient($http);
        $builder = $client->compress($tempPath, ['quality' => 75, 'format' => 'webp']);
        $handle = $builder->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $this->assertInstanceOf(Handle::class, $handle);
        $this->assertSame('01936fb2-0000-7000-8000-0000000000c1', $handle->workflowId);
        $this->assertSame(
            'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789',
            $handle->webhookSecret,
        );
        // FF5a back-compat: the enriched Handle's toArray() shape must stay
        // byte-identical to {workflowId, webhookSecret} — no `client` leakage,
        // no extra keys, fixed field order.
        $this->assertSame(
            [
                'workflowId' => '01936fb2-0000-7000-8000-0000000000c1',
                'webhookSecret' => 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789',
            ],
            $handle->toArray(),
        );
        $this->assertArrayNotHasKey('client', $handle->toArray());

        // Exactly two outbound requests: upload + workflow create.
        $this->assertCount(2, $captured);
        $this->assertSame('POST', $captured[0]->getMethod());
        $this->assertStringContainsString('/api/uploads', (string) $captured[0]->getUri());
        $this->assertSame('POST', $captured[1]->getMethod());
        $this->assertStringContainsString('/api/workflows', (string) $captured[1]->getUri());

        // Workflow body MUST carry callback_url + the op options verbatim.
        $body = \json_decode((string) $captured[1]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);
        $this->assertSame('https://example.com/cb', $body['callback_url']);
        $this->assertSame('compress', $body['jobs'][0]['operations'][0]['type']);
        $this->assertSame(75, $body['jobs'][0]['operations'][0]['options']['quality']);
        $this->assertSame('webp', $body['jobs'][0]['operations'][0]['options']['format']);
        $this->assertSame('upload', $body['jobs'][0]['source']['type']);
        $this->assertSame('01936fb1-7bb3-7000-8000-000000000001', $body['jobs'][0]['source']['file_id']);
    }

    public function test_submit_handle_omits_webhook_secret_when_server_does_not_return_one(): void
    {
        $tempPath = self::writeTempFile('payload');

        $captured = [];
        $http = self::stubClient([
            self::jsonResponse(200, [
                'success' => true,
                'data' => [
                    'file_id' => '01936fb1-7bb3-7000-8000-000000000002',
                    'original_name' => 'fixture.bin',
                    'mime_type' => 'application/octet-stream',
                    'size_bytes' => 7,
                ],
            ]),
            self::jsonResponse(201, [
                'success' => true,
                'data' => [
                    'workflow_id' => '01936fb2-0000-7000-8000-0000000000c2',
                    'status' => 'pending',
                    'created_at' => '2026-05-27T11:00:00Z',
                    // No webhook_secret field.
                    'jobs' => [],
                    'delivery_plan' => [
                        'mode' => 'individual',
                        'selection_type' => 'terminal',
                        'outputs' => [],
                        'hidden_outputs' => [],
                    ],
                    'processing_plan' => ['jobs' => []],
                    'warnings' => [],
                ],
            ]),
        ], $captured);

        $client = self::makeClient($http);
        // ExVcchMz — width AND height are required (single-op thumbnail now
        // validates pre-upload); a width-only bag would throw before submit.
        $handle = $client->thumbnail($tempPath, ['width' => 320, 'height' => 240])
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $this->assertNull($handle->webhookSecret);
        $this->assertSame(
            ['workflowId' => '01936fb2-0000-7000-8000-0000000000c2'],
            $handle->toArray(),
            'toArray must drop absent optional keys (parity with TS JSON.stringify).',
        );
    }

    /**
     * 0Vcogefw — operation-first end-to-end audio bitrate drop (the
     * second call-site). `client->compress(<*.flac>, ['optimize' => Size])`
     * must lower to a wire payload WITHOUT `bitrate` (the worker rejects it
     * on lossless flac/wav), while `sample_rate` + `normalize` from the same
     * audio Size preset cell survive. Proves OperationBuilder.php:125 wires
     * `detectAudioLossless` into the resolver — a regression severing that
     * line (null, or dropping the `$media === 'audio'` guard) would keep the
     * baked bitrate and fail HERE. Mirrors the TS both-call-sites block
     * (builder.test.ts).
     */
    public function test_operation_first_flac_compress_drops_bitrate_on_the_wire(): void
    {
        $tempPath = self::writeTempFile('lossless audio bytes', 'track.flac');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000f1'),
            self::workflowCreateResponse('01936fb2-0000-7000-8000-0000000000f1'),
        ], $captured);

        $client = self::makeClient($http);
        $client->compress($tempPath, ['optimize' => OptimizeFor::Size])
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $options = self::capturedCompressOptions($captured);
        $this->assertArrayNotHasKey('bitrate', $options, 'lossless flac drops the shipped-preset bitrate');
        $this->assertSame(44100, $options['sample_rate'], 'the rest of the audio Size cell survives');
        $this->assertArrayHasKey('normalize', $options);
    }

    public function test_operation_first_mp3_compress_keeps_bitrate_on_the_wire(): void
    {
        $tempPath = self::writeTempFile('lossy audio bytes', 'song.mp3');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000f2'),
            self::workflowCreateResponse('01936fb2-0000-7000-8000-0000000000f2'),
        ], $captured);

        $client = self::makeClient($http);
        $client->compress($tempPath, ['optimize' => OptimizeFor::Size])
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $options = self::capturedCompressOptions($captured);
        $this->assertSame(96, $options['bitrate'], 'lossy mp3 keeps the shipped-preset bitrate');
        $this->assertSame(44100, $options['sample_rate']);
    }

    /**
     * Pull the lowered compress `options` out of the captured workflow-create
     * request (the second outbound request: upload then create).
     *
     * @param list<RequestInterface> $captured
     * @return array<string, mixed>
     */
    private static function capturedCompressOptions(array $captured): array
    {
        self::assertCount(2, $captured);
        $body = \json_decode((string) $captured[1]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $options = $body['jobs'][0]['operations'][0]['options'];
        self::assertIsArray($options);

        return $options;
    }

    // --- single-op builder validation wiring (ExVcchMz) ---------------------
    // The client factory methods convert()/thumbnail() must validate PRE-UPLOAD
    // (no HTTP). Empty stub queue → any HTTP call throws "queue exhausted", so an
    // asserted GislConfigError with an empty $captured proves the guard fires at
    // the factory before submit. Mirrors the TS gisl.test.ts proxy tests.

    public function test_single_op_thumbnail_rejects_missing_dimensions_pre_upload(): void
    {
        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));
        $tempPath = self::writeTempFile('img', 'photo.png');
        try {
            $client->thumbnail($tempPath, []);
            self::fail('thumbnail with no dimensions must throw pre-upload');
        } catch (GislConfigError $err) {
            self::assertSame('missing_required_field', $err->getReason());
        } finally {
            self::assertSame([], $captured, 'no HTTP may fire before the validation throw');
        }
    }

    public function test_single_op_thumbnail_rejects_unknown_key_pre_upload(): void
    {
        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));
        $tempPath = self::writeTempFile('img', 'photo.png');
        try {
            $client->thumbnail($tempPath, ['width' => 10, 'height' => 10, 'bogus' => 1]);
            self::fail('thumbnail with an unknown key must throw pre-upload');
        } catch (GislConfigError $err) {
            self::assertSame('unknown_field', $err->getReason());
        } finally {
            self::assertSame([], $captured);
        }
    }

    public function test_single_op_convert_rejects_missing_output_format_pre_upload(): void
    {
        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));
        $tempPath = self::writeTempFile('img', 'photo.png');
        try {
            $client->convert($tempPath, ['quality' => 80]);
            self::fail('convert with no output_format must throw pre-upload');
        } catch (GislConfigError $err) {
            self::assertSame('missing_required_field', $err->getReason());
        } finally {
            self::assertSame([], $captured);
        }
    }

    public function test_single_op_convert_rejects_format_alias_pre_upload(): void
    {
        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));
        $tempPath = self::writeTempFile('img', 'photo.png');
        try {
            $client->convert($tempPath, ['format' => 'webp']);
            self::fail('convert with the `format` alias must throw pre-upload');
        } catch (GislConfigError $err) {
            self::assertSame('unknown_field', $err->getReason());
        } finally {
            self::assertSame([], $captured);
        }
    }

    private static function writeTempFile(string $bytes, string $filename = 'fixture.bin'): string
    {
        $dir = \sys_get_temp_dir() . '/gisl-ergo-test-' . \bin2hex(\random_bytes(6));
        \mkdir($dir, 0700, true);
        $path = $dir . '/' . $filename;
        \file_put_contents($path, $bytes);
        return $path;
    }

    private static function uploadResponse(string $fileId): ResponseInterface
    {
        return self::jsonResponse(200, [
            'success' => true,
            'data' => [
                'file_id' => $fileId,
                'original_name' => 'fixture.bin',
                'mime_type' => 'application/octet-stream',
                'size_bytes' => 20,
            ],
        ]);
    }

    private static function workflowCreateResponse(string $workflowId): ResponseInterface
    {
        return self::jsonResponse(201, [
            'success' => true,
            'data' => [
                'workflow_id' => $workflowId,
                'status' => 'pending',
                'created_at' => '2026-05-27T11:00:00Z',
                'jobs' => [],
                'delivery_plan' => [
                    'mode' => 'individual',
                    'selection_type' => 'terminal',
                    'outputs' => [],
                    'hidden_outputs' => [],
                ],
                'processing_plan' => ['jobs' => []],
                'warnings' => [],
            ],
        ]);
    }

    private static function makeClient(ClientInterface $http): GislErgonomicClient
    {
        $factory = new HttpFactory();
        return new GislErgonomicClient(
            config: new GislClientConfig(
                baseUrl: 'https://api.test.example.com',
                apiKey: 'test-api-key',
                multipartConcurrency: 1,
            ),
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
        );
    }

    /**
     * @param list<ResponseInterface> $queue
     * @param-out list<RequestInterface> $captured
     */
    private static function stubClient(array $queue, array &$captured): ClientInterface
    {
        $captured = [];
        return new class ($queue, $captured) implements ClientInterface {
            /** @var list<ResponseInterface> */
            private array $queue;
            /** @var list<RequestInterface> */
            private array $captured;

            /**
             * @param list<ResponseInterface> $queue
             * @param list<RequestInterface>  $captured
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
                    throw new \RuntimeException(
                        'Stub PSR-18 client: response queue exhausted on request #'
                        . \count($this->captured),
                    );
                }
                return $next;
            }
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function jsonResponse(int $status, array $body): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            \json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}
