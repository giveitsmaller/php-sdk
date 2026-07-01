<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\FileFirst;

use Gisl\Sdk\Ergonomic\ArchiveFormat;
use Gisl\Sdk\Ergonomic\ArchiveRecipeOptions;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\FileFirst\ArchivedRecipe;
use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\FilesRecipe;
use Gisl\Sdk\FileFirst\RecipeStep;
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
 * Fluent `files([...])->archive(...)` (FF3b). N→1 bundle, terminal (no chain).
 */
#[CoversClass(ArchivedRecipe::class)]
final class ArchivedRecipeTest extends TestCase
{
    private const WORKFLOW_ID = '01936fb2-0000-7000-8000-0000000000a0';

    public function test_getInputCount_reports_the_number_of_bundled_inputs(): void
    {
        // BuflcZvO — TS-parity introspection getter (ArchivedRecipe.inputCount). Archive
        // is terminal (no post-combine chain), so there is no getStepCount() — parity
        // with TS, which omits it.
        $archived = new ArchivedRecipe(
            [FileInput::path('report.pdf'), FileInput::path('hero.jpg'), FileInput::path('narration.mp3')],
        );
        self::assertSame(3, $archived->getInputCount());
    }

    public function test_archive_lowers_to_one_passthrough_src_per_input_plus_an_archive_job(): void
    {
        $archived = new ArchivedRecipe(
            [FileInput::path('report.pdf'), FileInput::path('hero.jpg'), FileInput::path('narration.mp3')],
            new ArchiveRecipeOptions(format: ArchiveFormat::Zip, folderStructure: 'by_job'),
        );

        $payload = $archived->toWorkflowPayload(['f0', 'f1', 'f2'], null);

        self::assertCount(4, $payload->jobs);
        for ($i = 0; $i < 3; $i++) {
            self::assertSame("src_{$i}", $payload->jobs[$i]->id);
            self::assertSame('passthrough', $payload->jobs[$i]->operations[0]->type);
            self::assertSame(['type' => 'upload', 'file_id' => "f{$i}"], $payload->jobs[$i]->source);
        }

        $archiveJob = $payload->jobs[3];
        self::assertSame('archive', $archiveJob->id);
        self::assertNotNull($archiveJob->inputs);
        self::assertCount(3, $archiveJob->inputs);
        self::assertSame(['type' => 'job_output', 'from' => 'src_0'], $archiveJob->inputs[0]['source']);

        self::assertCount(1, $archiveJob->operations);
        self::assertSame('archive', $archiveJob->operations[0]->type);
        self::assertSame(
            ['format' => 'zip', 'folder_structure' => 'by_job'],
            $archiveJob->operations[0]->options,
        );
    }

    public function test_archive_omits_options_when_none_set(): void
    {
        $archived = new ArchivedRecipe([FileInput::path('a.pdf'), FileInput::path('b.pdf')]);
        $payload = $archived->toWorkflowPayload(['f0', 'f1'], null);
        self::assertSame([], $payload->jobs[2]->operations[0]->options);
    }

    public function test_archive_accepts_a_raw_string_format(): void
    {
        // ArchiveRecipeOptions::$format is ArchiveFormat|string|null — the raw
        // string leg of wireArchiveOptions()'s ternary must reach the wire
        // verbatim (parity with the TS `format: 'tar.gz'` string form).
        $archived = new ArchivedRecipe(
            [FileInput::path('a.pdf'), FileInput::path('b.pdf')],
            new ArchiveRecipeOptions(format: 'tar.gz'),
        );
        $payload = $archived->toWorkflowPayload(['f0', 'f1'], null);
        self::assertSame(['format' => 'tar.gz'], $payload->jobs[2]->operations[0]->options);
    }

    public function test_callback_url_is_wired_into_the_payload(): void
    {
        $archived = new ArchivedRecipe([FileInput::path('a.pdf'), FileInput::path('b.pdf')]);
        $payload = $archived->toWorkflowPayload(['f0', 'f1'], 'https://example.com/cb');
        self::assertSame('https://example.com/cb', $payload->callbackUrl);
    }

    public function test_archive_must_be_the_first_op_on_files(): void
    {
        $recipe = new FilesRecipe(
            [FileInput::path('a.pdf'), FileInput::path('b.pdf')],
            [new RecipeStep('compress', ['optimize' => null])],
        );

        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/archive\(\) must be the first operation/');
        $recipe->archive();
    }

    public function test_archive_rejects_fewer_than_two_inputs_before_any_upload(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([], $captured));

        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/at least 2 inputs/');
        try {
            $client->files([FileInput::path('only.pdf')])->archive()->submit();
        } finally {
            self::assertSame([], $captured, 'no upload may fire when there are too few inputs');
        }
    }

    public function test_archive_rejects_more_than_fifty_inputs_before_any_upload(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([], $captured));
        $inputs = [];
        for ($i = 0; $i < 51; $i++) {
            $inputs[] = FileInput::path("f-{$i}.pdf");
        }

        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/at most 50 inputs/');
        try {
            $client->files($inputs)->archive()->submit();
        } finally {
            self::assertSame([], $captured, 'no upload may fire when there are too many inputs');
        }
    }

    // -----------------------------------------------------------------
    // xxy5Rlsy follow-up (Wi4OnaJE): run() reaches the shared
    // MultiInputUpload helper at runtime (the other tests are lowering-only +
    // count-guard submits). Mirrors the TS file-first-archive.test.ts.
    // -----------------------------------------------------------------

    public function test_run_creates_the_lowered_archive_dag_and_projects_only_the_archive_output(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse("event: workflow.completed\ndata: {\"status\":\"completed\"}\n\n"),
            $this->statusResponse('completed'),
            $this->archiveDownloadsResponse(),
        ], $captured);

        $client = $this->makeClient($http);
        // uploadId arm keeps the queue tight (no upload requests); the lowering +
        // projection is what this exercises.
        $result = $client->files([FileInput::uploadId('id0'), FileInput::uploadId('id1')])
            ->archive(new ArchiveRecipeOptions(format: ArchiveFormat::Zip))
            ->run();

        // The FIRST captured request is the workflow create — its body carries the
        // lowered archive DAG: a passthrough src job per input + a terminal
        // archive job consuming them.
        $body = \json_decode((string) $captured[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertStringContainsString('/api/workflows', (string) $captured[0]->getUri());
        self::assertSame('src_0', $body['jobs'][0]['id']);
        self::assertSame('src_1', $body['jobs'][1]['id']);
        self::assertSame('archive', $body['jobs'][2]['id']);
        self::assertSame(['type' => 'upload', 'file_id' => 'id0'], $body['jobs'][0]['source']);
        self::assertSame('archive', $body['jobs'][2]['operations'][0]['type']);

        // The RunResult projects ONLY the archive output — the src_* passthrough
        // downloads (raw uploads) are filtered out.
        self::assertSame('completed', $result->state);
        self::assertTrue($result->ok);
        self::assertSame(['bundle.zip'], \array_map(static fn ($a) => $a->filename, $result->artifacts));
        self::assertSame('https://signed.example.com/bundle.zip', $result->url);
    }

    public function test_run_mid_batch_timeout_message_names_the_archive_label(): void
    {
        // Pin the archive label noun the shared helper threads into its timeout
        // message. A mid-batch deadline (maxWait 1ms + a slow first upload over
        // two inputs) trips the `during {uploadsLabel} uploads` throw. Asserting
        // the MESSAGE (not the racy upload count) locks the distinguishing noun.
        $a = $this->tempFile('pdf');
        $b = $this->tempFile('pdf');
        $http = $this->slowFirstStubClient([$this->uploadResponse(), $this->uploadResponse()]);
        $client = $this->makeClient($http);
        try {
            $client->files([FileInput::path($a), FileInput::path($b)])
                ->archive()
                ->run(maxWait: 1);
            self::fail('expected GislTimeoutError');
        } catch (GislTimeoutError $e) {
            self::assertStringContainsString('archive', $e->getMessage());
        } finally {
            @\unlink($a);
            @\unlink($b);
        }
    }

    // -----------------------------------------------------------------

    private function makeClient(ClientInterface $http): GislErgonomicClient
    {
        $factory = new HttpFactory();
        return new GislErgonomicClient(
            config: new GislClientConfig(baseUrl: 'https://api.test.example.com', apiKey: 'test-api-key', multipartConcurrency: 1),
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
        );
    }

    /**
     * @param list<ResponseInterface> $queue
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
                    throw new \RuntimeException('Stub queue exhausted on ' . $request->getUri());
                }
                return $next;
            }
        };
    }

    /**
     * A PSR-18 stub that usleeps ~5ms on its FIRST request so a 1ms maxWait is
     * reliably blown DURING the upload loop — the mid-batch throw. Extra queued
     * responses are harmless (only exhaustion throws), and if the iteration-0
     * deadline check trips before any request the queue simply goes unused.
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
                    throw new \RuntimeException('Stub queue exhausted on ' . $request->getUri());
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
        return new Response($status, ['Content-Type' => 'application/json'], (string) \json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function uploadResponse(string $fileId = '01936fb1-7bb3-7000-8000-0000000060a1'): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['file_id' => $fileId, 'content_type' => 'application/pdf', 'size_bytes' => 2048],
        ]);
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

    private function statusResponse(string $status): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['workflow_id' => self::WORKFLOW_ID, 'status' => $status, 'jobs' => []],
        ]);
    }

    /**
     * Downloads carrying the src_* passthrough re-exposures of the raw uploads
     * ALONGSIDE the archive output, so run()'s `ref === 'archive'` filter is
     * genuinely exercised.
     */
    private function archiveDownloadsResponse(): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => [
                'downloads' => [
                    [
                        'job_id' => '01936fb3-0001-7000-8000-0000000060a1',
                        'ref' => 'src_0',
                        'files' => [[
                            'operation' => 'passthrough',
                            'operation_id' => '01936fb4-0001-7000-8000-0000000060a1',
                            'filename' => 'report.pdf',
                            'size_bytes' => 1,
                            'download_url' => 'https://signed.example.com/report.pdf',
                        ]],
                    ],
                    [
                        'job_id' => '01936fb3-0002-7000-8000-0000000060a2',
                        'ref' => 'src_1',
                        'files' => [[
                            'operation' => 'passthrough',
                            'operation_id' => '01936fb4-0002-7000-8000-0000000060a2',
                            'filename' => 'hero.jpg',
                            'size_bytes' => 1,
                            'download_url' => 'https://signed.example.com/hero.jpg',
                        ]],
                    ],
                    [
                        'job_id' => '01936fb3-0003-7000-8000-0000000060a3',
                        'ref' => 'archive',
                        'files' => [[
                            'operation' => 'archive',
                            'operation_id' => '01936fb4-0003-7000-8000-0000000060a3',
                            'filename' => 'bundle.zip',
                            'size_bytes' => 99,
                            'download_url' => 'https://signed.example.com/bundle.zip',
                        ]],
                    ],
                ],
            ],
        ]);
    }

    private function tempFile(string $ext): string
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'gisl_arc_');
        self::assertIsString($tmp);
        $path = $tmp . '.' . $ext;
        \rename($tmp, $path);
        \file_put_contents($path, \str_repeat('x', 64));
        return $path;
    }
}
