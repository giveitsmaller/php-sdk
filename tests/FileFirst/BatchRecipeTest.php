<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\FileFirst;

use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislItemFailedError;
use Gisl\Sdk\FileFirst\BatchRecipe;
use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\Recipe;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * FF7 (MFaCjL8d) — the keyed multi-recipe {@see BatchRecipe}: N DISTINCT
 * single-input keyed recipes lowered into ONE workflow (`b{i}` job ids), run
 * end-to-end over a stubbed PSR-18 client, then partitioned into a
 * {@see \Gisl\Sdk\FileFirst\RunResult} addressable by the caller key given at
 * `$client->file($path, $key)` time. Mirrors the TS `file-first-batch.test.ts`.
 */
final class BatchRecipeTest extends TestCase
{
    private const WORKFLOW_ID = '01936fb2-0000-7000-8000-0000000000b0';

    /**
     * The cross-language GOLDEN lowered payload — BOTH the PHP and TS suites
     * assert their batch lowers a fixed 2-entry keyed-thumbnail batch to EXACTLY
     * this JSON. Any drift (job order, `b0`/`b1` ids, per-job key order, source
     * shape, or an unexpected callback_url) breaks the pin in one language only.
     */
    private const GOLDEN_JSON =
        '{"jobs":[{"id":"b0","source":{"type":"upload","file_id":"id0"},"operations":[{"type":"thumbnail","options":{"width":1200,"height":630}}]},'
        . '{"id":"b1","source":{"type":"upload","file_id":"id1"},"operations":[{"type":"thumbnail","options":{"width":256,"height":256}}]}]}';

    private HttpFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    // -----------------------------------------------------------------------
    // Happy path — two keyed recipes → one workflow → keyed partition.
    // -----------------------------------------------------------------------

    #[Test]
    public function happy_path_runs_two_keyed_recipes_and_addresses_each_output_by_key(): void
    {
        $captured = [];
        // uploadId arm → NO upload; create + sse + status + downloads = 4.
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse("event: workflow.completed\ndata: {\"status\":\"completed\"}\n\n"),
            $this->multiJobStatusResponse('completed', [
                ['ref' => 'b0', 'status' => 'completed'],
                ['ref' => 'b1', 'status' => 'completed'],
            ]),
            $this->multiJobDownloadsResponse([
                ['ref' => 'b0', 'filename' => 'hero.png', 'size' => 10, 'url' => 'https://cdn/hero.png'],
                ['ref' => 'b1', 'filename' => 'avatar.png', 'size' => 20, 'url' => 'https://cdn/avatar.png'],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $hero = $client->file(FileInput::uploadId('id0'), 'hero')->thumbnail(['width' => 1200, 'height' => 630]);
        $avatar = $client->file(FileInput::uploadId('id1'), 'avatar')->thumbnail(['width' => 256, 'height' => 256]);

        $result = $client->batch([$hero, $avatar])->run();

        self::assertTrue($result->ok);
        self::assertSame([], $result->failed);
        // Each entry is addressed by the caller key (NOT a positional index).
        self::assertSame('hero', $result->byKey('hero')->key);
        self::assertSame('https://cdn/hero.png', $result->byKey('hero')->outputs[0]->url);
        self::assertSame('avatar', $result->byKey('avatar')->key);
        self::assertSame('https://cdn/avatar.png', $result->byKey('avatar')->outputs[0]->url);
        self::assertSame(['hero', 'avatar'], array_map(static fn ($s) => $s->key, $result->succeeded));

        // No upload happened → first captured request is the workflow create,
        // and its lowered body carries the `b{i}` job ids in entry order.
        self::assertStringContainsString('/api/workflows', (string) $captured[0]->getUri());
        $body = \json_decode((string) $captured[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(['b0', 'b1'], array_column($body['jobs'], 'id'));
    }

    #[Test]
    public function actual_upload_threads_uploaded_ids_into_the_create_payload_in_entry_order(): void
    {
        // The uploadId happy path never exercises the upload→create fileId
        // threading seam. With PATH inputs the batch uploads each entry (in
        // order); the create payload's per-job source.file_id must then carry the
        // uploaded ids positionally: b0 ← first upload, b1 ← second.
        $a = $this->tempFile('jpg');
        $b = $this->tempFile('jpg');
        $captured = [];
        // upload(a) + upload(b) + create + sse + status + downloads = 6.
        $http = $this->stubClient([
            $this->uploadResponse('01936fb1-7bb3-7000-8000-0000000060c1'),
            $this->uploadResponse('01936fb1-7bb3-7000-8000-0000000060c2'),
            $this->createResponse(),
            $this->sseResponse("event: workflow.completed\ndata: {\"status\":\"completed\"}\n\n"),
            $this->multiJobStatusResponse('completed', [
                ['ref' => 'b0', 'status' => 'completed'],
                ['ref' => 'b1', 'status' => 'completed'],
            ]),
            $this->multiJobDownloadsResponse([
                ['ref' => 'b0', 'filename' => 'hero.png', 'size' => 10, 'url' => 'https://cdn/hero.png'],
                ['ref' => 'b1', 'filename' => 'avatar.png', 'size' => 20, 'url' => 'https://cdn/avatar.png'],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        try {
            $hero = $client->file(FileInput::path($a), 'hero')->thumbnail(['width' => 1200, 'height' => 630]);
            $avatar = $client->file(FileInput::path($b), 'avatar')->thumbnail(['width' => 256, 'height' => 256]);
            $result = $client->batch([$hero, $avatar])->run();
        } finally {
            @\unlink($a);
            @\unlink($b);
        }

        self::assertTrue($result->ok);

        // Each entry's input uploaded 1:1 (no dedupe in v1) — two upload requests.
        $uploadRequests = \array_values(\array_filter(
            $captured,
            static fn (RequestInterface $r): bool => \str_contains((string) $r->getUri(), '/api/uploads'),
        ));
        self::assertCount(2, $uploadRequests);

        // The create body threads the uploaded ids in ENTRY ORDER.
        $createRequest = null;
        foreach ($captured as $request) {
            if (\str_contains((string) $request->getUri(), '/api/workflows')) {
                $createRequest = $request;
                break;
            }
        }
        self::assertNotNull($createRequest);
        $body = \json_decode((string) $createRequest->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(['b0', 'b1'], array_column($body['jobs'], 'id'));
        self::assertSame(
            ['01936fb1-7bb3-7000-8000-0000000060c1', '01936fb1-7bb3-7000-8000-0000000060c2'],
            array_map(static fn (array $job): string => $job['source']['file_id'], $body['jobs']),
        );
    }

    // -----------------------------------------------------------------------
    // Mixed success / failure — one entry fails, the other still resolves.
    // -----------------------------------------------------------------------

    #[Test]
    public function mixed_success_and_failure_partitions_by_key_with_ok_false(): void
    {
        // The avatar (b1) job fails; the hero (b0) job completes → partially_failed.
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse("event: workflow.partially_failed\ndata: {\"status\":\"partially_failed\"}\n\n"),
            $this->multiJobStatusResponse('partially_failed', [
                ['ref' => 'b0', 'status' => 'completed'],
                ['ref' => 'b1', 'status' => 'failed', 'error' => 'codec exploded'],
            ]),
            $this->multiJobDownloadsResponse([
                ['ref' => 'b0', 'filename' => 'hero.png', 'size' => 10, 'url' => 'https://cdn/hero.png'],
            ]),
        ]);

        $client = $this->makeClient($http);
        $hero = $client->file(FileInput::uploadId('id0'), 'hero')->thumbnail(['width' => 1200, 'height' => 630]);
        $avatar = $client->file(FileInput::uploadId('id1'), 'avatar')->thumbnail(['width' => 256, 'height' => 256]);

        $result = $client->batch([$hero, $avatar])->run();

        self::assertSame('partially_failed', $result->state);
        self::assertFalse($result->ok);
        // hero succeeded, avatar failed — one failure does not sink the rest.
        self::assertSame(['hero'], array_map(static fn ($s) => $s->key, $result->succeeded));
        self::assertSame('https://cdn/hero.png', $result->byKey('hero')->outputs[0]->url);
        self::assertSame(['avatar'], array_map(static fn ($f) => $f->key, $result->failed));
        self::assertInstanceOf(GislItemFailedError::class, $result->failed[0]->error);
        self::assertSame('failed: codec exploded', $result->failed[0]->error->getMessage());
        self::assertSame('avatar', $result->failed[0]->error->key);
        self::assertSame('failed', $result->failed[0]->error->state);
        self::assertSame('codec exploded', $result->failed[0]->error->errorMessage);
    }

    // -----------------------------------------------------------------------
    // Validation guards — each fires from run(), throwing the right reason.
    // -----------------------------------------------------------------------

    #[Test]
    public function empty_batch_throws_config_error_reason_no_recipes(): void
    {
        // $client->batch([]) returns a BatchRecipe; the guard fires from run().
        // The empty stub queue would throw on ANY request, so reaching a
        // GislConfigError proves validation short-circuited before I/O.
        $client = $this->makeClient($this->stubClient([]));
        try {
            $client->batch([])->run();
            self::fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertSame('no_recipes', $e->getReason());
        }
    }

    #[Test]
    public function multi_input_recipe_entry_throws_config_error_reason_multi_input_recipe_unsupported(): void
    {
        // A FilesRecipe (a multi-input builder) is REJECTED — checked BEFORE the
        // not-a-Recipe catch-all (it does not extend Recipe, so the catch-all
        // would otherwise misreport it as a generic type error).
        $client = $this->makeClient($this->stubClient([]));
        $files = $client->files([FileInput::uploadId('id0')])->compress();
        try {
            $client->batch([$files])->run();
            self::fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertSame('multi_input_recipe_unsupported', $e->getReason());
        }
    }

    #[Test]
    public function non_recipe_entry_throws_config_error_reason_invalid_recipe(): void
    {
        $client = $this->makeClient($this->stubClient([]));
        try {
            $client->batch(['not a recipe'])->run();
            self::fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertSame('invalid_recipe', $e->getReason());
        }
    }

    #[Test]
    public function keyless_entry_throws_config_error_reason_missing_key(): void
    {
        // Built via $client->file($input) with NO key — every batch entry needs one.
        $client = $this->makeClient($this->stubClient([]));
        $noKey = $client->file(FileInput::uploadId('id0'))->thumbnail(['width' => 1200, 'height' => 630]);
        try {
            $client->batch([$noKey])->run();
            self::fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertSame('missing_key', $e->getReason());
        }
    }

    #[Test]
    public function duplicate_key_throws_config_error_reason_duplicate_key(): void
    {
        $client = $this->makeClient($this->stubClient([]));
        $a = $client->file(FileInput::uploadId('id0'), 'dup')->thumbnail(['width' => 1200, 'height' => 630]);
        $b = $client->file(FileInput::uploadId('id1'), 'dup')->thumbnail(['width' => 256, 'height' => 256]);
        try {
            $client->batch([$a, $b])->run();
            self::fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertSame('duplicate_key', $e->getReason());
        }
    }

    // -----------------------------------------------------------------------
    // Validation runs BEFORE any upload — a later invalid entry never leaves
    // an earlier input uploaded.
    // -----------------------------------------------------------------------

    #[Test]
    public function validation_failure_uploads_nothing(): void
    {
        // Path inputs WOULD upload — but the duplicate-key guard fires first, so
        // NO request is ever issued. A capturing stub proves the sequence is empty.
        $a = $this->tempFile('jpg');
        $b = $this->tempFile('jpg');
        $captured = [];
        $http = $this->stubClient([], $captured);
        $client = $this->makeClient($http);
        try {
            $client->batch([
                $client->file(FileInput::path($a), 'dup')->thumbnail(['width' => 1200, 'height' => 630]),
                $client->file(FileInput::path($b), 'dup')->thumbnail(['width' => 256, 'height' => 256]),
            ])->run();
            self::fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertSame('duplicate_key', $e->getReason());
        } finally {
            @\unlink($a);
            @\unlink($b);
        }

        // No request was captured — validation short-circuited before any upload.
        self::assertCount(0, $captured);
    }

    #[Test]
    public function later_entry_missing_path_fails_preflight_before_any_upload(): void
    {
        // DISTINCT keys, so the STRUCTURAL guards all pass — this exercises the
        // FULL pre-upload preflight (path readability), which the duplicate-key
        // test does not reach. Entry 'a' is a valid existing path that WOULD
        // upload; entry 'b' is a NON-EXISTENT path. The preflight's fromPath()
        // check throws BEFORE any upload, so the valid input 'a' is never sent.
        $valid = $this->tempFile('jpg');
        $captured = [];
        $http = $this->stubClient([], $captured);
        $client = $this->makeClient($http);
        try {
            $client->batch([
                $client->file(FileInput::path($valid), 'a')->thumbnail(['width' => 1200, 'height' => 630]),
                $client->file(FileInput::path('/nonexistent/missing.bin'), 'b')->thumbnail(['width' => 256, 'height' => 256]),
            ])->run();
            self::fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertStringContainsString('File not found', $e->getMessage());
        } finally {
            @\unlink($valid);
        }

        // The earlier valid input 'a' was NEVER uploaded — preflight ran first.
        self::assertCount(0, $captured);
    }

    #[Test]
    public function later_entry_unlowerable_op_chain_fails_preflight_before_any_upload(): void
    {
        // The lowering-preflight branch of validateAndPreflight: entry 'a' lowers
        // fine; entry 'b' is compress(optimize) on a bare upload id, which has no
        // inferable media class → media_unknown at lowering. The preflight lowers
        // every entry (with a placeholder id) BEFORE any upload, so the failure
        // surfaces without a single request.
        $captured = [];
        $http = $this->stubClient([], $captured);
        $client = $this->makeClient($http);
        try {
            $client->batch([
                $client->file(FileInput::uploadId('id0'), 'a')->thumbnail(['width' => 1200, 'height' => 630]),
                $client->file(FileInput::uploadId('id1'), 'b')->compress(OptimizeFor::Size),
            ])->run();
            self::fail('expected GislConfigError media_unknown');
        } catch (GislConfigError $e) {
            self::assertSame('media_unknown', $e->getReason());
        }

        self::assertCount(0, $captured);
    }

    // -----------------------------------------------------------------------
    // no-client guard — a directly-constructed BatchRecipe throws from run().
    // -----------------------------------------------------------------------

    #[Test]
    public function run_no_client_guard_throws_config_error_reason_no_client(): void
    {
        // A directly-constructed BatchRecipe has no client; run() throws no_client
        // BEFORE validation (so this fires even for an otherwise-valid batch).
        $bare = new BatchRecipe([
            (new Recipe(FileInput::uploadId('id0'), 'hero'))->thumbnail(['width' => 1200, 'height' => 630]),
        ]);
        try {
            $bare->run();
            self::fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertSame('no_client', $e->getReason());
        }
    }

    // -----------------------------------------------------------------------
    // useSSE opt-out (wf133EDR) — poll-direct, no SSE stream.
    // -----------------------------------------------------------------------

    #[Test]
    public function use_sse_false_polls_directly_and_never_opens_the_sse_stream(): void
    {
        // The queue carries NO sse response; the default SSE-first path is
        // exercised by the happy-path test (which queues + consumes an sse).
        $captured = [];
        $http = $this->stubClient([
            $this->createResponse(),
            $this->multiJobStatusResponse('completed', [
                ['ref' => 'b0', 'status' => 'completed'],
                ['ref' => 'b1', 'status' => 'completed'],
            ]),
            $this->multiJobDownloadsResponse([
                ['ref' => 'b0', 'filename' => 'hero.png', 'size' => 10, 'url' => 'https://cdn/hero.png'],
                ['ref' => 'b1', 'filename' => 'avatar.png', 'size' => 20, 'url' => 'https://cdn/avatar.png'],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $hero = $client->file(FileInput::uploadId('id0'), 'hero')->thumbnail(['width' => 1200, 'height' => 630]);
        $avatar = $client->file(FileInput::uploadId('id1'), 'avatar')->thumbnail(['width' => 256, 'height' => 256]);

        $result = $client->batch([$hero, $avatar])->run(useSSE: false, pollIntervalMs: 0);

        self::assertTrue($result->ok);
        self::assertSame(['hero', 'avatar'], array_map(static fn ($s) => $s->key, $result->succeeded));

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

    // -----------------------------------------------------------------------
    // toWorkflowPayload — the cross-language GOLDEN lowering pin. Pure lowering
    // on a CLIENT-LESS instance (no validation / preflight runs here).
    // -----------------------------------------------------------------------

    #[Test]
    public function golden_lowered_payload_matches_the_cross_language_shape(): void
    {
        $batch = new BatchRecipe([
            (new Recipe(FileInput::path('hero.jpg'), 'hero'))->thumbnail(['width' => 1200, 'height' => 630]),
            (new Recipe(FileInput::path('avatar.jpg'), 'avatar'))->thumbnail(['width' => 256, 'height' => 256]),
        ]);

        $wire = $batch->toWorkflowPayload(['id0', 'id1'])->toWire();

        self::assertSame([
            'jobs' => [
                [
                    'id' => 'b0',
                    'source' => ['type' => 'upload', 'file_id' => 'id0'],
                    'operations' => [['type' => 'thumbnail', 'options' => ['width' => 1200, 'height' => 630]]],
                ],
                [
                    'id' => 'b1',
                    'source' => ['type' => 'upload', 'file_id' => 'id1'],
                    'operations' => [['type' => 'thumbnail', 'options' => ['width' => 256, 'height' => 256]]],
                ],
            ],
        ], $wire);

        // Byte-identical JSON to the TS golden (job/key order is load-bearing).
        self::assertSame(self::GOLDEN_JSON, \json_encode($wire, JSON_THROW_ON_ERROR));
        // No webhook → callback_url omitted entirely.
        self::assertArrayNotHasKey('callback_url', $wire);
    }

    #[Test]
    public function to_workflow_payload_builds_callback_url_when_a_webhook_is_supplied(): void
    {
        $batch = new BatchRecipe([
            (new Recipe(FileInput::uploadId('u0'), 'hero'))->thumbnail(['width' => 1200, 'height' => 630]),
            (new Recipe(FileInput::uploadId('u1'), 'avatar'))->thumbnail(['width' => 256, 'height' => 256]),
        ]);

        $wire = $batch->toWorkflowPayload(['id0', 'id1'], 'https://webhook.test/x')->toWire();

        self::assertSame('https://webhook.test/x', $wire['callback_url']);
        // Job order + ids are unchanged by the webhook.
        self::assertSame(['b0', 'b1'], array_column($wire['jobs'], 'id'));
    }

    #[Test]
    public function recipe_count_reports_the_number_of_entries(): void
    {
        $batch = new BatchRecipe([
            (new Recipe(FileInput::uploadId('u0'), 'hero'))->thumbnail(['width' => 1200, 'height' => 630]),
            (new Recipe(FileInput::uploadId('u1'), 'avatar'))->thumbnail(['width' => 256, 'height' => 256]),
        ]);
        self::assertSame(2, $batch->recipeCount());
        self::assertSame(0, (new BatchRecipe([]))->recipeCount());
    }

    // -----------------------------------------------------------------------
    // Stub plumbing — mirrors FilesRecipeTest / RecipeRunTest.
    // -----------------------------------------------------------------------

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

    private function makeClient(ClientInterface $http): GislErgonomicClient
    {
        // batch() lives on GislErgonomicClient (the subclass) — the run tests
        // drive the batch through it, exactly like files() in FilesRecipeTest.
        return new GislErgonomicClient(
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

    private function createResponse(): ResponseInterface
    {
        return $this->jsonResponse(201, [
            'success' => true,
            'data' => ['workflow_id' => self::WORKFLOW_ID, 'status' => 'pending'],
        ]);
    }

    private function uploadResponse(string $fileId): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['file_id' => $fileId, 'content_type' => 'image/jpeg', 'size_bytes' => 2048],
        ]);
    }

    private function sseResponse(string $sse): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/event-stream'], $sse);
    }

    /**
     * @param list<array{ref: string, status: string, error?: string, error_code?: string}> $jobs
     */
    private function multiJobStatusResponse(string $status, array $jobs): ResponseInterface
    {
        $jobsWire = [];
        foreach ($jobs as $i => $job) {
            $ops = [];
            if (isset($job['error']) || isset($job['error_code'])) {
                $op = [];
                if (isset($job['error'])) {
                    $op['error_message'] = $job['error'];
                }
                if (isset($job['error_code'])) {
                    $op['error_code'] = $job['error_code'];
                }
                $ops[] = $op;
            }
            $jobsWire[] = [
                'job_id' => \sprintf('01936fb2-00%02d-7000-8000-0000000000%02d', $i + 2, $i + 2),
                'ref' => $job['ref'],
                'status' => $job['status'],
                'operations' => $ops,
            ];
        }
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['workflow_id' => self::WORKFLOW_ID, 'status' => $status, 'jobs' => $jobsWire],
        ]);
    }

    /**
     * @param list<array{ref: string, filename: string, size: int, url: string}> $entries
     */
    private function multiJobDownloadsResponse(array $entries): ResponseInterface
    {
        $downloads = [];
        foreach ($entries as $i => $entry) {
            $downloads[] = [
                'job_id' => \sprintf('01936fb2-00%02d-7000-8000-0000000000%02d', $i + 2, $i + 2),
                'ref' => $entry['ref'],
                'files' => [
                    [
                        'operation' => 'thumbnail',
                        'operation_id' => \sprintf('01936fb2-01%02d-7000-8000-0000000001%02d', $i, $i),
                        'filename' => $entry['filename'],
                        'size_bytes' => $entry['size'],
                        'download_url' => $entry['url'],
                    ],
                ],
            ];
        }
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['downloads' => $downloads],
        ]);
    }

    private function tempFile(string $ext): string
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'gisl_batch_');
        self::assertNotFalse($tmp);
        $path = $tmp . '.' . $ext;
        \rename($tmp, $path);
        \file_put_contents($path, \str_repeat('x', 64));
        return $path;
    }
}
