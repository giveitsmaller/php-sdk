<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\FileFirst;

use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislItemFailedError;
use Gisl\Sdk\Errors\GislNoSuchKeyError;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\FilesRecipe;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Ergonomic\PresetResolver;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use Gisl\Sdk\Tests\Unit\Ergonomic\GislErgonomicClientFactoryTestHelper;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * FF3a (u0hBt6fl) — the file-first {@see FilesRecipe} homogeneous fan-out:
 * clone-on-write immutability, multi-job lowering (one job per file, the
 * `file-{i}` id scheme, per-file media-hint), and the partitioned
 * {@see \Gisl\Sdk\FileFirst\RunResult} (string-index keys, one-failure-doesn't-
 * sink, resource-input unsupported). Mirrors the TS `file-first-files.test.ts`.
 */
final class FilesRecipeTest extends TestCase
{
    private const WORKFLOW_ID = '01936fb2-0000-7000-8000-000000000000';

    private HttpFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    /**
     * @param list<string> $paths
     */
    private function filesRecipe(array $paths): FilesRecipe
    {
        $inputs = array_map(static fn (string $p): FileInput => FileInput::path($p), $paths);
        return new FilesRecipe($inputs);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jobs(FilesRecipe $recipe, array $fileIds): array
    {
        /** @var list<array<string, mixed>> $jobs */
        $jobs = $recipe->toWorkflowPayload($fileIds)->toWire()['jobs'];
        return $jobs;
    }

    // --- Immutability (clone-on-write) --------------------------------------

    #[Test]
    public function chaining_an_op_returns_a_new_files_recipe_and_does_not_mutate_the_original(): void
    {
        $base = $this->filesRecipe(['a.jpg', 'b.jpg']);
        $compressed = $base->compress(OptimizeFor::Balanced);

        self::assertSame(0, $base->stepCount(), 'the base fan-out is untouched');
        self::assertSame(1, $compressed->stepCount());
        self::assertNotSame($base, $compressed, 'each op returns a fresh FilesRecipe');
        self::assertSame(2, $compressed->inputCount(), 'the input list is preserved across the clone');
        self::assertSame(2, $base->inputCount());
    }

    #[Test]
    public function two_branches_off_one_base_are_independent(): void
    {
        $base = $this->filesRecipe(['a.mov', 'b.mov']);
        $branchA = $base->convert('mp4')->compress(OptimizeFor::Size);
        $branchB = $base->thumbnail(['width' => 320, 'height' => 240]);

        self::assertSame(0, $base->stepCount());
        self::assertSame(2, $branchA->stepCount());
        self::assertSame(1, $branchB->stepCount());

        // Re-lower the base AFTER both branches are built — its jobs must still
        // carry ZERO operations (catches a shared-array mutation stepCount misses).
        foreach ($this->jobs($base, ['f0', 'f1']) as $job) {
            self::assertSame([], $job['operations'], 'the base fan-out still lowers to zero operations per job');
        }
        $branchAJob = $this->jobs($branchA, ['f0', 'f1'])[0];
        self::assertSame(['convert', 'compress'], array_column($branchAJob['operations'], 'type'));
    }

    // --- toWorkflowPayload — one job per file, id scheme, shared chain ------

    #[Test]
    public function lowering_emits_one_job_per_input_with_id_scheme_and_shared_chain(): void
    {
        $jobs = $this->jobs(
            $this->filesRecipe(['a.jpg', 'b.jpg', 'c.jpg'])->convert('webp'),
            ['file_0', 'file_1', 'file_2'],
        );

        self::assertCount(3, $jobs);
        self::assertSame(['file-0', 'file-1', 'file-2'], array_column($jobs, 'id'));
        foreach ($jobs as $i => $job) {
            self::assertSame(['type' => 'upload', 'file_id' => "file_{$i}"], $job['source']);
            // Every input gets the SAME lowered operations[].
            self::assertSame([['type' => 'convert', 'options' => ['output_format' => 'webp']]], $job['operations']);
            // Wire key order (id, source, operations) for cross-language JSON parity.
            self::assertSame(['id', 'source', 'operations'], array_keys($job));
        }
    }

    #[Test]
    public function compress_resolves_the_preset_per_file_so_different_media_diverge(): void
    {
        // a.jpg → image preset cell; clip.mov → video preset cell. The fan-out
        // must compose Recipe per input so each picks its own media-hint.
        $jobs = $this->jobs(
            $this->filesRecipe(['a.jpg', 'clip.mov'])->compress(OptimizeFor::Balanced),
            ['file_0', 'file_1'],
        );

        $imageExpected = PresetResolver::resolveCompress(
            media: 'image',
            presetDefaults: null,
            scopedDefaults: null,
            presetOverrides: null,
            optimize: OptimizeFor::Balanced,
            explicitOptions: [],
        )['wireOptions'];
        $videoExpected = PresetResolver::resolveCompress(
            media: 'video',
            presetDefaults: null,
            scopedDefaults: null,
            presetOverrides: null,
            optimize: OptimizeFor::Balanced,
            explicitOptions: [],
        )['wireOptions'];

        self::assertSame(['type' => 'compress', 'options' => $imageExpected], $jobs[0]['operations'][0]);
        self::assertSame(['type' => 'compress', 'options' => $videoExpected], $jobs[1]['operations'][0]);
        self::assertNotEquals(
            $jobs[0]['operations'][0]['options'],
            $jobs[1]['operations'][0]['options'],
            'image and video preset cells are distinct',
        );
    }

    #[Test]
    public function compress_rejects_an_unknown_optimize_string(): void
    {
        $this->expectException(GislConfigError::class);
        $this->filesRecipe(['a.jpg'])->compress('Smallest');
    }

    #[Test]
    public function single_input_fan_out_serialises_to_a_stable_json_string(): void
    {
        $json = \json_encode(
            $this->filesRecipe(['a.jpg'])->convert('webp')->toWorkflowPayload(['file_0'])->toWire(),
        );
        self::assertSame(
            '{"jobs":[{"id":"file-0","source":{"type":"upload","file_id":"file_0"},"operations":[{"type":"convert","options":{"output_format":"webp"}}]}]}',
            $json,
        );
    }

    // --- submit() (uUnCtVAr) -------------------------------------------------

    #[Test]
    public function submit_requires_a_client(): void
    {
        // A directly-constructed FilesRecipe (no bound client) must throw the
        // same no_client guard as run().
        try {
            $this->filesRecipe(['a.jpg'])->compress()->submit('https://webhook.test/x');
            self::fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertSame('no_client', $e->reason);
        }
    }

    #[Test]
    public function to_workflow_payload_wires_callback_url_for_submit(): void
    {
        // submit() threads the webhook into the multi-job payload's callback_url.
        $json = \json_encode(
            $this->filesRecipe(['a.jpg', 'b.jpg'])
                ->compress()
                ->toWorkflowPayload(['file_0', 'file_1'], 'https://webhook.test/x')
                ->toWire(),
        );
        self::assertIsString($json);
        self::assertStringContainsString('"callback_url":"https:\/\/webhook.test\/x"', $json);
        self::assertStringContainsString('"id":"file-0"', $json);
        self::assertStringContainsString('"id":"file-1"', $json);
    }

    // --- run() partition (string-index keys, one-failure-doesn't-sink) ------

    #[Test]
    public function run_partitions_all_completed_jobs_into_succeeded_keyed_by_index(): void
    {
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse("event: workflow.completed\ndata: {\"status\":\"completed\"}\n\n"),
            $this->multiJobStatusResponse('completed', [
                ['ref' => 'file-0', 'status' => 'completed'],
                ['ref' => 'file-1', 'status' => 'completed'],
            ]),
            $this->multiJobDownloadsResponse([
                ['ref' => 'file-0', 'filename' => 'a.webp', 'size' => 10, 'url' => 'https://cdn/a.webp'],
                ['ref' => 'file-1', 'filename' => 'b.webp', 'size' => 20, 'url' => 'https://cdn/b.webp'],
            ]),
        ]);

        $client = $this->makeClient($http);
        $result = $client->files([FileInput::uploadId('id0'), FileInput::uploadId('id1')])
            ->compress()
            ->run();

        self::assertTrue($result->ok);
        self::assertSame([], $result->failed);
        self::assertSame(['0', '1'], array_map(static fn ($s) => $s->key, $result->succeeded));
        self::assertSame('a.webp', $result->byKey('0')->outputs[0]->filename);
        self::assertSame('b.webp', $result->byKey('1')->outputs[0]->filename);
        // The flat artifacts[] carries every job's outputs in job order.
        self::assertSame(['https://cdn/a.webp', 'https://cdn/b.webp'], array_map(static fn ($a) => $a->url, $result->artifacts));
    }

    #[Test]
    public function run_use_sse_false_polls_directly_and_never_opens_the_sse_stream(): void
    {
        // wf133EDR — useSSE:false routes the fan-out straight to the poll
        // fallback: no /events SSE request, terminal resolved via /status. The
        // queue carries NO sse response; the default SSE-first path is exercised
        // by the sibling run tests (which queue + consume an sse response).
        $captured = [];
        $http = $this->capturingStubClient([
            $this->createResponse(),
            $this->multiJobStatusResponse('completed', [
                ['ref' => 'file-0', 'status' => 'completed'],
                ['ref' => 'file-1', 'status' => 'completed'],
            ]),
            $this->multiJobDownloadsResponse([
                ['ref' => 'file-0', 'filename' => 'a.webp', 'size' => 10, 'url' => 'https://cdn/a.webp'],
                ['ref' => 'file-1', 'filename' => 'b.webp', 'size' => 20, 'url' => 'https://cdn/b.webp'],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $result = $client->files([FileInput::uploadId('id0'), FileInput::uploadId('id1')])
            ->compress()
            ->run(useSSE: false, pollIntervalMs: 0);

        self::assertTrue($result->ok);
        self::assertSame(['0', '1'], array_map(static fn ($s) => $s->key, $result->succeeded));

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
    public function run_one_failing_job_does_not_sink_the_rest(): void
    {
        // file-1 fails; file-0 + file-2 complete; workflow partially_failed.
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse("event: workflow.partially_failed\ndata: {\"status\":\"partially_failed\"}\n\n"),
            $this->multiJobStatusResponse('partially_failed', [
                ['ref' => 'file-0', 'status' => 'completed'],
                ['ref' => 'file-1', 'status' => 'failed', 'error' => 'codec exploded'],
                ['ref' => 'file-2', 'status' => 'completed'],
            ]),
            $this->multiJobDownloadsResponse([
                ['ref' => 'file-0', 'filename' => 'a.webp', 'size' => 10, 'url' => 'https://cdn/a.webp'],
                ['ref' => 'file-2', 'filename' => 'c.webp', 'size' => 30, 'url' => 'https://cdn/c.webp'],
            ]),
        ]);

        $client = $this->makeClient($http);
        $result = $client->files([
            FileInput::uploadId('id0'),
            FileInput::uploadId('id1'),
            FileInput::uploadId('id2'),
        ])->compress()->run();

        self::assertSame('partially_failed', $result->state);
        self::assertFalse($result->ok);
        self::assertSame(['0', '2'], array_map(static fn ($s) => $s->key, $result->succeeded));
        self::assertSame(['1'], array_map(static fn ($f) => $f->key, $result->failed));
        // The failed item carries THAT job's first op error, scoped via the
        // PER-JOB status: "{jobStatus}: {message}".
        self::assertInstanceOf(GislItemFailedError::class, $result->failed[0]->error);
        self::assertSame('failed: codec exploded', $result->failed[0]->error->getMessage());
        // The typed error exposes the per-job state for branchless handling; no
        // error_code was mocked, so it stays null.
        self::assertSame('failed', $result->failed[0]->error->state);
        self::assertSame('codec exploded', $result->failed[0]->error->errorMessage);
        self::assertNull($result->failed[0]->error->errorCode);
        // The succeeded outputs are still resolvable; the failed job has none.
        self::assertSame('https://cdn/a.webp', $result->byKey('0')->outputs[0]->url);
        self::assertSame('https://cdn/c.webp', $result->byKey('2')->outputs[0]->url);
        self::assertSame(['https://cdn/a.webp', 'https://cdn/c.webp'], array_map(static fn ($a) => $a->url, $result->artifacts));
    }

    #[Test]
    public function run_failed_jobs_scope_error_code_and_message_per_job(): void
    {
        // PER-JOB scoping of the typed GislItemFailedError: file-0 fails with
        // BOTH an error_code + error_message; file-1 fails with only a message
        // (no code). Each failed entry reads from ITS OWN job's first failing op
        // — never bleeding the other job's code.
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse("event: workflow.failed\ndata: {\"status\":\"failed\"}\n\n"),
            $this->multiJobStatusResponse('failed', [
                ['ref' => 'file-0', 'status' => 'failed', 'error' => 'too large', 'error_code' => 'output_too_large'],
                ['ref' => 'file-1', 'status' => 'failed', 'error' => 'unsupported pixel format'],
            ]),
            $this->multiJobDownloadsResponse([]),
        ]);

        $client = $this->makeClient($http);
        $result = $client->files([
            FileInput::uploadId('id0'),
            FileInput::uploadId('id1'),
        ])->compress()->run();

        self::assertSame(['0', '1'], array_map(static fn ($f) => $f->key, $result->failed));

        // file-0: both fields present.
        self::assertInstanceOf(GislItemFailedError::class, $result->failed[0]->error);
        self::assertSame('failed', $result->failed[0]->error->state);
        self::assertSame('too large', $result->failed[0]->error->errorMessage);
        self::assertSame('output_too_large', $result->failed[0]->error->errorCode);

        // file-1: message present, code absent (the other job's code never leaks).
        self::assertSame('failed', $result->failed[1]->error->state);
        self::assertSame('unsupported pixel format', $result->failed[1]->error->errorMessage);
        self::assertNull($result->failed[1]->error->errorCode);

        // toArray() reflects the per-job key sets: first entry has errorCode,
        // second omits it.
        $failedArray = $result->toArray()['failed'];
        self::assertSame(
            ['key' => '0', 'error' => 'failed: too large', 'state' => 'failed', 'errorMessage' => 'too large', 'errorCode' => 'output_too_large'],
            $failedArray[0],
        );
        self::assertSame(
            ['key' => '1', 'error' => 'failed: unsupported pixel format', 'state' => 'failed', 'errorMessage' => 'unsupported pixel format'],
            $failedArray[1],
        );
        self::assertArrayNotHasKey('errorCode', $failedArray[1]);
    }

    #[Test]
    public function run_all_jobs_failed_yields_failed_workflow_no_artifacts(): void
    {
        // The symmetric bookend to one-failure-doesn't-sink: when EVERY input
        // fails the workflow state is `failed` (NOT partially_failed). Both jobs
        // partition into failed (keyed "0"/"1"), each carrying ITS OWN scoped
        // error; nothing succeeds; the flat artifacts[] is empty; toArray() omits
        // the single-output `url` sugar (0 artifacts).
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse("event: workflow.failed\ndata: {\"status\":\"failed\"}\n\n"),
            $this->multiJobStatusResponse('failed', [
                ['ref' => 'file-0', 'status' => 'failed', 'error' => 'codec exploded'],
                ['ref' => 'file-1', 'status' => 'failed', 'error' => 'unsupported pixel format'],
            ]),
            $this->multiJobDownloadsResponse([]),
        ]);

        $client = $this->makeClient($http);
        $result = $client->files([
            FileInput::uploadId('id0'),
            FileInput::uploadId('id1'),
        ])->compress()->run();

        self::assertSame('failed', $result->state);
        self::assertFalse($result->ok);
        self::assertSame([], $result->succeeded);
        self::assertSame(['0', '1'], array_map(static fn ($f) => $f->key, $result->failed));
        // Each failed item carries THAT job's first op error, scoped via its
        // PER-JOB status: "{jobStatus}: {message}".
        self::assertSame('failed: codec exploded', $result->failed[0]->error->getMessage());
        self::assertSame('failed: unsupported pixel format', $result->failed[1]->error->getMessage());
        self::assertSame([], $result->artifacts);
        // Zero artifacts → the single-output `url` sugar key is OMITTED entirely
        // (cross-language parity with TS's omit-when-undefined toJSON()).
        self::assertArrayNotHasKey('url', $result->toArray());
    }

    #[Test]
    public function by_key_resolves_string_index_and_throws_no_such_key_otherwise(): void
    {
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse("event: workflow.completed\ndata: {\"status\":\"completed\"}\n\n"),
            $this->multiJobStatusResponse('completed', [
                ['ref' => 'file-0', 'status' => 'completed'],
                ['ref' => 'file-1', 'status' => 'completed'],
            ]),
            $this->multiJobDownloadsResponse([
                ['ref' => 'file-0', 'filename' => 'a.webp', 'size' => 10, 'url' => 'https://cdn/a.webp'],
                ['ref' => 'file-1', 'filename' => 'b.webp', 'size' => 20, 'url' => 'https://cdn/b.webp'],
            ]),
        ]);

        $client = $this->makeClient($http);
        $result = $client->files([FileInput::uploadId('id0'), FileInput::uploadId('id1')])
            ->compress()
            ->run();

        // String-index addressing: "0" resolves; loose numeric forms do NOT.
        self::assertSame('0', $result->byKey('0')->key);
        $this->expectException(GislNoSuchKeyError::class);
        // '00' is NOT '0' — the partition keys are the verbatim (string) $i.
        $result->byKey('00');
    }

    #[Test]
    public function files_with_empty_list_throws_config_error_reason_no_inputs(): void
    {
        // A zero-input fan-out is a caller error — the guard fires synchronously,
        // before any upload/create ("one bad input doesn't sink the rest" is
        // meaningless with no inputs). The helper's transport throws on any I/O,
        // proving no request is issued.
        $client = GislErgonomicClientFactoryTestHelper::client();
        try {
            $client->files([]);
            self::fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertSame('no_inputs', $e->reason);
        }
    }

    #[Test]
    public function run_no_client_guard_throws_config_error_reason_no_client(): void
    {
        $bare = new FilesRecipe([FileInput::uploadId('id0')]);
        try {
            $bare->compress()->run();
            self::fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertSame('no_client', $e->getReason());
        }
    }

    #[Test]
    public function run_compress_optimize_on_no_media_input_fails_before_any_upload(): void
    {
        // The fan-out preflights per-input lowering before any upload, so
        // compress(optimize) on an input with no inferable media (a pre-uploaded
        // id) throws media_unknown BEFORE earlier inputs upload (codex r2).
        $http = $this->stubClient([]);   // empty — any request means an input uploaded
        $client = $this->makeClient($http);
        try {
            $client->files([FileInput::uploadId('id0'), FileInput::uploadId('id1')])
                ->compress(OptimizeFor::Size)
                ->run();
            self::fail('expected GislConfigError media_unknown');
        } catch (GislConfigError $e) {
            self::assertSame('media_unknown', $e->getReason());
        }
    }

    #[Test]
    public function run_missing_path_after_valid_path_fails_before_any_upload(): void
    {
        // Path existence is preflighted before any upload too (codex r3), so a
        // missing path listed after a valid one does NOT upload the valid one.
        $valid = \tempnam(\sys_get_temp_dir(), 'gisl');
        self::assertNotFalse($valid);
        \file_put_contents($valid, 'ok');
        $http = $this->stubClient([]);   // empty — any request means the valid path uploaded
        $client = $this->makeClient($http);
        try {
            $client->files([FileInput::path($valid), FileInput::path('/nonexistent/missing.bin')])
                ->compress()
                ->run();
            self::fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertStringContainsString('File not found', $e->getMessage());
        } finally {
            @\unlink($valid);
        }
    }

    #[Test]
    public function run_bad_resource_filename_hint_after_a_valid_input_fails_before_any_upload(): void
    {
        // fFwaKsN5 (codex r2): a resource's filename/contentType hints are
        // validated in the pre-upload preflight, so a bad hint listed AFTER a
        // valid input does NOT upload the earlier input first.
        $good = \fopen('php://temp', 'r+b');
        $bad = \fopen('php://temp', 'r+b');
        self::assertIsResource($good);
        self::assertIsResource($bad);
        $http = $this->stubClient([]);   // empty — any request means an input uploaded
        $client = $this->makeClient($http);
        try {
            $client->files([
                FileInput::resource($good, filename: 'ok.jpg'),
                FileInput::resource($bad, filename: 'dir/evil.jpg'),   // path separator → invalid
            ])->compress()->run();
            self::fail('expected GislConfigError before any upload');
        } catch (GislConfigError $e) {
            self::assertStringContainsString('bare filename', $e->getMessage());
        } finally {
            \fclose($good);
            \fclose($bad);
        }
    }

    #[Test]
    public function file_input_resource_rejects_non_resource(): void
    {
        // FileInput::resource() validates its argument (mirrors Merge::resource())
        // so a misuse fails at construction, not as a silent path upload (codex r2).
        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/expected an open stream resource/');
        /** @phpstan-ignore-next-line — deliberately passing a non-resource. */
        FileInput::resource('not a resource');
    }

    #[Test]
    public function run_non_seekable_resource_after_path_fails_before_any_upload(): void
    {
        // The fan-out preflights ALL resource inputs for seekability before
        // uploading ANY input, so a non-seekable stream listed after a path
        // input does NOT leave the path uploaded (codex VOxtu0RZ-B4).
        $path = \tempnam(\sys_get_temp_dir(), 'gisl');
        self::assertNotFalse($path);
        \file_put_contents($path, 'ok');
        $pipe = \popen('printf x', 'r');
        self::assertIsResource($pipe);
        $http = $this->stubClient([]);   // empty — any request means the path uploaded
        $client = $this->makeClient($http);
        try {
            $client->files([FileInput::path($path), FileInput::resource($pipe)])->compress()->run();
            self::fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertSame('non_seekable_stream', $e->getReason());
        } finally {
            \pclose($pipe);
            @\unlink($path);
        }
    }

    #[Test]
    public function run_resource_input_arm_rejects_non_seekable_stream(): void
    {
        // VOxtu0RZ-B4: seekable stream inputs are now uploaded by the fan-out
        // (covered via the single-file Recipe + GislClient suites); a
        // NON-seekable stream is rejected with an actionable error (Option B)
        // BEFORE any network call.
        $pipe = \popen('printf x', 'r');
        self::assertIsResource($pipe);
        $http = $this->stubClient([]);   // empty queue — no request should fire
        $client = $this->makeClient($http);
        try {
            $client->files([FileInput::resource($pipe)])->compress()->run();
            self::fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertSame('non_seekable_stream', $e->getReason());
        } finally {
            \pclose($pipe);
        }
    }

    #[Test]
    public function run_mid_batch_timeout_message_names_the_fan_out_label(): void
    {
        // xxy5Rlsy follow-up (Wi4OnaJE): pin the fan-out label noun the shared
        // MultiInputUpload helper threads into its timeout message. A mid-batch
        // deadline (maxWait 1ms + a slow first upload over two inputs) trips the
        // `during {uploadsLabel} uploads` throw. The fan-out noun is the
        // DISTINGUISHING one (the post-upload workflowLabel for a fan-out is the
        // generic 'workflow'), so a 'fan-out' -> 'workflow' regression is caught.
        $a = $this->tempFile('jpg');
        $b = $this->tempFile('jpg');
        $http = $this->slowFirstStubClient([$this->uploadResponse(), $this->uploadResponse()]);
        $client = $this->makeClient($http);
        try {
            $client->files([FileInput::path($a), FileInput::path($b)])
                ->compress()
                ->run(maxWait: 1);
            self::fail('expected GislTimeoutError');
        } catch (GislTimeoutError $e) {
            self::assertStringContainsString('fan-out', $e->getMessage());
        } finally {
            @\unlink($a);
            @\unlink($b);
        }
    }

    // ----------------------------------------------------------------------
    // Stub plumbing — mirrors RecipeRunTest.
    // ----------------------------------------------------------------------

    /**
     * A PSR-18 stub that usleeps ~5ms on its FIRST request so a 1ms maxWait is
     * reliably blown DURING the upload loop — the mid-batch throw.
     *
     * @param list<ResponseInterface> $queue
     */
    private function slowFirstStubClient(array $queue): ClientInterface
    {
        return new class ($queue) implements ClientInterface {
            /** @var list<ResponseInterface> */
            private array $queue;
            private bool $first = true;

            /** @param list<ResponseInterface> $queue */
            public function __construct(array $queue)
            {
                $this->queue = $queue;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                if ($this->first) {
                    $this->first = false;
                    \usleep(5000);
                }
                $next = \array_shift($this->queue);
                if ($next === null) {
                    throw new \RuntimeException('Stub PSR-18 client: response queue exhausted');
                }
                return $next;
            }
        };
    }

    private function uploadResponse(string $fileId = '01936fb1-7bb3-7000-8000-0000000060c1'): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['file_id' => $fileId, 'content_type' => 'image/jpeg', 'size_bytes' => 2048],
        ]);
    }

    private function tempFile(string $ext): string
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'gisl_files_');
        self::assertNotFalse($tmp);
        $path = $tmp . '.' . $ext;
        \rename($tmp, $path);
        \file_put_contents($path, \str_repeat('x', 64));
        return $path;
    }

    /**
     * @param list<ResponseInterface|\Throwable> $queue
     */
    private function stubClient(array $queue): ClientInterface
    {
        return new class ($queue) implements ClientInterface {
            /** @var list<ResponseInterface|\Throwable> */
            private array $queue;

            /**
             * @param list<ResponseInterface|\Throwable> $queue
             */
            public function __construct(array $queue)
            {
                $this->queue = $queue;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
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

    /**
     * A capturing PSR-18 stub (the sibling {@see stubClient} does not record
     * requests) so the useSSE opt-out test can assert the request routing.
     *
     * @param list<ResponseInterface|\Throwable> $queue
     * @param-out list<RequestInterface>          $captured
     */
    private function capturingStubClient(array $queue, array &$captured = []): ClientInterface
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
        // files() lives on GislErgonomicClient (the subclass), not the base
        // GislClient — the run tests drive the fan-out through it.
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

    private function sseResponse(string $sse): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/event-stream'], $sse);
    }

    /**
     * @param list<array{ref: string, status: string, error?: string}> $jobs
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
                        'operation' => 'compress',
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
}
