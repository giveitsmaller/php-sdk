<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Generated\OpenApi\Model\UploadResponse;
use Gisl\Generated\OpenApi\Model\WorkflowCreateResponse;
use Gisl\Generated\OpenApi\Model\WorkflowListResponse;
use Gisl\Generated\OpenApi\Model\WorkflowStatusResponse;
use Gisl\Sdk\Errors\GislApiError;
use Gisl\Sdk\Errors\GislAuthError;
use Gisl\Sdk\Errors\GislError;
use Gisl\Sdk\Errors\GislNetworkError;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\JobDefinitionPayload;
use Gisl\Sdk\OperationDef;
use Gisl\Sdk\Sources;
use Gisl\Sdk\WaitOptions;
use Gisl\Sdk\WorkflowCreatePayload;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(GislClient::class)]
final class GislClientTest extends TestCase
{
    private HttpFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    /**
     * Build a stub PSR-18 client whose `sendRequest` returns the queued
     * responses in order and captures every outbound request for assertion.
     *
     * @param list<ResponseInterface|\Throwable> $queue
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

    /**
     * @param array<string, mixed> $body
     */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        $encoded = \json_encode($body, JSON_THROW_ON_ERROR);
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            $encoded,
        );
    }

    private function makeClient(ClientInterface $http, string $baseUrl = 'https://api.example.com'): GislClient
    {
        return new GislClient(
            config: new GislClientConfig(baseUrl: $baseUrl, apiKey: 'sk_test'),
            httpClient: $http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    // ---------------------------------------------------------------
    // uploadFile — single-shot
    // ---------------------------------------------------------------

    public function testUploadFileSingleShotHappyPath(): void
    {
        $tmp = $this->writeTempFile('hello world');
        try {
            $captured = [];
            $http = $this->stubClient([
                $this->jsonResponse(200, [
                    'success' => true,
                    'data' => [
                        'file_id' => '01936fb2-0000-7000-8000-000000000001',
                        'original_name' => \basename($tmp),
                        'mime_type' => 'text/plain',
                        'size_bytes' => 11,
                    ],
                ]),
            ], $captured);

            $client = $this->makeClient($http);
            $result = $client->uploadFile($tmp);

            self::assertInstanceOf(UploadResponse::class, $result);
            self::assertSame('01936fb2-0000-7000-8000-000000000001', $result->getFileId());

            self::assertCount(1, $captured);
            $request = $captured[0];
            self::assertSame('POST', $request->getMethod());
            self::assertSame('/api/uploads', $request->getUri()->getPath());
            self::assertSame('https', $request->getUri()->getScheme());
            self::assertStringStartsWith('multipart/form-data; boundary=', $request->getHeaderLine('Content-Type'));
            self::assertSame('Bearer sk_test', $request->getHeaderLine('Authorization'));
        } finally {
            @\unlink($tmp);
        }
    }

    public function testUploadFileRejectsNonSeekableStream(): void
    {
        // VOxtu0RZ-B4 Option B: a non-seekable stream (a pipe) has no random
        // access for chunked PUTs + no reliable size, so it is rejected with an
        // actionable error rather than a hidden buffer-to-disk copy.
        $captured = [];
        $client = $this->makeClient($this->stubClient([], $captured));

        $pipe = \popen('printf hello', 'r');
        self::assertNotFalse($pipe);
        try {
            $this->expectException(GislConfigError::class);
            $this->expectExceptionMessageMatches('/non-seekable stream/');
            $client->uploadFile($pipe);
        } finally {
            \pclose($pipe);
            self::assertCount(0, $captured, 'a rejected non-seekable stream must not upload');
        }
    }

    public function testUploadFileSeekableStreamSingleShotHappyPath(): void
    {
        // A seekable in-memory stream (php://temp) uploads end-to-end via the
        // single-shot path, just like a filesystem path (VOxtu0RZ-B4).
        $stream = \fopen('php://temp', 'r+b');
        self::assertNotFalse($stream);
        \fwrite($stream, 'hello world');
        \rewind($stream);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'file_id' => '01936fb2-0000-7000-8000-0000000000ab',
                    'original_name' => 'upload.bin',
                    'mime_type' => 'application/octet-stream',
                    'size_bytes' => 11,
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        try {
            $result = $client->uploadFile($stream);
        } finally {
            \fclose($stream);
        }

        self::assertInstanceOf(UploadResponse::class, $result);
        self::assertSame('01936fb2-0000-7000-8000-0000000000ab', $result->getFileId());
        self::assertCount(1, $captured);
        $request = $captured[0];
        self::assertSame('/api/uploads', $request->getUri()->getPath());
        // The stream's bytes crossed the wire in the multipart body, and the
        // SDK never closed the caller's stream (we fclose it ourselves above).
        self::assertStringContainsString('hello world', (string) $request->getBody());
    }

    public function testUploadFileRejectsMissingPath(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([], $captured));

        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/File not found/');
        $client->uploadFile('/nonexistent/path/photo.jpg');
    }

    /**
     * @return array<string, array{0: int|float|array<int|string,mixed>|object|bool}>
     */
    public static function nonStringNonResourceProvider(): array
    {
        return [
            'int'    => [42],
            'array'  => [['file' => 'x']],
            'object' => [new \stdClass()],
            'bool'   => [true],
            'float'  => [3.14],
        ];
    }

    /**
     * @param int|float|array<int|string,mixed>|object|bool $bogus
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonStringNonResourceProvider')]
    public function testUploadFileRejectsNonStringNonResource(mixed $bogus): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([], $captured));
        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/expected a string filesystem path or a stream resource/');
        $client->uploadFile($bogus);
    }

    public function testUploadFileRejectsFilenameWithIllegalMultipartChars(): void
    {
        // RFC 7578 §4.2 forbids quotes, CR, LF, NUL in Content-Disposition
        // filename values. Reject loudly rather than silently sanitising —
        // bad filenames usually indicate a broken caller pipeline.
        $tmp = \sys_get_temp_dir() . '/gisl-bad-"-name.txt';
        \file_put_contents($tmp, 'x');
        try {
            $captured = [];
            $client = $this->makeClient($this->stubClient([], $captured));
            $this->expectException(GislConfigError::class);
            $this->expectExceptionMessageMatches('/illegal characters for multipart Content-Disposition/');
            $client->uploadFile($tmp);
        } finally {
            @\unlink($tmp);
        }
    }

    public function testUploadFileRoutesOversizeToMultipart(): void
    {
        // VOxtu0RZ-B1 enabled multipart routing for files above the threshold.
        // Detailed multipart wire-shape coverage lives in
        // GislClientMultipartTest; this assertion is just a smoke test that
        // routing leaves the single-shot path. The stub deliberately does not
        // pre-load any responses, so the moment the multipart path tries to
        // call `/api/uploads/multipart/initiate` we observe the queue
        // exhaustion as a `RuntimeException` from the stub. A regression that
        // re-routed back to single-shot would surface an `application/json`
        // POST against `/api/uploads` instead.
        $tmp = $this->writeTempFile(\str_repeat('a', 11_000_000));
        try {
            $captured = [];
            $client = $this->makeClient($this->stubClient([], $captured));
            try {
                $client->uploadFile($tmp);
                self::fail('Expected stub queue exhaustion');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('queue exhausted', $e->getMessage());
            }

            self::assertCount(1, $captured);
            $request = $captured[0];
            self::assertSame('POST', $request->getMethod());
            self::assertStringEndsWith('/api/uploads/multipart/initiate', (string) $request->getUri());
        } finally {
            @\unlink($tmp);
        }
    }

    // ---------------------------------------------------------------
    // createWorkflow + getWorkflowStatus
    // ---------------------------------------------------------------

    public function testCreateWorkflowRoundTripsTypedPayload(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(201, [
                'success' => true,
                'data' => [
                    'workflow_id' => '01936fb2-0000-7000-8000-000000000123',
                    'status' => 'pending',
                    'jobs' => [],
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $result = $client->createWorkflow(new WorkflowCreatePayload(
            jobs: [
                new JobDefinitionPayload(
                    operations: [new OperationDef(type: 'compress', options: ['quality' => 80])],
                    id: 'compressed',
                    source: Sources::upload('file_abc'),
                ),
            ],
        ));

        self::assertInstanceOf(WorkflowCreateResponse::class, $result);
        self::assertSame('01936fb2-0000-7000-8000-000000000123', $result->getWorkflowId());

        self::assertCount(1, $captured);
        $request = $captured[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/workflows', $request->getUri()->getPath());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));

        $body = \json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertArrayHasKey('jobs', $body);
        self::assertSame('compressed', $body['jobs'][0]['id']);
        self::assertSame(['type' => 'upload', 'file_id' => 'file_abc'], $body['jobs'][0]['source']);
        self::assertSame(['type' => 'compress', 'options' => ['quality' => 80]], $body['jobs'][0]['operations'][0]);
    }

    public function testGetWorkflowStatusHydratesNestedJobsAsTypedObjects(): void
    {
        // Pin: ObjectSerializer::deserialize recursively hydrates nested
        // model fields. With shallow hydration (`new $modelClass($data)`)
        // jobs would come back as raw arrays and getJobs()[0]->getJobId()
        // would fatal at runtime. Codex review caught this; this test is
        // the regression guard.
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'workflow_id' => '01936fb2-0000-7000-8000-000000000abc',
                    'status' => 'completed',
                    'jobs' => [
                        [
                            'ref' => 'compressed',
                            'job_id' => '01936fb2-0000-7000-8000-000000000bbb',
                            'status' => 'completed',
                            'depends_on' => [],
                            'operations' => [],
                        ],
                    ],
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $result = $client->getWorkflowStatus('01936fb2-0000-7000-8000-000000000abc');

        $jobs = $result->getJobs();
        self::assertIsArray($jobs);
        self::assertCount(1, $jobs);
        // The load-bearing assertion: nested element is the generated
        // JobResponse object, NOT a raw array.
        self::assertInstanceOf(\Gisl\Generated\OpenApi\Model\JobResponse::class, $jobs[0]);
        self::assertSame('compressed', $jobs[0]->getRef());
        self::assertSame('01936fb2-0000-7000-8000-000000000bbb', $jobs[0]->getJobId());
    }

    public function testGetWorkflowStatusUsesStatusSubpath(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'workflow_id' => '01936fb2-0000-7000-8000-000000000222',
                    'status' => 'in_progress',
                    'jobs' => [],
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $result = $client->getWorkflowStatus('01936fb2-0000-7000-8000-000000000222');

        self::assertInstanceOf(WorkflowStatusResponse::class, $result);
        self::assertSame('01936fb2-0000-7000-8000-000000000222', $result->getWorkflowId());
        // Pin URL exactly: /api/workflows/{id}/status (mirrors TS client.ts:1013).
        self::assertCount(1, $captured);
        self::assertSame('GET', $captured[0]->getMethod());
        self::assertSame('/api/workflows/01936fb2-0000-7000-8000-000000000222/status', $captured[0]->getUri()->getPath());
    }

    // ---------------------------------------------------------------
    // Anonymous-read capability header (X-Workflow-Capability) — lets a
    // session-less caller read its own null-owner workflow by passing back
    // the one-time `cap` from the anonymous workflow-create response.
    // ---------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    private function statusEnvelope(array $data = []): ResponseInterface
    {
        // The generated WorkflowStatusResponse validates `workflow_id` against a
        // UUIDv7 pattern on hydration, so the envelope must carry a conformant id
        // (the request-path arg passed to getWorkflowStatus is unconstrained).
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => \array_merge(
                [
                    'workflow_id' => '01936fb2-0000-7000-8000-0000000000c1',
                    'status' => 'completed',
                    'jobs' => [],
                ],
                $data,
            ),
        ]);
    }

    public function testGetWorkflowStatusSendsCapabilityHeaderWhenProvided(): void
    {
        $captured = [];
        $http = $this->stubClient([$this->statusEnvelope()], $captured);
        $client = $this->makeClient($http);

        $client->getWorkflowStatus('wf-1', 'wcap_abc123');

        self::assertCount(1, $captured);
        self::assertSame('wcap_abc123', $captured[0]->getHeaderLine('X-Workflow-Capability'));
    }

    public function testGetWorkflowStatusOmitsCapabilityHeaderWhenAbsent(): void
    {
        $captured = [];
        $http = $this->stubClient([$this->statusEnvelope()], $captured);
        $client = $this->makeClient($http);

        $client->getWorkflowStatus('wf-1');

        self::assertCount(1, $captured);
        self::assertFalse($captured[0]->hasHeader('X-Workflow-Capability'));
    }

    public function testGetWorkflowStatusTreatsEmptyCapabilityAsAbsent(): void
    {
        $captured = [];
        $http = $this->stubClient([$this->statusEnvelope()], $captured);
        $client = $this->makeClient($http);

        $client->getWorkflowStatus('wf-1', '');

        self::assertCount(1, $captured);
        self::assertFalse($captured[0]->hasHeader('X-Workflow-Capability'));
    }

    public function testGetWorkflowDownloadsSendsCapabilityHeaderWhenProvided(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, ['success' => true, 'data' => ['downloads' => []]]),
        ], $captured);
        $client = $this->makeClient($http);

        $client->getWorkflowDownloads('wf-1', 'wcap_dl');

        self::assertCount(1, $captured);
        self::assertSame('wcap_dl', $captured[0]->getHeaderLine('X-Workflow-Capability'));
    }

    public function testGetWorkflowDownloadsOmitsCapabilityHeaderWhenAbsent(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, ['success' => true, 'data' => ['downloads' => []]]),
        ], $captured);
        $client = $this->makeClient($http);

        $client->getWorkflowDownloads('wf-1');

        self::assertCount(1, $captured);
        self::assertFalse($captured[0]->hasHeader('X-Workflow-Capability'));
    }

    public function testWaitForWorkflowForwardsCapabilityToEachPoll(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->statusEnvelope(['status' => 'in_progress']),
            $this->statusEnvelope(['status' => 'completed']),
        ], $captured);
        $client = $this->makeClient($http);

        $client->waitForWorkflow('wf-1', new WaitOptions(
            intervalMs: 0,
            timeoutMs: 5000,
            capability: 'wcap_poll',
        ));

        self::assertCount(2, $captured);
        self::assertSame('wcap_poll', $captured[0]->getHeaderLine('X-Workflow-Capability'));
        self::assertSame('wcap_poll', $captured[1]->getHeaderLine('X-Workflow-Capability'));
    }

    // ---------------------------------------------------------------
    // Envelope unwrap + error mapping
    // ---------------------------------------------------------------

    public function testFailureEnvelope4xxThrowsGislApiErrorWithPayload(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(422, [
                'success' => false,
                'error' => 'validation_failed',
                'message' => 'jobs[0].operations[0].options.quality must be 1-100',
                'details' => [['field' => 'quality', 'reason' => 'out_of_range']],
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->createWorkflow(new WorkflowCreatePayload(jobs: []));
            self::fail('Expected GislApiError');
        } catch (GislApiError $e) {
            self::assertSame(422, $e->statusCode);
            self::assertSame('validation_failed', $e->errorCode);
            self::assertSame(
                'jobs[0].operations[0].options.quality must be 1-100',
                $e->getMessage(),
            );
            self::assertSame(
                [['field' => 'quality', 'reason' => 'out_of_range']],
                $e->payload['details'],
            );
            // Sibling type assertion: Auth is a separate subclass.
            self::assertNotInstanceOf(GislAuthError::class, $e);
        }
    }

    public function testFailureEnvelope401ThrowsGislAuthError(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(401, [
                'success' => false,
                'error' => 'invalid_api_key',
                'message' => 'API key is missing or invalid.',
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus('01936fb2-0000-7000-8000-0000000000ff');
            self::fail('Expected GislAuthError');
        } catch (GislAuthError $e) {
            // GislAuthError extends GislApiError per errors.ts:187.
            self::assertInstanceOf(GislApiError::class, $e);
            self::assertSame(401, $e->statusCode);
            self::assertSame('invalid_api_key', $e->errorCode);
        }
    }

    public function testNetworkExceptionWrappedAsGislNetworkError(): void
    {
        $captured = [];
        $http = $this->stubClient([
            new class ('boom') extends \RuntimeException implements ClientExceptionInterface {},
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus('01936fb2-0000-7000-8000-0000000000ff');
            self::fail('Expected GislNetworkError');
        } catch (GislNetworkError $e) {
            // Pin exact wrapper format so a regression on the prefix is loud.
            self::assertSame('HTTP transport failed: boom', $e->getMessage());
            self::assertNotNull($e->getPrevious());
        }
    }

    public function testFailureEnvelope5xxThrowsGislApiErrorNotAuth(): void
    {
        // Pin: only 401 maps to GislAuthError. Every other failure status
        // (5xx included) must land on the bare GislApiError type.
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(503, [
                'success' => false,
                'error' => 'service_unavailable',
                'message' => 'Backend overloaded.',
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus('01936fb2-0000-7000-8000-0000000000ff');
            self::fail('Expected GislApiError');
        } catch (GislApiError $e) {
            self::assertNotInstanceOf(GislAuthError::class, $e);
            self::assertSame(503, $e->statusCode);
            self::assertSame('service_unavailable', $e->errorCode);
        }
    }

    public function testFailureEnvelopeWithoutErrorFieldFallsBackToUnknown(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(500, ['success' => false]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus('01936fb2-0000-7000-8000-0000000000ff');
            self::fail('Expected GislApiError');
        } catch (GislApiError $e) {
            self::assertSame('unknown_error', $e->errorCode);
            // Synthesised message when both `error` and `message` are missing.
            self::assertSame(
                'Request failed with status 500 (unknown_error).',
                $e->getMessage(),
            );
        }
    }

    public function testFailureEnvelopeWithNonStringErrorFallsBackToUnknown(): void
    {
        // Wire-corruption / mis-typed envelope: `error` carries a non-string
        // value (e.g. an int from a buggy upstream). The unwrap path must
        // not blow up — falls through to 'unknown_error'.
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(400, [
                'success' => false,
                'error' => 12345,
                'message' => 'Something bad.',
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus('01936fb2-0000-7000-8000-0000000000ff');
            self::fail('Expected GislApiError');
        } catch (GislApiError $e) {
            self::assertSame('unknown_error', $e->errorCode);
            self::assertSame('Something bad.', $e->getMessage());
        }
    }

    public function testNoApiKeyOmitsAuthorizationHeader(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => ['workflow_id' => '01936fb2-0000-7000-8000-000000000333', 'status' => 'pending', 'jobs' => []],
            ]),
        ], $captured);

        $client = new GislClient(
            config: new GislClientConfig(baseUrl: 'https://api.example.com'),
            httpClient: $http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
        $client->getWorkflowStatus('01936fb2-0000-7000-8000-000000000333');

        self::assertCount(1, $captured);
        self::assertFalse(
            $captured[0]->hasHeader('Authorization'),
            'No Authorization header expected when apiKey is null.',
        );
    }

    public function testExtraHeadersOverrideConfigHeadersOnCollision(): void
    {
        // request-loop precedence: extraHeaders applied AFTER config headers,
        // so a colliding key in extraHeaders wins. Pin so the loop ordering
        // doesn't silently regress.
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'file_id' => '01936fb2-0000-7000-8000-000000000001',
                    'original_name' => 't',
                    'mime_type' => 'application/octet-stream',
                    'size_bytes' => 1,
                ],
            ]),
        ], $captured);

        $client = new GislClient(
            config: new GislClientConfig(
                baseUrl: 'https://api.example.com',
                apiKey: 'sk_x',
                headers: ['Content-Type' => 'application/json'], // would collide
            ),
            httpClient: $http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );

        $tmp = $this->writeTempFile('a');
        try {
            $client->uploadFile($tmp);
        } finally {
            @\unlink($tmp);
        }

        self::assertCount(1, $captured);
        // uploadFile pushes its own multipart Content-Type via extraHeaders;
        // it must win over the config-supplied 'application/json'.
        self::assertStringStartsWith(
            'multipart/form-data; boundary=',
            $captured[0]->getHeaderLine('Content-Type'),
        );
    }

    public function testEmptyBodyOnExpected200ThrowsGislError(): void
    {
        $captured = [];
        $http = $this->stubClient([
            new Response(200, ['Content-Type' => 'application/json'], ''),
        ], $captured);

        $client = $this->makeClient($http);
        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/Empty response body/');
        $client->getWorkflowStatus('01936fb2-0000-7000-8000-0000000000ff');
    }

    public function testNonJsonBodyThrowsGislError(): void
    {
        $captured = [];
        $http = $this->stubClient([
            new Response(200, ['Content-Type' => 'application/json'], '<html>oops</html>'),
        ], $captured);

        $client = $this->makeClient($http);
        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/non-JSON/');
        $client->getWorkflowStatus('01936fb2-0000-7000-8000-0000000000ff');
    }

    public function testSuccessEnvelopeWithoutDataThrowsGislError(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, ['success' => true]),
        ], $captured);

        $client = $this->makeClient($http);
        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/missing `data` field/');
        $client->getWorkflowStatus('01936fb2-0000-7000-8000-0000000000ff');
    }

    public function testCustomHeadersAndUserAgentAttached(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => ['workflow_id' => '01936fb2-0000-7000-8000-000000000444', 'status' => 'pending', 'jobs' => []],
            ]),
        ], $captured);

        $client = new GislClient(
            config: new GislClientConfig(
                baseUrl: 'https://api.example.com',
                apiKey: 'sk_x',
                headers: ['X-Trace-Id' => 'trace-1'],
            ),
            httpClient: $http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
        $client->getWorkflowStatus('01936fb2-0000-7000-8000-000000000444');

        self::assertCount(1, $captured);
        $request = $captured[0];
        self::assertSame('trace-1', $request->getHeaderLine('X-Trace-Id'));
        // zDwyRcaD: the CURRENT manifest version, read here independently of the
        // SDK's own reader, so a stale literal (it was 0.1.0) cannot pass.
        $manifest = \json_decode((string) \file_get_contents(\dirname(__DIR__, 2) . '/composer.json'), true);
        self::assertIsArray($manifest);
        self::assertIsString($manifest['version']);
        self::assertSame('giveitsmaller-sdk-php/' . $manifest['version'], $request->getHeaderLine('User-Agent'));
        self::assertNotSame('giveitsmaller-sdk-php/0.1.0', $request->getHeaderLine('User-Agent'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
    }

    public function testListWorkflowsBuildsQueryAndHydrates(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->workflowListEnvelope(
                [$this->workflowSummary('01936fb2-0000-7000-8000-000000000001')],
                nextCursor: 'cursor_next',
                isTruncated: true,
            )),
        ], $captured);
        $client = $this->makeClient($http);

        $page = $client->listWorkflows('cursor_abc', 50);

        self::assertInstanceOf(WorkflowListResponse::class, $page);
        self::assertCount(1, $page->getWorkflows() ?? []);
        self::assertSame('cursor_next', $page->getNextCursor());
        self::assertTrue($page->getIsTruncated());

        self::assertCount(1, $captured);
        $uri = $captured[0]->getUri();
        self::assertSame('GET', $captured[0]->getMethod());
        self::assertSame('/api/workflows', $uri->getPath());
        // Query carries cursor + limit.
        \parse_str($uri->getQuery(), $q);
        self::assertSame('cursor_abc', $q['cursor']);
        self::assertSame('50', $q['limit']);
    }

    public function testListWorkflowsSendsTheArchivedFilter(): void
    {
        foreach ([[true, 'true'], [false, 'false']] as [$archived, $expected]) {
            $captured = [];
            $client = $this->makeClient($this->stubClient([
                $this->jsonResponse(200, $this->workflowListEnvelope([], nextCursor: null, isTruncated: false)),
            ], $captured));

            $client->listWorkflows(archived: $archived);

            \parse_str($captured[0]->getUri()->getQuery(), $q);
            self::assertSame($expected, $q['archived'] ?? null, 'mWQsiUun: archived filter on the wire');
        }
    }

    public function testListWorkflowsOmitsEmptyQueryOnFirstPage(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->workflowListEnvelope([], nextCursor: null, isTruncated: false)),
        ], $captured);
        $client = $this->makeClient($http);

        $client->listWorkflows();

        self::assertSame('/api/workflows', $captured[0]->getUri()->getPath());
        self::assertSame('', $captured[0]->getUri()->getQuery(), 'no cursor/limit → bare path');
    }

    public function testWorkflowsAutoPaginatesAcrossPages(): void
    {
        $captured = [];
        $http = $this->stubClient([
            // Page 1: truncated, points at cursor_2.
            $this->jsonResponse(200, $this->workflowListEnvelope([
                $this->workflowSummary('01936fb2-0000-7000-8000-0000000000a1'),
                $this->workflowSummary('01936fb2-0000-7000-8000-0000000000a2'),
            ], nextCursor: 'cursor_2', isTruncated: true)),
            // Page 2: final page.
            $this->jsonResponse(200, $this->workflowListEnvelope([
                $this->workflowSummary('01936fb2-0000-7000-8000-0000000000a3'),
            ], nextCursor: null, isTruncated: false)),
        ], $captured);
        $client = $this->makeClient($http);

        $ids = [];
        foreach ($client->workflows(2) as $summary) {
            $ids[] = $summary->getWorkflowId();
        }

        // All 3 rows yielded across the 2 pages, in order.
        self::assertSame([
            '01936fb2-0000-7000-8000-0000000000a1',
            '01936fb2-0000-7000-8000-0000000000a2',
            '01936fb2-0000-7000-8000-0000000000a3',
        ], $ids);

        // Exactly 2 requests: first with no cursor (limit only), second with cursor_2.
        self::assertCount(2, $captured);
        \parse_str($captured[0]->getUri()->getQuery(), $q1);
        self::assertArrayNotHasKey('cursor', $q1, 'first page carries no cursor');
        self::assertSame('2', $q1['limit']);
        \parse_str($captured[1]->getUri()->getQuery(), $q2);
        self::assertSame('cursor_2', $q2['cursor']);
        self::assertSame('2', $q2['limit']);
    }

    public function testWorkflowsStopsOnNonAdvancingCursor(): void
    {
        // A misbehaving server that reports is_truncated:true but repeats the
        // SAME cursor must NOT loop forever — only 2 requests fire (the stub
        // queue has exactly 2; a 3rd would throw "queue exhausted").
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->workflowListEnvelope(
                [$this->workflowSummary('01936fb2-0000-7000-8000-0000000000b1')],
                nextCursor: 'stuck',
                isTruncated: true,
            )),
            $this->jsonResponse(200, $this->workflowListEnvelope(
                [$this->workflowSummary('01936fb2-0000-7000-8000-0000000000b2')],
                nextCursor: 'stuck', // same cursor it was just called with → stop
                isTruncated: true,
            )),
        ], $captured);
        $client = $this->makeClient($http);

        $ids = [];
        foreach ($client->workflows() as $summary) {
            $ids[] = $summary->getWorkflowId();
        }

        self::assertSame([
            '01936fb2-0000-7000-8000-0000000000b1',
            '01936fb2-0000-7000-8000-0000000000b2',
        ], $ids);
        self::assertCount(2, $captured, 'non-advancing cursor stops the walk (no infinite loop)');
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function workflowSummary(string $workflowId): array
    {
        return [
            'workflow_id' => $workflowId,
            'status' => 'completed',
            'created_at' => '2026-06-10T11:00:00Z',
            'jobs' => [
                ['job_id' => '01936fb3-0001-7000-8000-000000000001', 'ref' => 'op', 'job_type' => 'image', 'status' => 'completed'],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $summaries
     * @return array<string, mixed>
     */
    private function workflowListEnvelope(array $summaries, ?string $nextCursor, bool $isTruncated): array
    {
        return [
            'success' => true,
            'data' => [
                'workflows' => $summaries,
                'next_cursor' => $nextCursor,
                'is_truncated' => $isTruncated,
            ],
        ];
    }

    private function writeTempFile(string $contents): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'gisl-sdk-test-');
        if ($path === false) {
            self::fail('tempnam failed');
        }
        \file_put_contents($path, $contents);
        return $path;
    }
}
