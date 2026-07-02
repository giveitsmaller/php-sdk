<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\FileFirst;

use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\OutputFile;
use Gisl\Sdk\FileFirst\Recipe;
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
 * pAVd5oC4 — surface the auto_quality metrics on the file-first result. Mirrors
 * the TS `file-first-measured-quality.test.ts`.
 *
 * Load-bearing invariants pinned here:
 *  1. **omit-when-null (parity-critical):** an output the worker did not measure
 *     stays byte-identical to the pre-feature `toArray()` — the two new keys are
 *     omitted, NOT emitted as `=> null`, so the TS omit-when-undefined `toJSON()`
 *     matches. The parity comparator filters null/undefined, so the explicit
 *     `array_keys` order assertions below are what actually catch a reordered /
 *     leaked projection.
 *  2. **independent omission:** the two `if`-blocks are separate, so an output
 *     carrying only one field serialises only that field.
 *  3. **field order:** the quality keys come AFTER `operation` (and after the
 *     target-size keys when those are also present).
 *
 * Covers the projection-target surface directly ({@see OutputFile}) and the
 * static projection factories end-to-end over a stubbed PSR-18 client: the
 * single-job path (via `Recipe::run()`) and the multi-job fan-out (via
 * `$client->files([...])->run()`).
 */
final class RunResultMeasuredQualityTest extends TestCase
{
    private const WORKFLOW_ID = '01936fb2-0000-7000-8000-0000000008a1';
    private const TERMINAL_SSE = "event: workflow.completed\ndata: {\"status\":\"completed\"}\n\n";

    private HttpFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    // -- 1. OutputFile projection: present / absent / independent / order ------

    #[Test]
    public function output_file_projects_measured_quality_and_quality_metric_in_fixed_order(): void
    {
        $o = new OutputFile('https://x/a', 'a.webp', 30720, 'compress', measuredQuality: 0.82, qualityMetric: 'ssimulacra2');
        self::assertSame(0.82, $o->measuredQuality);
        self::assertSame('ssimulacra2', $o->qualityMetric);
        self::assertSame(
            [
                'url' => 'https://x/a',
                'filename' => 'a.webp',
                'sizeBytes' => 30720,
                'operation' => 'compress',
                'measuredQuality' => 0.82,
                'qualityMetric' => 'ssimulacra2',
            ],
            $o->toArray(),
        );
        // The two quality keys come AFTER operation, measuredQuality then qualityMetric.
        self::assertSame(
            ['url', 'filename', 'sizeBytes', 'operation', 'measuredQuality', 'qualityMetric'],
            array_keys($o->toArray()),
        );
    }

    #[Test]
    public function output_file_omits_auto_quality_fields_when_absent(): void
    {
        // Parity-critical: a non-measured output is byte-identical to the
        // pre-feature four-field shape.
        $o = new OutputFile('https://x/a', 'a.webp', 20480, 'compress');
        self::assertNull($o->measuredQuality);
        self::assertNull($o->qualityMetric);
        self::assertSame(
            ['url' => 'https://x/a', 'filename' => 'a.webp', 'sizeBytes' => 20480, 'operation' => 'compress'],
            $o->toArray(),
        );
        self::assertArrayNotHasKey('measuredQuality', $o->toArray());
        self::assertArrayNotHasKey('qualityMetric', $o->toArray());
    }

    #[Test]
    public function output_file_omits_each_auto_quality_field_independently(): void
    {
        // measuredQuality present, qualityMetric null → only measuredQuality emitted.
        $onlyMeasured = new OutputFile('https://x/a', 'a.webp', 20480, 'compress', measuredQuality: 0.9);
        self::assertSame(
            ['url' => 'https://x/a', 'filename' => 'a.webp', 'sizeBytes' => 20480, 'operation' => 'compress', 'measuredQuality' => 0.9],
            $onlyMeasured->toArray(),
        );
        self::assertArrayNotHasKey('qualityMetric', $onlyMeasured->toArray());

        // qualityMetric present, measuredQuality null → only qualityMetric emitted.
        $onlyMetric = new OutputFile('https://x/b', 'b.webp', 20480, 'compress', qualityMetric: 'ssimulacra2');
        self::assertSame(
            ['url' => 'https://x/b', 'filename' => 'b.webp', 'sizeBytes' => 20480, 'operation' => 'compress', 'qualityMetric' => 'ssimulacra2'],
            $onlyMetric->toArray(),
        );
        self::assertArrayNotHasKey('measuredQuality', $onlyMetric->toArray());
    }

    #[Test]
    public function output_file_full_eight_key_order_with_target_size_and_quality(): void
    {
        // All four projected fields present: target-size keys precede the
        // auto_quality keys (emission order chosenQuality→targetSizeMet→
        // measuredQuality→qualityMetric, all after operation).
        $o = new OutputFile('https://x/a', 'a.webp', 30720, 'compress', 63, true, 0.82, 'ssimulacra2');
        self::assertSame(
            ['url', 'filename', 'sizeBytes', 'operation', 'chosenQuality', 'targetSizeMet', 'measuredQuality', 'qualityMetric'],
            array_keys($o->toArray()),
        );
    }

    // -- 2. End-to-end single-job projection off the download -----------------

    #[Test]
    public function run_projects_auto_quality_off_the_download_single_job_mixed(): void
    {
        // ONE job, TWO outputs: the first carries the auto_quality metrics, the
        // second is a plain compress output with no measurement.
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse(self::TERMINAL_SSE),
            $this->statusResponse('completed'),
            $this->singleJobAutoQualityDownloads(),
        ]);
        $client = $this->makeClient($http);

        $result = (new Recipe(FileInput::uploadId('file_existing'), null, [], null, null, $client))
            ->compress()
            ->run();

        self::assertTrue($result->ok);
        self::assertCount(2, $result->artifacts);
        self::assertSame(0.82, $result->artifacts[0]->measuredQuality);
        self::assertSame('ssimulacra2', $result->artifacts[0]->qualityMetric);
        self::assertNull($result->artifacts[1]->measuredQuality);
        self::assertNull($result->artifacts[1]->qualityMetric);

        $arr = $result->toArray();
        // The measured output carries both fields in order; the plain output omits them.
        self::assertSame(
            ['url', 'filename', 'sizeBytes', 'operation', 'measuredQuality', 'qualityMetric'],
            array_keys($arr['artifacts'][0]),
        );
        self::assertSame(['url', 'filename', 'sizeBytes', 'operation'], array_keys($arr['artifacts'][1]));
    }

    // -- 3. End-to-end multi-job fan-out projection ---------------------------

    #[Test]
    public function files_fan_out_projects_auto_quality_per_job_mixed(): void
    {
        // file-0 carries the auto_quality metrics; file-1 is a plain compress
        // output (no measurement).
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse(self::TERMINAL_SSE),
            $this->multiJobStatusResponse('completed', [
                ['ref' => 'file-0', 'status' => 'completed'],
                ['ref' => 'file-1', 'status' => 'completed'],
            ]),
            $this->multiJobAutoQualityDownloads(),
        ]);
        $client = $this->makeClient($http);

        $result = $client->files([FileInput::uploadId('id0'), FileInput::uploadId('id1')])
            ->compress()
            ->run();

        self::assertSame(['0', '1'], array_map(static fn ($s) => $s->key, $result->succeeded));
        self::assertSame(0.82, $result->artifacts[0]->measuredQuality);
        self::assertSame('ssimulacra2', $result->artifacts[0]->qualityMetric);
        self::assertNull($result->artifacts[1]->measuredQuality);
        self::assertNull($result->artifacts[1]->qualityMetric);
        // The per-input succeeded outputs carry the same projection as the flat artifacts.
        self::assertSame(0.82, $result->byKey('0')->outputs[0]->measuredQuality);
        self::assertSame('ssimulacra2', $result->byKey('0')->outputs[0]->qualityMetric);
    }

    // ----------------------------------------------------------------------
    // Stub plumbing — mirrors RunResultTargetSizeTest.
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

    private function singleJobAutoQualityDownloads(): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => [
                'downloads' => [
                    [
                        'job_id' => '01936fb3-0001-7000-8000-0000000008a3',
                        'ref' => 'op',
                        'files' => [
                            [
                                'operation' => 'compress',
                                'operation_id' => '01936fb4-0001-7000-8000-0000000008a4',
                                'filename' => 'photo_measured.webp',
                                'size_bytes' => 30720,
                                'download_url' => 'https://cdn.example.com/photo_measured.webp',
                                'measured_quality' => 0.82,
                                'quality_metric' => 'ssimulacra2',
                            ],
                            [
                                'operation' => 'compress',
                                'operation_id' => '01936fb4-0002-7000-8000-0000000008a5',
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

    private function multiJobAutoQualityDownloads(): ResponseInterface
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
                                'measured_quality' => 0.82,
                                'quality_metric' => 'ssimulacra2',
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
