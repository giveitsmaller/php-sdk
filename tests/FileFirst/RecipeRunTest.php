<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\FileFirst;

use Gisl\Sdk\Ergonomic\Handle;
use Gisl\Sdk\Ergonomic\ProcessingProgressEvent;
use Gisl\Sdk\Ergonomic\ProgressEvent;
use Gisl\Sdk\Ergonomic\UploadProgressEvent;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislItemFailedError;
use Gisl\Sdk\Errors\GislNetworkError;
use Gisl\Sdk\Errors\GislNoSuchKeyError;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\Recipe;
use Gisl\Sdk\FileFirst\RunResult;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * FF2b — `Recipe::run()` end-to-end execution over a stubbed PSR-18 client:
 * upload (when required) → createWorkflow → await terminal (SSE-first with a
 * poll fallback) → flatten downloads into a {@see RunResult}. The stub serves
 * responses in call order. Mirrors the TS `file-first-run.test.ts`.
 */
final class RecipeRunTest extends TestCase
{
    private const WORKFLOW_ID = '01936fb2-0000-7000-8000-000000000001';

    private HttpFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    /**
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
                    throw $next;
                }
                return $next;
            }
        };
    }

    private function makeClient(ClientInterface $http): GislClient
    {
        return new GislClient(
            config: new GislClientConfig(baseUrl: 'https://api.example.com', apiKey: 'sk_test'),
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
        return new Response($status, ['Content-Type' => 'application/json'], (string) \json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function uploadResponse(string $fileId = '01936fb1-7bb3-7000-8000-0000000060f1'): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['file_id' => $fileId, 'content_type' => 'image/jpeg', 'size_bytes' => 2048],
        ]);
    }

    private function createResponse(): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['workflow_id' => self::WORKFLOW_ID, 'status' => 'pending'],
        ]);
    }

    private function createResponseWithWebhookSecret(): ResponseInterface
    {
        return $this->jsonResponse(201, [
            'success' => true,
            'data' => [
                'workflow_id' => self::WORKFLOW_ID,
                'status' => 'pending',
                // webhook_secret has a fixed-length 64-char constraint in the
                // OpenAPI spec; the generated validator rejects other lengths.
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
        ]);
    }

    private function sseResponse(string $sse): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/event-stream'], $sse);
    }

    /**
     * @param list<array<string, mixed>> $jobs
     */
    private function statusResponse(string $status, array $jobs = []): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['workflow_id' => self::WORKFLOW_ID, 'status' => $status, 'jobs' => $jobs],
        ]);
    }

    private function downloadsResponse(): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => [
                'downloads' => [
                    [
                        'job_id' => '01936fb3-0001-7000-8000-0000000060f3',
                        'ref' => 'op',
                        'files' => [
                            [
                                'operation' => 'compress',
                                'operation_id' => '01936fb4-0001-7000-8000-0000000060f4',
                                'filename' => 'photo_compressed.jpg',
                                'size_bytes' => 512,
                                'download_url' => 'https://signed.example.com/photo_compressed.jpg',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function tempImage(): string
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'gisl_ff_');
        self::assertIsString($tmp);
        $path = $tmp . '.jpg';
        \rename($tmp, $path);
        \file_put_contents($path, \str_repeat('x', 2048));
        return $path;
    }

    private function recipe(GislClient $client, FileInput $input, ?string $key = null): Recipe
    {
        return new Recipe($input, $key, [], null, null, $client);
    }

    private const TERMINAL_SSE = "event: workflow.completed\ndata: {\"status\":\"completed\"}\n\n";

    #[Test]
    public function happy_path_via_sse_uploads_creates_awaits_and_flattens_downloads(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->uploadResponse(),
            $this->createResponse(),
            $this->sseResponse(self::TERMINAL_SSE),
            $this->statusResponse('completed'),
            $this->downloadsResponse(),
        ], $captured);

        $client = $this->makeClient($http);
        $path = $this->tempImage();
        try {
            $result = $this->recipe($client, FileInput::path($path))->compress()->run();
        } finally {
            @\unlink($path);
        }

        self::assertSame(self::WORKFLOW_ID, $result->workflowId);
        self::assertSame('completed', $result->state);
        self::assertTrue($result->ok);
        self::assertSame([], $result->failed);
        self::assertCount(1, $result->artifacts);
        self::assertSame('https://signed.example.com/photo_compressed.jpg', $result->artifacts[0]->url);
        self::assertSame('photo_compressed.jpg', $result->artifacts[0]->filename);
        self::assertSame(512, $result->artifacts[0]->sizeBytes);
        self::assertSame('compress', $result->artifacts[0]->operation);
        // Single output → url sugar set.
        self::assertSame('https://signed.example.com/photo_compressed.jpg', $result->url);
        // Keyless success → succeeded=[{key:null, outputs}].
        self::assertCount(1, $result->succeeded);
        self::assertNull($result->succeeded[0]->key);
        self::assertSame($result->artifacts, $result->succeeded[0]->outputs);
        // upload + create + sse + status + downloads = 5 requests.
        self::assertCount(5, $captured);
    }

    #[Test]
    public function use_sse_false_polls_directly_and_never_opens_the_sse_stream(): void
    {
        // wf133EDR — useSSE:false routes straight to the poll fallback: the
        // /events SSE stream is never opened, and the terminal state is resolved
        // via the /status poll. The queue carries NO sse response. The default
        // (SSE-first) path is pinned by happy_path_via_sse_... above (which
        // queues + consumes an sse response). Mirrors the operation-first
        // OperationBuilderRunTest useSSE:false cases.
        $captured = [];
        $http = $this->stubClient([
            $this->createResponse(),
            $this->statusResponse('completed'),   // poll resolves terminal here
            $this->downloadsResponse(),
        ], $captured);

        $client = $this->makeClient($http);
        $result = $this->recipe($client, FileInput::uploadId('file_existing'))
            ->compress()
            ->run(useSSE: false, pollIntervalMs: 0);

        self::assertSame('completed', $result->state);
        self::assertTrue($result->ok);
        self::assertCount(1, $result->artifacts);

        // Exactly create + status + downloads — no SSE request was issued.
        self::assertCount(3, $captured);
        $hitStatus = false;
        foreach ($captured as $request) {
            $uri = (string) $request->getUri();
            self::assertStringNotContainsString('/events', $uri, 'useSSE:false must not open the SSE stream');
            if (\str_contains($uri, '/status')) {
                $hitStatus = true;
            }
        }
        self::assertTrue($hitStatus, 'useSSE:false must resolve terminal via the /status poll');
    }

    #[Test]
    public function a_resource_input_carries_its_filename_and_content_type_to_the_upload(): void
    {
        // fFwaKsN5: a file-first resource input's filename/contentType hints flow
        // into the multipart upload — the server receives a real name + Content-
        // Type (a nameless stream would otherwise upload as `upload.bin`), and
        // compress(optimize:) resolves the image preset from the hint instead of
        // failing media_unknown.
        $captured = [];
        $http = $this->stubClient([
            $this->uploadResponse(),
            $this->createResponse(),
            $this->sseResponse(self::TERMINAL_SSE),
            $this->statusResponse('completed'),
            $this->downloadsResponse(),
        ], $captured);

        $client = $this->makeClient($http);
        $stream = \fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        \fwrite($stream, \str_repeat('x', 2048));
        \rewind($stream);
        try {
            $result = $this->recipe($client, FileInput::resource($stream, filename: 'photo.jpg', contentType: 'image/jpeg'))
                ->compress(OptimizeFor::Balanced)
                ->run();
        } finally {
            \fclose($stream);
        }

        self::assertSame('completed', $result->state);
        // The upload is the FIRST captured request — assert the multipart `file`
        // part carries the hinted filename + Content-Type.
        $uploadBody = (string) $captured[0]->getBody();
        self::assertStringContainsString('filename="photo.jpg"', $uploadBody);
        self::assertStringContainsString('Content-Type: image/jpeg', $uploadBody);
    }

    #[Test]
    public function upload_id_arm_makes_no_upload_call_and_uses_the_id_verbatim(): void
    {
        $captured = [];
        $http = $this->stubClient([
            // NO upload response queued — an upload call would exhaust nothing
            // useful and the create body must carry the verbatim id.
            $this->createResponse(),
            $this->sseResponse(self::TERMINAL_SSE),
            $this->statusResponse('completed'),
            $this->downloadsResponse(),
        ], $captured);

        $client = $this->makeClient($http);
        $result = $this->recipe($client, FileInput::uploadId('file_existing'))->convert('webp')->run();

        self::assertSame('completed', $result->state);
        // No upload happened → first captured request is the workflow create.
        self::assertCount(4, $captured);
        self::assertStringContainsString('/api/workflows', (string) $captured[0]->getUri());
        $body = (string) $captured[0]->getBody();
        self::assertStringContainsString('file_existing', $body);
    }

    #[Test]
    public function poll_fallback_when_sse_ends_without_a_terminal_event(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->uploadResponse(),
            $this->createResponse(),
            // SSE ends with only a progress frame → no terminal → fall to poll.
            $this->sseResponse("event: operation.progress\ndata: {\"progress\":50}\n\n"),
            $this->statusResponse('completed'),   // waitForWorkflow first poll
            $this->downloadsResponse(),
        ], $captured);

        $client = $this->makeClient($http);
        $path = $this->tempImage();
        try {
            $result = $this->recipe($client, FileInput::path($path))->compress()->run(pollIntervalMs: 0);
        } finally {
            @\unlink($path);
        }

        self::assertSame('completed', $result->state);
        self::assertTrue($result->ok);
        self::assertCount(1, $result->artifacts);
    }

    #[Test]
    public function poll_fallback_when_terminal_sse_frame_is_malformed(): void
    {
        // TYNjcjpo: a malformed *terminal* SSE frame (workflow.completed with a
        // corrupt data: body) is SKIPPED by the parser, so the stream ends
        // without a recognised terminal event and run() falls back to poll — it
        // resolves via getWorkflowStatus, it does NOT hang. PHP already dropped
        // malformed frames; this locks that path as a regression guard now that
        // the drop is also observable via onParseError.
        $captured = [];
        $http = $this->stubClient([
            $this->uploadResponse(),
            $this->createResponse(),
            // Terminal frame present by NAME but its data: body is corrupt →
            // dropped → the stream yields no terminal → poll fallback.
            $this->sseResponse("event: workflow.completed\ndata: {bad json}\n\n"),
            $this->statusResponse('completed'),   // poll fallback resolves here
            $this->downloadsResponse(),
        ], $captured);

        $client = $this->makeClient($http);
        $path = $this->tempImage();
        try {
            $result = $this->recipe($client, FileInput::path($path))->compress()->run(pollIntervalMs: 0);
        } finally {
            @\unlink($path);
        }

        self::assertSame('completed', $result->state);
        self::assertTrue($result->ok);
        self::assertCount(1, $result->artifacts);
    }

    #[Test]
    public function timeout_throws_when_deadline_elapses_before_terminal(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->createResponse(),
            // SSE ends without terminal → poll fallback with a 0ms budget.
            $this->sseResponse("event: operation.progress\ndata: {\"progress\":1}\n\n"),
            $this->statusResponse('in_progress'),
            $this->statusResponse('in_progress'),
        ], $captured);

        $client = $this->makeClient($http);

        // uploadId arm (no upload) keeps the queue tight; maxWait 1 (1ms) →
        // the deadline elapses almost immediately and a non-terminal status
        // trips the poll fallback's timeout. (maxWait 0 is rejected by
        // MaxWait::parse, which requires a positive budget.)
        try {
            $this->recipe($client, FileInput::uploadId('file_existing'))
                ->compress()
                ->run(maxWait: 1, pollIntervalMs: 0);
            self::fail('expected GislTimeoutError');
        } catch (GislTimeoutError $e) {
            // Expected — the deadline elapsed before a terminal status.
            // oYumKo6y: the workflow was created; the timeout carries its id so
            // the caller can poll it to recover rather than re-run (double charge).
            self::assertSame(self::WORKFLOW_ID, $e->workflowId);
        }

        // A timeout MUST short-circuit before fetching downloads — mirrors the
        // TS `expect(getWorkflowDownloads).not.toHaveBeenCalled()` assertion.
        foreach ($captured as $request) {
            self::assertStringNotContainsString(
                '/downloads',
                (string) $request->getUri(),
                'no /downloads request should be issued when the run times out',
            );
        }
    }

    #[Test]
    public function no_client_guard_throws_config_error_with_reason_no_client(): void
    {
        $bare = new Recipe(FileInput::path('photo.jpg'));
        try {
            $bare->compress()->run();
            self::fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertSame('no_client', $e->getReason());
        }
    }

    #[Test]
    public function resource_input_arm_uploads_seekable_stream(): void
    {
        // VOxtu0RZ-B4: a seekable stream input now uploads end-to-end (it was
        // previously rejected as resource_input_unsupported).
        $stream = \fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        \fwrite($stream, 'image-bytes');
        $http = $this->stubClient([
            $this->uploadResponse(),
            $this->createResponse(),
            $this->sseResponse(self::TERMINAL_SSE),
            $this->statusResponse('completed'),
            $this->downloadsResponse(),
        ]);
        $client = $this->makeClient($http);
        try {
            $result = $this->recipe($client, FileInput::resource($stream))->compress()->run();
            self::assertInstanceOf(RunResult::class, $result);
        } finally {
            \fclose($stream);
        }
    }

    #[Test]
    public function compress_optimize_on_resource_fails_before_upload(): void
    {
        // VOxtu0RZ-B4 (codex): compress(optimize) needs an inferable media
        // class; a stream carries no extension, so lowering throws media_unknown
        // — and it must throw BEFORE the upload, not after spending the bytes.
        $stream = \fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        \fwrite($stream, 'bytes');
        $http = $this->stubClient([]);   // empty — any request means the upload ran
        $client = $this->makeClient($http);
        try {
            $this->recipe($client, FileInput::resource($stream))->compress(OptimizeFor::Size)->run();
            self::fail('expected GislConfigError media_unknown');
        } catch (GislConfigError $e) {
            self::assertSame('media_unknown', $e->getReason());
        } finally {
            \fclose($stream);
        }
    }

    #[Test]
    public function resource_input_arm_rejects_non_seekable_stream(): void
    {
        // A non-seekable stream (a pipe) is rejected with an actionable error
        // (Option B) rather than buffered to disk.
        $pipe = \popen('printf x', 'r');
        self::assertIsResource($pipe);
        $http = $this->stubClient([]);   // empty queue — no request should fire
        $client = $this->makeClient($http);
        try {
            $this->recipe($client, FileInput::resource($pipe))->compress()->run();
            self::fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertSame('non_seekable_stream', $e->getReason());
        } finally {
            \pclose($pipe);
        }
    }

    #[Test]
    public function on_progress_emits_upload_and_processing_events(): void
    {
        $http = $this->stubClient([
            $this->uploadResponse(),
            $this->createResponse(),
            $this->sseResponse(
                "event: operation.progress\ndata: {\"progress\":60}\n\n" . self::TERMINAL_SSE,
            ),
            $this->statusResponse('completed'),
            $this->downloadsResponse(),
        ]);

        $client = $this->makeClient($http);
        $path = $this->tempImage();
        /** @var list<ProgressEvent> $events */
        $events = [];
        try {
            $this->recipe($client, FileInput::path($path))->compress()->run(
                onProgress: function (ProgressEvent $e) use (&$events): void {
                    $events[] = $e;
                },
            );
        } finally {
            @\unlink($path);
        }

        $uploads = \array_filter($events, static fn (ProgressEvent $e): bool => $e instanceof UploadProgressEvent);
        $processing = \array_filter($events, static fn (ProgressEvent $e): bool => $e instanceof ProcessingProgressEvent);
        self::assertNotEmpty($uploads, 'expected an UploadProgressEvent');
        self::assertNotEmpty($processing, 'expected a ProcessingProgressEvent');
        // Single-shot upload reports (fileSize, fileSize) byte counters.
        $upload = \array_values($uploads)[0];
        self::assertInstanceOf(UploadProgressEvent::class, $upload);
        self::assertSame(2048, $upload->uploadedBytes);
        self::assertSame(2048, $upload->totalBytes);
    }

    #[Test]
    public function failed_terminal_partitions_into_failed_with_ok_false(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse("event: workflow.failed\ndata: {\"status\":\"failed\"}\n\n"),
            // getWorkflowStatus after SSE terminal carries the failed status + error.
            $this->statusResponse('failed', [
                ['operations' => [['error_message' => 'codec exploded']]],
            ]),
            $this->downloadsResponse(),
        ], $captured);

        $client = $this->makeClient($http);
        $result = $this->recipe($client, FileInput::uploadId('file_existing'))->compress()->run();

        self::assertSame('failed', $result->state);
        self::assertFalse($result->ok);
        self::assertSame([], $result->succeeded);
        self::assertCount(1, $result->failed);
        self::assertNull($result->failed[0]->key);
        self::assertInstanceOf(GislItemFailedError::class, $result->failed[0]->error);
        // The error message is "{state}: {firstOpErrorMessage}" — pin the
        // content, not just the type, so a misformatted partition is caught.
        self::assertSame('failed: codec exploded', $result->failed[0]->error->getMessage());
        // The typed error exposes the structured failure for branchless handling.
        self::assertSame('failed', $result->failed[0]->error->state);
        self::assertSame('codec exploded', $result->failed[0]->error->errorMessage);
        // No error_code was mocked → it stays null.
        self::assertNull($result->failed[0]->error->errorCode);
    }

    #[Test]
    public function failed_terminal_reads_error_code_and_message_from_same_op(): void
    {
        // The downloads-path failure reads errorMessage AND errorCode from the
        // SAME first failing op (whole-workflow scoped). Both surface on the
        // typed error.
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse("event: workflow.failed\ndata: {\"status\":\"failed\"}\n\n"),
            $this->statusResponse('failed', [
                ['operations' => [['error_message' => 'too large', 'error_code' => 'output_too_large']]],
            ]),
            $this->downloadsResponse(),
        ]);

        $client = $this->makeClient($http);
        $result = $this->recipe($client, FileInput::uploadId('file_existing'))->compress()->run();

        self::assertInstanceOf(GislItemFailedError::class, $result->failed[0]->error);
        self::assertSame('failed', $result->failed[0]->error->state);
        self::assertSame('too large', $result->failed[0]->error->errorMessage);
        self::assertSame('output_too_large', $result->failed[0]->error->errorCode);
        self::assertSame('failed: too large', $result->failed[0]->error->getMessage());
        // toArray() failed[] carries the full key set in fixed order.
        self::assertSame(
            ['key' => null, 'error' => 'failed: too large', 'state' => 'failed', 'errorMessage' => 'too large', 'errorCode' => 'output_too_large'],
            $result->toArray()['failed'][0],
        );
    }

    #[Test]
    public function failed_terminal_keeps_an_empty_string_error_message_with_the_colon(): void
    {
        // An EMPTY-string error_message is NOT null, so the message composition
        // still appends the colon ("failed: ") and toArray() INCLUDES the
        // errorMessage key (present, empty) — the "!== null" rule, not truthiness.
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse("event: workflow.failed\ndata: {\"status\":\"failed\"}\n\n"),
            $this->statusResponse('failed', [
                ['operations' => [['error_message' => '']]],
            ]),
            $this->downloadsResponse(),
        ]);

        $client = $this->makeClient($http);
        $result = $this->recipe($client, FileInput::uploadId('file_existing'))->compress()->run();

        self::assertInstanceOf(GislItemFailedError::class, $result->failed[0]->error);
        self::assertSame('', $result->failed[0]->error->errorMessage);
        self::assertNull($result->failed[0]->error->errorCode);
        self::assertSame('failed: ', $result->failed[0]->error->getMessage());
        // The empty string is PRESENT in toArray() (not omitted); errorCode absent.
        $failedEntry = $result->toArray()['failed'][0];
        self::assertSame(
            ['key' => null, 'error' => 'failed: ', 'state' => 'failed', 'errorMessage' => ''],
            $failedEntry,
        );
        self::assertArrayHasKey('errorMessage', $failedEntry);
        self::assertArrayNotHasKey('errorCode', $failedEntry);
    }

    #[Test]
    public function failed_terminal_reads_code_and_message_from_the_same_op_no_cross_op_pairing(): void
    {
        // Two ops: op A carries ONLY error_code, op B ONLY error_message. The
        // break-2 selects the FIRST op with EITHER field (op A) and reads BOTH
        // fields from THAT op — so errorCode comes from op A and errorMessage
        // stays null; op B's message is NOT paired in.
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse("event: workflow.failed\ndata: {\"status\":\"failed\"}\n\n"),
            $this->statusResponse('failed', [
                ['operations' => [
                    ['error_code' => 'a'],
                    ['error_message' => 'b'],
                ]],
            ]),
            $this->downloadsResponse(),
        ]);

        $client = $this->makeClient($http);
        $result = $this->recipe($client, FileInput::uploadId('file_existing'))->compress()->run();

        self::assertInstanceOf(GislItemFailedError::class, $result->failed[0]->error);
        self::assertSame('a', $result->failed[0]->error->errorCode);
        // op B's message is NOT cross-paired — only op A's fields are read.
        self::assertNull($result->failed[0]->error->errorMessage);
        // No errorMessage → no colon; the message is the bare state.
        self::assertSame('failed', $result->failed[0]->error->getMessage());
        self::assertSame(
            ['key' => null, 'error' => 'failed', 'state' => 'failed', 'errorCode' => 'a'],
            $result->toArray()['failed'][0],
        );
    }

    #[Test]
    public function failed_terminal_picks_first_defined_op_error_message(): void
    {
        // First operation carries no error_message; the partition must walk on
        // to the later op that does — pinning the find/break-2 selection.
        $captured = [];
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse("event: workflow.failed\ndata: {\"status\":\"failed\"}\n\n"),
            $this->statusResponse('failed', [
                ['operations' => [
                    [],
                    ['error_message' => 'later op blew up'],
                ]],
            ]),
            $this->downloadsResponse(),
        ], $captured);

        $client = $this->makeClient($http);
        $result = $this->recipe($client, FileInput::uploadId('file_existing'))->compress()->run();

        self::assertSame('failed', $result->state);
        self::assertSame('failed: later op blew up', $result->failed[0]->error->getMessage());
    }

    #[Test]
    public function partially_failed_terminal_is_treated_as_a_failure(): void
    {
        // A partial failure must NOT be misclassified as success. SSE yields the
        // workflow.partially_failed terminal event, then the status carries the
        // partially_failed state plus a per-op error message.
        $captured = [];
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse("event: workflow.partially_failed\ndata: {\"status\":\"partially_failed\"}\n\n"),
            $this->statusResponse('partially_failed', [
                ['operations' => [['error_message' => 'codec exploded']]],
            ]),
            $this->downloadsResponse(),
        ], $captured);

        $client = $this->makeClient($http);
        $result = $this->recipe($client, FileInput::uploadId('file_existing'))->compress()->run();

        self::assertSame('partially_failed', $result->state);
        self::assertFalse($result->ok);
        self::assertSame([], $result->succeeded);
        self::assertCount(1, $result->failed);
        self::assertNull($result->failed[0]->key);
        self::assertInstanceOf(\Throwable::class, $result->failed[0]->error);
        self::assertSame('partially_failed: codec exploded', $result->failed[0]->error->getMessage());
    }

    #[Test]
    public function multi_file_downloads_flatten_in_order_with_null_url_sugar(): void
    {
        // One job carrying TWO files flattens to 2 artifacts in order; the
        // single-output `url` sugar is null because there is more than one.
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse(self::TERMINAL_SSE),
            $this->statusResponse('completed'),
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'downloads' => [
                        [
                            'job_id' => '01936fb3-0001-7000-8000-0000000060f3',
                            'ref' => 'op',
                            'files' => [
                                [
                                    'operation' => 'convert',
                                    'operation_id' => '01936fb4-0002-7000-8000-0000000060f5',
                                    'filename' => 'page_1.png',
                                    'size_bytes' => 100,
                                    'download_url' => 'https://signed.example.com/page_1.png',
                                ],
                                [
                                    'operation' => 'convert',
                                    'operation_id' => '01936fb4-0002-7000-8000-0000000060f5',
                                    'filename' => 'page_2.png',
                                    'size_bytes' => 110,
                                    'download_url' => 'https://signed.example.com/page_2.png',
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $client = $this->makeClient($http);
        $result = $this->recipe($client, FileInput::uploadId('file_existing'))->convert('png')->run();

        self::assertCount(2, $result->artifacts);
        self::assertSame('https://signed.example.com/page_1.png', $result->artifacts[0]->url);
        self::assertSame('page_1.png', $result->artifacts[0]->filename);
        self::assertSame(100, $result->artifacts[0]->sizeBytes);
        self::assertSame('convert', $result->artifacts[0]->operation);
        self::assertSame('https://signed.example.com/page_2.png', $result->artifacts[1]->url);
        self::assertSame('page_2.png', $result->artifacts[1]->filename);
        self::assertSame(110, $result->artifacts[1]->sizeBytes);
        self::assertSame('convert', $result->artifacts[1]->operation);
        // >1 output → single-output url sugar is null.
        self::assertNull($result->url);
    }

    #[Test]
    public function empty_downloads_yield_zero_artifacts_but_ok_true(): void
    {
        // A completed workflow whose downloads carry no files yields zero
        // artifacts, a null url sugar, and an empty-outputs success entry.
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse(self::TERMINAL_SSE),
            $this->statusResponse('completed'),
            $this->jsonResponse(200, [
                'success' => true,
                'data' => ['downloads' => []],
            ]),
        ]);

        $client = $this->makeClient($http);
        $result = $this->recipe($client, FileInput::uploadId('file_existing'))->compress()->run();

        self::assertSame('completed', $result->state);
        self::assertTrue($result->ok);
        self::assertSame([], $result->artifacts);
        // Zero outputs → single-output url sugar is null.
        self::assertNull($result->url);
        // Keyless completed run → succeeded=[{key:null, outputs:[]}].
        self::assertCount(1, $result->succeeded);
        self::assertSame([], $result->succeeded[0]->outputs);
        self::assertSame([], $result->failed);
    }

    #[Test]
    public function poll_fallback_when_sse_request_throws_at_transport_level(): void
    {
        // The SSE/events request fails at the PSR-18 transport level (a queued
        // GislNetworkError, which implements ClientExceptionInterface). run()
        // must recover by polling getWorkflowStatus to a terminal state.
        $sseError = new GislNetworkError('events transport down');
        $http = $this->stubClient([
            $this->createResponse(),
            $sseError,                              // streamEvents() request throws
            $this->statusResponse('completed'),     // poll fallback → terminal
            $this->downloadsResponse(),
        ]);

        $client = $this->makeClient($http);
        $result = $this->recipe($client, FileInput::uploadId('file_existing'))
            ->compress()
            ->run(pollIntervalMs: 0);

        self::assertSame('completed', $result->state);
        self::assertTrue($result->ok);
        self::assertCount(1, $result->artifacts);
    }

    #[Test]
    public function downloader_is_bound_so_to_file_does_not_throw_downloader_unavailable(): void
    {
        // run() binds a StreamingDownloader. Pointing the single output at a
        // local file:// URL lets toFile() copy it without a network call — the
        // copy succeeding proves a downloader was bound (the unbound path
        // throws GislSinkError reason 'downloader_unavailable').
        $sourcePath = \tempnam(\sys_get_temp_dir(), 'gisl_src_');
        self::assertIsString($sourcePath);
        \file_put_contents($sourcePath, 'OUTPUT-BYTES');

        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse(self::TERMINAL_SSE),
            $this->statusResponse('completed'),
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'downloads' => [
                        [
                            'job_id' => '01936fb3-0001-7000-8000-0000000060f3',
                            'ref' => 'op',
                            'files' => [
                                [
                                    'operation' => 'compress',
                                    'operation_id' => '01936fb4-0001-7000-8000-0000000060f4',
                                    'filename' => 'out.jpg',
                                    'size_bytes' => 12,
                                    'download_url' => 'file://' . $sourcePath,
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $client = $this->makeClient($http);
        $result = $this->recipe($client, FileInput::uploadId('file_existing'))->compress()->run();

        $dest = \tempnam(\sys_get_temp_dir(), 'gisl_dst_');
        self::assertIsString($dest);
        try {
            // Does NOT throw downloader_unavailable — a downloader was bound.
            $result->toFile($dest);
            self::assertSame('OUTPUT-BYTES', \file_get_contents($dest));
        } finally {
            @\unlink($sourcePath);
            @\unlink($dest);
        }
    }

    #[Test]
    public function key_threads_into_the_result_so_by_key_resolves(): void
    {
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse(self::TERMINAL_SSE),
            $this->statusResponse('completed'),
            $this->downloadsResponse(),
        ]);

        $client = $this->makeClient($http);
        $result = $this->recipe($client, FileInput::uploadId('file_existing'), 'hero')->compress()->run();

        self::assertSame('hero', $result->succeeded[0]->key);
        $item = $result->byKey('hero');
        self::assertSame('hero', $item->key);
        self::assertSame($result->artifacts, $item->outputs);
    }

    // ----------------------------------------------------------------------
    // FF5b — Recipe::submit(): fire-and-forget upload + create, returning a
    // client-bound Handle that carries the recipe key, WITHOUT waiting.
    // ----------------------------------------------------------------------

    #[Test]
    public function submit_uploads_and_creates_returns_a_handle_and_does_not_wait(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->uploadResponse(),
            $this->createResponseWithWebhookSecret(),
            // NO sse / status / downloads queued — submit() must NOT consult
            // any of them. Reaching for one would exhaust the queue and throw.
        ], $captured);

        $client = $this->makeClient($http);
        $path = $this->tempImage();
        try {
            $handle = $this->recipe($client, FileInput::path($path))->compress()->submit();
        } finally {
            @\unlink($path);
        }

        self::assertInstanceOf(Handle::class, $handle);
        self::assertSame(self::WORKFLOW_ID, $handle->workflowId);
        self::assertSame(
            'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789',
            $handle->webhookSecret,
        );
        // Exactly two outbound requests: upload + workflow create. No wait,
        // no status poll, no downloads fetch.
        self::assertCount(2, $captured);
        self::assertStringContainsString('/api/uploads', (string) $captured[0]->getUri());
        self::assertStringContainsString('/api/workflows', (string) $captured[1]->getUri());
        foreach ($captured as $request) {
            self::assertStringNotContainsString('/events', (string) $request->getUri());
            self::assertStringNotContainsString('/downloads', (string) $request->getUri());
            self::assertStringNotContainsString('/status', (string) $request->getUri());
        }
    }

    #[Test]
    public function submit_with_webhook_wires_callback_url_onto_the_create_payload(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->createResponseWithWebhookSecret(),
        ], $captured);

        $client = $this->makeClient($http);
        $this->recipe($client, FileInput::uploadId('file_existing'))
            ->compress()
            ->submit('https://example.com/cb');

        self::assertCount(1, $captured);
        self::assertStringContainsString('/api/workflows', (string) $captured[0]->getUri());
        $body = \json_decode((string) $captured[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('https://example.com/cb', $body['callback_url']);
    }

    #[Test]
    public function submit_without_webhook_omits_callback_url_from_the_create_payload(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->createResponseWithWebhookSecret(),
        ], $captured);

        $client = $this->makeClient($http);
        $this->recipe($client, FileInput::uploadId('file_existing'))
            ->compress()
            ->submit();

        self::assertCount(1, $captured);
        $body = \json_decode((string) $captured[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertArrayNotHasKey('callback_url', $body);
    }

    #[Test]
    public function submit_upload_id_arm_makes_no_upload_call_and_uses_the_id_verbatim(): void
    {
        $captured = [];
        $http = $this->stubClient([
            // NO upload response queued — submit() with a pre-uploaded id must
            // skip the upload entirely and reference the id verbatim.
            $this->createResponseWithWebhookSecret(),
        ], $captured);

        $client = $this->makeClient($http);
        $handle = $this->recipe($client, FileInput::uploadId('file_x'))
            ->convert('webp')
            ->submit();

        self::assertSame(self::WORKFLOW_ID, $handle->workflowId);
        // No upload happened → the only request is the workflow create.
        self::assertCount(1, $captured);
        self::assertStringContainsString('/api/workflows', (string) $captured[0]->getUri());
        $body = \json_decode((string) $captured[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('upload', $body['jobs'][0]['source']['type']);
        self::assertSame('file_x', $body['jobs'][0]['source']['file_id']);
    }

    #[Test]
    public function submit_no_client_guard_throws_config_error_with_reason_no_client(): void
    {
        $bare = new Recipe(FileInput::path('photo.jpg'));
        try {
            $bare->compress()->submit();
            self::fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertSame('no_client', $e->getReason());
        }
    }

    #[Test]
    public function submitted_handle_is_keyed_so_its_result_is_addressable_by_key(): void
    {
        $captured = [];
        $http = $this->stubClient([
            // submit(): create (no upload — id arm).
            $this->createResponseWithWebhookSecret(),
            // result(): one status fetch (terminal) + downloads.
            $this->statusResponse('completed'),
            $this->downloadsResponse(),
        ], $captured);

        $client = $this->makeClient($http);
        $handle = $this->recipe($client, FileInput::uploadId('file_x'), 'hero')
            ->compress()
            ->submit();

        $result = $handle->result();

        self::assertSame('hero', $result->succeeded[0]->key);
        $item = $result->byKey('hero');
        self::assertSame('hero', $item->key);
        self::assertSame($result->artifacts, $item->outputs);
    }

    #[Test]
    public function reattached_handle_has_no_key_so_its_result_is_keyless_and_by_key_throws(): void
    {
        // Contrast to the submit-KEYED case: a Handle built with no recipe key
        // (the client->workflow(id) reattach surface) projects a keyless
        // RunResult, and byKey() then throws.
        $http = $this->stubClient([
            $this->statusResponse('completed'),
            $this->downloadsResponse(),
        ]);

        $client = $this->makeClient($http);
        $reattached = new Handle(
            workflowId: self::WORKFLOW_ID,
            client: $client,
        );

        $result = $reattached->result();

        self::assertNull($result->succeeded[0]->key);
        $this->expectException(GislNoSuchKeyError::class);
        $result->byKey('hero');
    }

    #[Test]
    public function keyed_handle_to_array_stays_byte_identical_and_never_serialises_the_key(): void
    {
        // Back-compat pin: a Handle carrying the 4th (key) arg must still
        // serialise to exactly {workflowId, webhookSecret} — the key is never
        // emitted, so the operation-first/merge submit() shape can't drift.
        $keyed = new Handle(
            workflowId: self::WORKFLOW_ID,
            webhookSecret: 'whsec_abc',
            key: 'hero',
        );

        self::assertSame(
            ['workflowId' => self::WORKFLOW_ID, 'webhookSecret' => 'whsec_abc'],
            $keyed->toArray(),
        );
        self::assertArrayNotHasKey('key', $keyed->toArray());

        // And with no webhookSecret → exactly {workflowId}.
        $keyedNoSecret = new Handle(workflowId: self::WORKFLOW_ID, key: 'hero');
        self::assertSame(['workflowId' => self::WORKFLOW_ID], $keyedNoSecret->toArray());
        self::assertArrayNotHasKey('key', $keyedNoSecret->toArray());
    }
}
