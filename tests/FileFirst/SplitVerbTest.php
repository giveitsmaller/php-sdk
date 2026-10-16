<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\FileFirst;

use Gisl\Sdk\Ergonomic\OperationBuilder;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislSinkError;
use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\OutputFile;
use Gisl\Sdk\FileFirst\Recipe;
use Gisl\Sdk\FileFirst\SplitRecipe;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use Gisl\Sdk\OperationDef;
use Gisl\Sdk\Tests\Unit\Ergonomic\GislErgonomicClientFactoryTestHelper;
use Gisl\Sdk\WorkflowCreatePayload;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * DflFw0j1 — the `split` verb in the PHP ergonomic layer: `Recipe::split()`
 * returning the TERMINAL {@see SplitRecipe}, and the single-op
 * `GislErgonomicClient::split()`. Mirrors the TS `split-verb.test.ts`.
 *
 * Pinned here:
 *  - lowering per mime group (GIF, PDF both modes, audio silence, video), with
 *    null values dropped and pre-steps lowered into a `pre` job;
 *  - terminality: SplitRecipe's public surface is exactly the delegated set, and
 *    batch() refuses it (`invalid_recipe`) before any request;
 *  - runtime: unknown keys rejected at the verb call on both surfaces; a second
 *    sole_op step rejected eagerly; `precision: 'exact'` refused with ZERO
 *    requests on BOTH surfaces, and `fast` passes (control);
 *  - beta groups and the beta silence mode reach createWorkflow unchanged
 *    (control against an accidental SDK gate);
 *  - run() over 3 outputs and submit() -> handle->result().
 */
final class SplitVerbTest extends TestCase
{
    private const FILE_ID = 'file_0001';
    private const WORKFLOW_ID = '01936fb2-0000-7000-8000-00000000d531';
    private const TERMINAL_SSE = "event: workflow.completed\ndata: {\"status\":\"completed\"}\n\n";

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
            @\unlink($path);
        }
        $this->tempFiles = [];
    }

    // --- Lowering -----------------------------------------------------------

    /**
     * The one `split` op of a lowered payload, in wire shape (fails if absent).
     *
     * @return array<string, mixed>
     */
    private function splitOp(WorkflowCreatePayload $payload): array
    {
        /** @var list<array<string, mixed>> $jobs */
        $jobs = $payload->toWire()['jobs'];
        foreach ($jobs as $job) {
            /** @var list<array<string, mixed>> $ops */
            $ops = $job['operations'];
            foreach ($ops as $op) {
                if (($op['type'] ?? null) === 'split') {
                    return $op;
                }
            }
        }
        self::fail('expected a split op in the lowered payload');
    }

    #[Test]
    public function gif_frame_range_and_output_format_lower_to_one_job_with_no_id(): void
    {
        $wire = (new Recipe(FileInput::path('anim.gif')))
            ->split(['frame_range' => '1-10', 'output_format' => 'webp'])
            ->toWorkflowPayload(self::FILE_ID)
            ->toWire();
        self::assertSame([
            'jobs' => [[
                'operations' => [['type' => 'split', 'options' => ['frame_range' => '1-10', 'output_format' => 'webp']]],
                'source' => ['type' => 'upload', 'file_id' => self::FILE_ID],
            ]],
        ], $this->sortedJobKeys($wire));
    }

    #[Test]
    public function pdf_page_range_and_page_groups_lower_verbatim(): void
    {
        $range = (new Recipe(FileInput::path('doc.pdf')))->split(['mode' => 'page_range', 'page_range' => '1-3,8']);
        self::assertSame(
            ['type' => 'split', 'options' => ['mode' => 'page_range', 'page_range' => '1-3,8']],
            $this->splitOp($range->toWorkflowPayload(self::FILE_ID)),
        );
        $groups = (new Recipe(FileInput::path('doc.pdf')))->split(['mode' => 'page_groups', 'page_groups' => 5]);
        self::assertSame(
            ['type' => 'split', 'options' => ['mode' => 'page_groups', 'page_groups' => 5]],
            $this->splitOp($groups->toWorkflowPayload(self::FILE_ID)),
        );
    }

    #[Test]
    public function audio_silence_mode_lowers_every_silence_key(): void
    {
        $payload = (new Recipe(FileInput::path('talk.mp3')))
            ->split(['mode' => 'silence', 'silence_threshold_db' => -40, 'silence_min_duration' => 0.5, 'silence_handling' => 'keep_at_end'])
            ->toWorkflowPayload(self::FILE_ID);
        self::assertSame(
            ['mode' => 'silence', 'silence_threshold_db' => -40, 'silence_min_duration' => 0.5, 'silence_handling' => 'keep_at_end'],
            $this->splitOp($payload)['options'],
        );
    }

    #[Test]
    public function video_cut_points_lower_the_list_as_is(): void
    {
        $payload = (new Recipe(FileInput::path('clip.mp4')))
            ->split(['mode' => 'cut_points', 'cut_points' => [1.5, 3, 7.25], 'precision' => 'fast'])
            ->toWorkflowPayload(self::FILE_ID);
        self::assertSame(
            ['mode' => 'cut_points', 'cut_points' => [1.5, 3, 7.25], 'precision' => 'fast'],
            $this->splitOp($payload)['options'],
        );
    }

    #[Test]
    public function an_explicit_null_value_is_dropped(): void
    {
        $payload = (new Recipe(FileInput::path('clip.mp4')))
            ->split(['mode' => 'count', 'count' => 4, 'precision' => null])
            ->toWorkflowPayload(self::FILE_ID);
        self::assertSame(['mode' => 'count', 'count' => 4], $this->splitOp($payload)['options']);
    }

    #[Test]
    public function split_with_no_options_lowers_to_a_bare_split_op(): void
    {
        // split() with no bag is accepted by the SDK (the `= []` default) and lowers
        // to `{type: 'split'}` with no `options` key, as TS lowers an untyped bare
        // call; the server rejects it (GIF needs frame_range, others need a mode).
        $split = (new Recipe(FileInput::path('anim.gif')))->split();
        self::assertSame(['type' => 'split'], $this->splitOp($split->toWorkflowPayload(self::FILE_ID)));
    }

    #[Test]
    public function a_pre_step_lowers_into_a_pre_job_feeding_the_split_job(): void
    {
        $split = (new Recipe(FileInput::path('deck.docx')))->convert('pdf')->split(['mode' => 'page_groups', 'page_groups' => 2]);
        self::assertSame(2, $split->stepCount());

        $payload = $split->toWorkflowPayload(self::FILE_ID);
        self::assertSame(['pre', 'split'], \array_map(static fn ($j) => $j->id, $payload->jobs));
        /** @var list<array<string, mixed>> $jobs */
        $jobs = $payload->toWire()['jobs'];
        self::assertSame(['type' => 'upload', 'file_id' => self::FILE_ID], $jobs[0]['source']);
        self::assertSame(['convert'], \array_map(static fn (OperationDef $o) => $o->type, $payload->jobs[0]->operations));
        self::assertSame(['type' => 'job_output', 'from' => 'pre'], $jobs[1]['source']);
        self::assertSame(
            [['type' => 'split', 'options' => ['mode' => 'page_groups', 'page_groups' => 2]]],
            $jobs[1]['operations'],
        );
    }

    #[Test]
    public function threads_the_recipe_key_and_a_callback_url(): void
    {
        $split = (new Recipe(FileInput::path('doc.pdf'), 'report'))->split(['page_range' => '1-2']);
        self::assertSame('report', $split->key());
        self::assertSame(
            'https://hooks.example.com/x',
            $split->toWorkflowPayload(self::FILE_ID, 'https://hooks.example.com/x')->callbackUrl,
        );
    }

    // --- Terminality --------------------------------------------------------

    #[Test]
    public function split_recipe_exposes_exactly_the_delegated_surface(): void
    {
        // Pins the whole public surface, not a sample: adding any step verb
        // (compress, convert, transform, textWatermark, output, watermark, split, ...)
        // to SplitRecipe fails here.
        $methods = \get_class_methods(SplitRecipe::class);
        \sort($methods);
        self::assertSame(['__construct', 'key', 'run', 'stepCount', 'submit', 'toWorkflowPayload'], $methods);
    }

    #[Test]
    public function the_delegated_signatures_match_recipe(): void
    {
        foreach (['key', 'stepCount', 'toWorkflowPayload', 'run', 'submit'] as $method) {
            self::assertSame(
                $this->signatureOf(Recipe::class, $method),
                $this->signatureOf(SplitRecipe::class, $method),
                "SplitRecipe::{$method}() drifted from Recipe::{$method}()",
            );
        }
    }

    #[Test]
    public function batch_refuses_a_split_recipe_as_invalid_recipe_with_zero_requests(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([], $captured));
        $split = $client->file(FileInput::uploadId('file_existing'), 'a')->split(['page_range' => '1']);
        try {
            $client->batch([$split])->run();
            self::fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertSame('invalid_recipe', $e->reason);
        }
        self::assertSame([], $captured);
    }

    // --- Runtime validation at the call -------------------------------------

    #[Test]
    public function recipe_split_rejects_an_unknown_key_at_the_verb_call(): void
    {
        $err = $this->captureConfigError(static fn () => (new Recipe(FileInput::path('doc.pdf')))->split(['bogus' => 1]));
        self::assertSame('unknown_field', $err->reason);
        self::assertSame(['bogus'], $err->conflictingFields);
    }

    #[Test]
    public function client_split_rejects_an_unknown_key_at_the_call(): void
    {
        $client = GislErgonomicClientFactoryTestHelper::client();
        $err = $this->captureConfigError(static fn () => $client->split('doc.pdf', ['bogus' => 1]));
        self::assertSame('unknown_field', $err->reason);
        self::assertSame(['bogus'], $err->conflictingFields);
    }

    #[Test]
    public function client_split_builds_an_operation_builder_for_a_valid_bag(): void
    {
        $client = GislErgonomicClientFactoryTestHelper::client();
        self::assertInstanceOf(OperationBuilder::class, $client->split('doc.pdf', ['mode' => 'page_range', 'page_range' => '1-2']));
        self::assertInstanceOf(OperationBuilder::class, $client->split('doc.pdf'));
    }

    #[Test]
    public function a_recipe_already_holding_a_sole_op_step_fails_at_the_split_call(): void
    {
        $err = $this->captureConfigError(
            static fn () => (new Recipe(FileInput::path('photo.png')))->textWatermark('(c) Acme')->split(['frame_range' => '1-2']),
        );
        self::assertSame('multi_sole_op_unsupported', $err->reason);
        self::assertSame(
            'split() cannot follow text_watermark: both are sole_op operations and a recipe chains at most one '
            . '— run them as separate workflows.',
            $err->getMessage(),
        );
    }

    #[Test]
    public function a_non_sole_op_pre_step_is_allowed(): void
    {
        // Control for the eager check above.
        $split = (new Recipe(FileInput::path('deck.docx')))->convert('pdf')->split(['page_range' => '1']);
        self::assertInstanceOf(SplitRecipe::class, $split);
    }

    // --- run() / submit() ---------------------------------------------------

    #[Test]
    public function run_returns_only_the_split_job_outputs_with_page_index_and_no_single_output_url(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient($this->chainRunQueue(), $captured));
        $path = $this->tempFile('docx');

        $result = $client->file($path, 'deck')
            ->convert('pdf')
            ->split(['mode' => 'page_groups', 'page_groups' => 2])
            ->run(maxWait: '30s');

        // upload + create + sse + status + downloads.
        self::assertCount(5, $captured);
        $create = \json_decode((string) $captured[1]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($create);
        self::assertSame(['pre', 'split'], \array_column($create['jobs'], 'id'));

        // The `pre` job's intermediate PDF is filtered out.
        self::assertSame(['deck-1.pdf', 'deck-3.pdf', 'deck-5.pdf'], \array_map(static fn (OutputFile $o) => $o->filename, $result->artifacts));
        self::assertSame([1, 3, 5], \array_map(static fn (OutputFile $o) => $o->pageIndex, $result->artifacts));
        self::assertSame([null, null, null], \array_map(static fn (OutputFile $o) => $o->position, $result->artifacts));
        self::assertNull($result->url);
        self::assertTrue($result->ok);
        self::assertCount(1, $result->succeeded);
        self::assertSame('deck', $result->succeeded[0]->key);
        self::assertCount(3, $result->succeeded[0]->outputs);
    }

    #[Test]
    public function to_file_refuses_a_multi_output_split_result(): void
    {
        $client = $this->makeClient($this->stubClient($this->chainRunQueue(withUpload: false)));
        $result = $client->file(FileInput::uploadId('file_existing'))
            ->convert('pdf')
            ->split(['page_range' => '1,3,5'])
            ->run(maxWait: '30s');
        try {
            $result->toFile(\sys_get_temp_dir() . '/gisl-never-written.pdf');
            self::fail('expected GislSinkError');
        } catch (GislSinkError $e) {
            self::assertSame('not_single_output', $e->reason);
        }
    }

    #[Test]
    public function a_lone_split_keeps_every_download_and_carries_position_zero(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([
            $this->createResponse(),
            $this->sseResponse(self::TERMINAL_SSE),
            $this->statusResponse([['ref' => 'job_1', 'status' => 'completed', 'operations' => []]]),
            $this->downloadsResponse([[
                'ref' => 'job_1',
                'files' => [
                    $this->file('talk-0.mp3', 10, ['position' => 0]),
                    $this->file('talk-1.mp3', 10, ['position' => 1]),
                ],
            ]]),
        ], $captured));

        $result = $client->file(FileInput::uploadId('file_existing'))
            ->split(['mode' => 'count', 'count' => 2])
            ->run(maxWait: '30s');

        $create = \json_decode((string) $captured[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($create);
        self::assertCount(1, $create['jobs']);
        self::assertSame([0, 1], \array_map(static fn (OutputFile $o) => $o->position, $result->artifacts));
        self::assertSame(0, $result->toArray()['artifacts'][0]['position'] ?? null);
    }

    #[Test]
    public function submit_returns_a_keyed_handle_whose_result_projects_the_split_outputs(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([
            $this->uploadResponse(),
            $this->createResponse(),
            $this->chainStatusResponse(),
            $this->chainDownloadsResponse(),
        ], $captured));
        $path = $this->tempFile('docx');

        $handle = $client->file($path, 'deck')
            ->convert('pdf')
            ->split(['page_range' => '1,3,5'])
            ->submit('https://hooks.example.com/split');

        self::assertSame(self::WORKFLOW_ID, $handle->workflowId);
        self::assertCount(2, $captured);
        $create = \json_decode((string) $captured[1]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($create);
        self::assertSame('https://hooks.example.com/split', $create['callback_url']);

        $result = $handle->result();
        self::assertSame([1, 3, 5], \array_map(static fn (OutputFile $o) => $o->pageIndex, $result->artifacts));
        self::assertSame('deck', $result->succeeded[0]->key);
    }

    // --- Availability: precision 'exact' refused; beta passes through -------

    #[Test]
    public function recipe_split_refuses_precision_exact_on_run_and_submit_with_zero_requests(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([], $captured));
        $split = $client->file($this->tempFile('mp4'))->split(['mode' => 'count', 'count' => 3, 'precision' => 'exact']);

        foreach ([static fn () => $split->run(maxWait: '30s'), static fn () => $split->submit()] as $call) {
            $err = $this->captureConfigError($call);
            self::assertSame('feature_not_available', $err->reason);
            self::assertSame(['precision'], $err->conflictingFields);
        }
        self::assertSame([], $captured);
    }

    #[Test]
    public function recipe_split_with_precision_fast_reaches_create_workflow(): void
    {
        // Control for the refusal above: the same bag with `fast` uploads and creates.
        $captured = [];
        $client = $this->makeClient($this->stubClient([$this->uploadResponse(), $this->createResponse()], $captured));
        $client->file($this->tempFile('mp4'))
            ->split(['mode' => 'count', 'count' => 3, 'precision' => 'fast'])
            ->submit();
        self::assertCount(2, $captured);
        self::assertStringContainsString('/workflows', (string) $captured[1]->getUri());
    }

    #[Test]
    public function client_split_refuses_precision_exact_on_run_and_submit_with_zero_requests(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([], $captured));
        $builder = $client->split($this->tempFile('mp3'), ['mode' => 'interval', 'interval' => 30, 'precision' => 'exact']);

        foreach ([static fn () => $builder->run(), static fn () => $builder->submit()] as $call) {
            $err = $this->captureConfigError($call);
            self::assertSame('feature_not_available', $err->reason);
            self::assertSame(['precision'], $err->conflictingFields);
        }
        self::assertSame([], $captured);
    }

    #[Test]
    public function client_split_with_precision_fast_uploads_and_creates(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([$this->uploadResponse(), $this->createResponse()], $captured));
        $client->split($this->tempFile('mp3'), ['mode' => 'interval', 'interval' => 30, 'precision' => 'fast', 'count' => null])
            ->submit();

        self::assertCount(2, $captured);
        $create = \json_decode((string) $captured[1]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($create);
        // The null `count` is dropped before the wire.
        self::assertSame(
            ['type' => 'split', 'options' => ['mode' => 'interval', 'interval' => 30, 'precision' => 'fast']],
            $create['jobs'][0]['operations'][0],
        );
    }

    #[Test]
    public function audio_silence_mode_beta_reaches_create_workflow_unchanged(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([$this->uploadResponse(), $this->createResponse()], $captured));
        $client->file($this->tempFile('mp3'))
            ->split(['mode' => 'silence', 'silence_threshold_db' => -45])
            ->submit();

        self::assertCount(2, $captured);
        $create = \json_decode((string) $captured[1]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($create);
        self::assertSame(['mode' => 'silence', 'silence_threshold_db' => -45], $create['jobs'][0]['operations'][0]['options']);
    }

    #[Test]
    public function video_interval_beta_group_reaches_create_workflow_unchanged(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([$this->createResponse()], $captured));
        $client->file(FileInput::uploadId('file_existing'))
            ->split(['mode' => 'interval', 'interval' => 10])
            ->submit();

        self::assertCount(1, $captured);
        $create = \json_decode((string) $captured[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($create);
        self::assertSame(['mode' => 'interval', 'interval' => 10], $create['jobs'][0]['operations'][0]['options']);
    }

    // --- helpers --------------------------------------------------------------

    /**
     * @param array<string, mixed> $wire
     * @return array<string, mixed>
     */
    private function sortedJobKeys(array $wire): array
    {
        /** @var list<array<string, mixed>> $jobs */
        $jobs = $wire['jobs'];
        foreach ($jobs as $i => $job) {
            \ksort($job);
            $jobs[$i] = $job;
        }
        $wire['jobs'] = $jobs;

        return $wire;
    }

    /** @return list<string> */
    private function signatureOf(string $class, string $method): array
    {
        $reflection = new \ReflectionMethod($class, $method);
        $signature = [(string) $reflection->getReturnType()];
        foreach ($reflection->getParameters() as $param) {
            $signature[] = $param->getName() . ':' . $param->getType()
                . ($param->isDefaultValueAvailable() ? '=' . \var_export($param->getDefaultValue(), true) : '');
        }

        return $signature;
    }

    private function captureConfigError(callable $fn): GislConfigError
    {
        try {
            $fn();
        } catch (GislConfigError $e) {
            return $e;
        }
        self::fail('expected the call to throw a GislConfigError, but it did not');
    }

    private function tempFile(string $extension): string
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'gisl_split_');
        self::assertIsString($tmp);
        $path = $tmp . '.' . $extension;
        \rename($tmp, $path);
        \file_put_contents($path, \str_repeat('x', 2048));
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @param list<ResponseInterface> $queue
     * @param list<RequestInterface>  $captured
     * @param-out list<RequestInterface> $captured
     */
    private function stubClient(array $queue, array &$captured = []): ClientInterface
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
                    throw new \RuntimeException('Stub PSR-18 client: response queue exhausted');
                }
                return $next;
            }
        };
    }

    private function makeClient(ClientInterface $http): GislErgonomicClient
    {
        return new GislErgonomicClient(
            config: new GislClientConfig(
                baseUrl: 'https://api.example.com',
                streamBaseUrl: 'https://stream.example.com',
                apiKey: 'sk_test',
                multipartConcurrency: 1,
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
        return new Response($status, ['Content-Type' => 'application/json'], (string) \json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function uploadResponse(): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['file_id' => '01936fb1-7bb3-7000-8000-00000000d530', 'content_type' => 'application/octet-stream', 'size_bytes' => 2048],
        ]);
    }

    private function createResponse(): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['workflow_id' => self::WORKFLOW_ID, 'status' => 'pending'],
        ]);
    }

    private function sseResponse(string $sse): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/event-stream'], $sse);
    }

    /**
     * @param list<array<string, mixed>> $jobs
     */
    private function statusResponse(array $jobs): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['workflow_id' => self::WORKFLOW_ID, 'status' => 'completed', 'jobs' => $jobs],
        ]);
    }

    /** A completed `pre` -> `split` chain. */
    private function chainStatusResponse(): ResponseInterface
    {
        return $this->statusResponse([
            ['ref' => 'pre', 'status' => 'completed', 'operations' => []],
            ['ref' => 'split', 'status' => 'completed', 'operations' => []],
        ]);
    }

    /** The `pre` job's intermediate PDF plus the split's three page outputs. */
    private function chainDownloadsResponse(): ResponseInterface
    {
        return $this->downloadsResponse([
            ['ref' => 'pre', 'files' => [$this->file('deck.pdf', 9, [])]],
            ['ref' => 'split', 'files' => [
                $this->file('deck-1.pdf', 101, ['page_index' => 1]),
                $this->file('deck-3.pdf', 103, ['page_index' => 3]),
                $this->file('deck-5.pdf', 105, ['page_index' => 5]),
            ]],
        ]);
    }

    /** @return list<ResponseInterface> */
    private function chainRunQueue(bool $withUpload = true): array
    {
        return [
            ...($withUpload ? [$this->uploadResponse()] : []),
            $this->createResponse(),
            $this->sseResponse(self::TERMINAL_SSE),
            $this->chainStatusResponse(),
            $this->chainDownloadsResponse(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $jobs
     */
    private function downloadsResponse(array $jobs): ResponseInterface
    {
        return $this->jsonResponse(200, ['success' => true, 'data' => ['downloads' => $jobs]]);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function file(string $filename, int $size, array $extra): array
    {
        return [
            'operation' => \str_starts_with($filename, 'deck-') || \str_starts_with($filename, 'talk-') ? 'split' : 'convert',
            'operation_id' => '01936fb4-0001-7000-8000-00000000d532',
            'filename' => $filename,
            'size_bytes' => $size,
            'download_url' => 'https://signed.example.com/' . $filename,
            ...$extra,
        ];
    }
}
