<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\FileFirst;

use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\ItemResult;
use Gisl\Sdk\FileFirst\OutputFile;
use Gisl\Sdk\FileFirst\Recipe;
use Gisl\Sdk\FileFirst\RunResult;
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
 * 9u4YGZ4V (ergonomic A) — surface the target-size encode outcome on the
 * file-first result. Mirrors the TS `file-first-target-size.test.ts`.
 *
 * Two load-bearing invariants are pinned here:
 *  1. **omit-when-null (parity-critical):** a non-target-size output stays
 *     byte-identical to the pre-feature `toArray()` — the two new keys are
 *     omitted, NOT emitted as `=> null`, so the TS omit-when-undefined
 *     `toJSON()` matches. The parity comparator filters null/undefined, so the
 *     explicit `array_keys` order assertions below are what actually catch a
 *     reordered / leaked projection.
 *  2. **`targetSizeMissed` = some(`targetSizeMet === false`)** with a `null`
 *     short-circuit when NO artifact reports an outcome.
 *
 * Covers the projection-target surface directly ({@see OutputFile} +
 * {@see RunResult}) and the static projection factories end-to-end over a
 * stubbed PSR-18 client: the single-job {@see RunResult::fromTerminalDownloads}
 * (via `Recipe::run()`) and the multi-job {@see RunResult::fromTerminalMultiJob}
 * (via `$client->files([...])->run()`).
 */
final class RunResultTargetSizeTest extends TestCase
{
    private const WORKFLOW_ID = '01936fb2-0000-7000-8000-0000000007e1';
    private const TERMINAL_SSE = "event: workflow.completed\ndata: {\"status\":\"completed\"}\n\n";

    private HttpFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    /** A bare {@see OutputFile}, with the target-size fields set only when supplied. */
    private function out(string $name, ?int $chosenQuality = null, ?bool $targetSizeMet = null): OutputFile
    {
        return new OutputFile(
            url: "https://cdn.example.com/{$name}",
            filename: $name,
            sizeBytes: 10,
            operation: 'compress',
            chosenQuality: $chosenQuality,
            targetSizeMet: $targetSizeMet,
        );
    }

    // -- 1. OutputFile projection: present / absent / independent / order ------

    #[Test]
    public function output_file_projects_chosen_quality_and_target_size_met_in_fixed_order(): void
    {
        $o = new OutputFile('https://x/a', 'a.webp', 20480, 'compress', 63, false);
        self::assertSame(63, $o->chosenQuality);
        self::assertFalse($o->targetSizeMet);
        self::assertSame(
            [
                'url' => 'https://x/a',
                'filename' => 'a.webp',
                'sizeBytes' => 20480,
                'operation' => 'compress',
                'chosenQuality' => 63,
                'targetSizeMet' => false,
            ],
            $o->toArray(),
        );
        // The two target-size keys come AFTER operation, chosenQuality then targetSizeMet.
        self::assertSame(
            ['url', 'filename', 'sizeBytes', 'operation', 'chosenQuality', 'targetSizeMet'],
            array_keys($o->toArray()),
        );
    }

    #[Test]
    public function output_file_omits_target_size_fields_when_absent(): void
    {
        // Parity-critical: a non-target-size output is byte-identical to the
        // pre-feature four-field shape.
        $o = new OutputFile('https://x/a', 'a.webp', 20480, 'compress');
        self::assertNull($o->chosenQuality);
        self::assertNull($o->targetSizeMet);
        self::assertSame(
            ['url' => 'https://x/a', 'filename' => 'a.webp', 'sizeBytes' => 20480, 'operation' => 'compress'],
            $o->toArray(),
        );
        self::assertSame(['url', 'filename', 'sizeBytes', 'operation'], array_keys($o->toArray()));
        self::assertArrayNotHasKey('chosenQuality', $o->toArray());
        self::assertArrayNotHasKey('targetSizeMet', $o->toArray());
    }

    #[Test]
    public function output_file_omits_each_target_size_field_independently(): void
    {
        // targetSizeMet present (true), chosenQuality null → only targetSizeMet emitted.
        $o = new OutputFile('https://x/a', 'a.webp', 20480, 'compress', null, true);
        self::assertSame(
            ['url' => 'https://x/a', 'filename' => 'a.webp', 'sizeBytes' => 20480, 'operation' => 'compress', 'targetSizeMet' => true],
            $o->toArray(),
        );
        self::assertArrayNotHasKey('chosenQuality', $o->toArray());
    }

    // -- 2/3. RunResult.targetSizeMissed derivation: true / false / null -------

    #[Test]
    public function target_size_missed_is_true_when_some_artifact_missed(): void
    {
        $r = new RunResult('wf1', 'completed', [$this->out('a.webp', 70, true), $this->out('b.webp', 30, false)], [], []);
        self::assertTrue($r->targetSizeMissed);
    }

    #[Test]
    public function target_size_missed_is_false_when_every_reporting_artifact_met_its_target(): void
    {
        $r = new RunResult('wf1', 'completed', [$this->out('a.webp', 70, true), $this->out('b.webp', 80, true)], [], []);
        self::assertFalse($r->targetSizeMissed);
    }

    #[Test]
    public function target_size_missed_is_null_when_no_artifact_reports_an_outcome(): void
    {
        $r = new RunResult('wf1', 'completed', [$this->out('a.webp'), $this->out('b.webp')], [], []);
        self::assertNull($r->targetSizeMissed);
    }

    #[Test]
    public function target_size_missed_is_null_for_a_zero_artifact_run(): void
    {
        self::assertNull((new RunResult('wf1', 'completed', [], [], []))->targetSizeMissed);
    }

    #[Test]
    public function target_size_missed_mixed_reporting_and_non_reporting_artifacts(): void
    {
        // some(=== false) ambiguity: a missed output beside a non-reporting one → missed wins.
        $missed = new RunResult('wf1', 'completed', [$this->out('a.webp', 30, false), $this->out('b.webp')], [], []);
        self::assertTrue($missed->targetSizeMissed);

        // A met output beside a non-reporting one → reported, none missed → false.
        $met = new RunResult('wf1', 'completed', [$this->out('a.webp', 70, true), $this->out('b.webp')], [], []);
        self::assertFalse($met->targetSizeMissed);
    }

    // -- 4. RunResult.toArray head order + golden shape ------------------------

    #[Test]
    public function to_array_emits_target_size_missed_after_ok_before_url_single_output(): void
    {
        $r = new RunResult(
            'wf1',
            'completed',
            [$this->out('a.webp', 30, false)],
            [new ItemResult(null, [$this->out('a.webp', 30, false)])],
            [],
        );
        self::assertTrue($r->targetSizeMissed);
        self::assertSame(
            ['workflowId', 'state', 'ok', 'targetSizeMissed', 'url', 'artifacts', 'succeeded', 'failed'],
            array_keys($r->toArray()),
        );
    }

    #[Test]
    public function to_array_emits_target_size_missed_with_no_url_multi_output(): void
    {
        $r = new RunResult(
            'wf1',
            'completed',
            [$this->out('a.webp', 30, false), $this->out('b.webp', 70, true)],
            [],
            [],
        );
        self::assertSame(
            ['workflowId', 'state', 'ok', 'targetSizeMissed', 'artifacts', 'succeeded', 'failed'],
            array_keys($r->toArray()),
        );
    }

    #[Test]
    public function to_array_omits_target_size_missed_for_a_non_target_size_run(): void
    {
        $r = new RunResult(
            'wf1',
            'completed',
            [$this->out('a.webp')],
            [new ItemResult(null, [$this->out('a.webp')])],
            [],
        );
        self::assertArrayNotHasKey('targetSizeMissed', $r->toArray());
        self::assertSame(
            ['workflowId', 'state', 'ok', 'url', 'artifacts', 'succeeded', 'failed'],
            array_keys($r->toArray()),
        );
    }

    #[Test]
    public function to_array_full_shape_with_target_size_metadata(): void
    {
        $r = new RunResult(
            'wf1',
            'completed',
            [$this->out('a.webp', 63, false)],
            [new ItemResult(null, [$this->out('a.webp', 63, false)])],
            [],
        );
        self::assertSame(
            [
                'workflowId' => 'wf1',
                'state' => 'completed',
                'ok' => true,
                'targetSizeMissed' => true,
                'url' => 'https://cdn.example.com/a.webp',
                'artifacts' => [
                    ['url' => 'https://cdn.example.com/a.webp', 'filename' => 'a.webp', 'sizeBytes' => 10, 'operation' => 'compress', 'chosenQuality' => 63, 'targetSizeMet' => false],
                ],
                'succeeded' => [
                    ['key' => null, 'outputs' => [
                        ['url' => 'https://cdn.example.com/a.webp', 'filename' => 'a.webp', 'sizeBytes' => 10, 'operation' => 'compress', 'chosenQuality' => 63, 'targetSizeMet' => false],
                    ]],
                ],
                'failed' => [],
            ],
            $r->toArray(),
        );
    }

    // -- 5. End-to-end single-job projection (fromTerminalDownloads) ----------

    #[Test]
    public function run_projects_target_size_metadata_off_the_download_single_job_mixed(): void
    {
        // ONE job, TWO outputs: the first is a missed target_size encode (honest
        // best-effort, NOT a failure → ok stays true), the second is a plain
        // compress output with no target-size metadata.
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse(self::TERMINAL_SSE),
            $this->statusResponse('completed'),
            $this->singleJobTargetSizeDownloads(),
        ]);
        $client = $this->makeClient($http);

        $result = (new Recipe(FileInput::uploadId('file_existing'), null, [], null, null, $client))
            ->compress()
            ->run();

        self::assertTrue($result->ok);
        self::assertCount(2, $result->artifacts);
        self::assertSame(41, $result->artifacts[0]->chosenQuality);
        self::assertFalse($result->artifacts[0]->targetSizeMet);
        self::assertNull($result->artifacts[1]->chosenQuality);
        self::assertNull($result->artifacts[1]->targetSizeMet);
        self::assertTrue($result->targetSizeMissed);

        $arr = $result->toArray();
        self::assertTrue($arr['targetSizeMissed']);
        // The reporting output carries both fields in order; the plain output omits them.
        self::assertSame(
            ['url', 'filename', 'sizeBytes', 'operation', 'chosenQuality', 'targetSizeMet'],
            array_keys($arr['artifacts'][0]),
        );
        self::assertSame(['url', 'filename', 'sizeBytes', 'operation'], array_keys($arr['artifacts'][1]));
    }

    #[Test]
    public function run_omits_target_size_fields_for_a_plain_compress_run(): void
    {
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse(self::TERMINAL_SSE),
            $this->statusResponse('completed'),
            $this->singleJobPlainDownloads(),
        ]);
        $client = $this->makeClient($http);

        $result = (new Recipe(FileInput::uploadId('file_existing'), null, [], null, null, $client))
            ->compress()
            ->run();

        self::assertNull($result->artifacts[0]->chosenQuality);
        self::assertNull($result->artifacts[0]->targetSizeMet);
        self::assertNull($result->targetSizeMissed);
        $arr = $result->toArray();
        self::assertArrayNotHasKey('targetSizeMissed', $arr);
        self::assertSame(
            ['url' => 'https://cdn.example.com/plain.webp', 'filename' => 'plain.webp', 'sizeBytes' => 10000, 'operation' => 'compress'],
            $arr['artifacts'][0],
        );
    }

    // -- 6. End-to-end multi-job fan-out projection (fromTerminalMultiJob) -----

    #[Test]
    public function files_fan_out_projects_target_size_per_job_mixed(): void
    {
        // file-0 missed its target; file-1 is a plain compress output (no metadata).
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse(self::TERMINAL_SSE),
            $this->multiJobStatusResponse('completed', [
                ['ref' => 'file-0', 'status' => 'completed'],
                ['ref' => 'file-1', 'status' => 'completed'],
            ]),
            $this->multiJobTargetSizeDownloads(),
        ]);
        $client = $this->makeClient($http);

        $result = $client->files([FileInput::uploadId('id0'), FileInput::uploadId('id1')])
            ->compress()
            ->run();

        self::assertSame(['0', '1'], array_map(static fn ($s) => $s->key, $result->succeeded));
        self::assertSame(35, $result->artifacts[0]->chosenQuality);
        self::assertFalse($result->artifacts[0]->targetSizeMet);
        self::assertNull($result->artifacts[1]->chosenQuality);
        self::assertNull($result->artifacts[1]->targetSizeMet);
        self::assertTrue($result->targetSizeMissed);
        // The per-input succeeded outputs carry the same projection as the flat artifacts.
        self::assertFalse($result->byKey('0')->outputs[0]->targetSizeMet);
        self::assertSame(35, $result->byKey('0')->outputs[0]->chosenQuality);
    }

    // ----------------------------------------------------------------------
    // Stub plumbing — mirrors RecipeRunTest / FilesRecipeTest.
    // ----------------------------------------------------------------------

    /**
     * @param list<ResponseInterface|\Throwable> $queue
     */
    private function stubClient(array $queue): ClientInterface
    {
        return new class ($queue) implements ClientInterface {
            /** @var list<ResponseInterface|\Throwable> */
            private array $queue;

            /** @param list<ResponseInterface|\Throwable> $queue */
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

    private function makeClient(ClientInterface $http): GislErgonomicClient
    {
        // files() lives on GislErgonomicClient; the single-file Recipe accepts it
        // too (GislErgonomicClient IS-A GislClient).
        return new GislErgonomicClient(
            config: new GislClientConfig(baseUrl: 'https://api.example.com', streamBaseUrl: 'https://stream.example.com', apiKey: 'sk_test'),
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
     * @param list<array<string, mixed>> $jobs
     */
    private function statusResponse(string $status, array $jobs = []): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['workflow_id' => self::WORKFLOW_ID, 'status' => $status, 'jobs' => $jobs],
        ]);
    }

    /**
     * @param list<array{ref: string, status: string}> $jobs
     */
    private function multiJobStatusResponse(string $status, array $jobs): ResponseInterface
    {
        $jobsWire = [];
        foreach ($jobs as $i => $job) {
            $jobsWire[] = [
                'job_id' => \sprintf('01936fb2-00%02d-7000-8000-0000000000%02d', $i + 2, $i + 2),
                'ref' => $job['ref'],
                'status' => $job['status'],
                'operations' => [],
            ];
        }
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['workflow_id' => self::WORKFLOW_ID, 'status' => $status, 'jobs' => $jobsWire],
        ]);
    }

    private function singleJobTargetSizeDownloads(): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => [
                'downloads' => [
                    [
                        'job_id' => '01936fb3-0001-7000-8000-0000000007e3',
                        'ref' => 'op',
                        'files' => [
                            [
                                'operation' => 'compress',
                                'operation_id' => '01936fb4-0001-7000-8000-0000000007e4',
                                'filename' => 'photo_target.webp',
                                'size_bytes' => 51200,
                                'download_url' => 'https://cdn.example.com/photo_target.webp',
                                'chosen_quality' => 41,
                                'target_size_met' => false,
                            ],
                            [
                                'operation' => 'compress',
                                'operation_id' => '01936fb4-0002-7000-8000-0000000007e5',
                                'filename' => 'photo_plain.webp',
                                'size_bytes' => 10000,
                                'download_url' => 'https://cdn.example.com/photo_plain.webp',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function singleJobPlainDownloads(): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => [
                'downloads' => [
                    [
                        'job_id' => '01936fb3-0001-7000-8000-0000000007e3',
                        'ref' => 'op',
                        'files' => [
                            [
                                'operation' => 'compress',
                                'operation_id' => '01936fb4-0001-7000-8000-0000000007e4',
                                'filename' => 'plain.webp',
                                'size_bytes' => 10000,
                                'download_url' => 'https://cdn.example.com/plain.webp',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function multiJobTargetSizeDownloads(): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => [
                'downloads' => [
                    [
                        'job_id' => '01936fb2-0002-7000-8000-000000000002',
                        'ref' => 'file-0',
                        'files' => [
                            [
                                'operation' => 'compress',
                                'operation_id' => '01936fb2-0104-7000-8000-000000000104',
                                'filename' => 'a.webp',
                                'size_bytes' => 30720,
                                'download_url' => 'https://cdn.example.com/a.webp',
                                'chosen_quality' => 35,
                                'target_size_met' => false,
                            ],
                        ],
                    ],
                    [
                        'job_id' => '01936fb2-0003-7000-8000-000000000003',
                        'ref' => 'file-1',
                        'files' => [
                            [
                                'operation' => 'compress',
                                'operation_id' => '01936fb2-0105-7000-8000-000000000105',
                                'filename' => 'b.webp',
                                'size_bytes' => 20480,
                                'download_url' => 'https://cdn.example.com/b.webp',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
