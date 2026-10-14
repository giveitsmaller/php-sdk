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
 * aRmwBrLX — an indexed output (a page of a PDF->image `convert`, a page group
 * of a `split`, or one segment of an audio/video split) carries `page_index` or
 * `position` on its `/downloads` entry (ADR-0009 §D2). The file-first
 * projection lands them on each {@see OutputFile} as `pageIndex` / `position`.
 * Mirrors the TS `file-first-page-index.test.ts`.
 *
 * Load-bearing invariants pinned here:
 *  1. **omit-when-null (parity-critical):** an output without either field keeps
 *     the pre-feature `toArray()` shape, byte-identical — no `=> null` keys.
 *  2. **`position: 0` is a value, not an absence:** the first segment's
 *     ordinal is kept, on the object and in `toArray()`.
 *  3. **field order:** the two keys come LAST, after `alreadyOptimalKind`.
 *  4. **both producers project them:** single-job and multi-job fan-out share
 *     one projector, so neither can miss a field the other carries.
 */
final class RunResultPageIndexTest extends TestCase
{
    private const WORKFLOW_ID = '01936fb2-0000-7000-8000-0000000009b1';
    private const TERMINAL_SSE = "event: workflow.completed\ndata: {\"status\":\"completed\"}\n\n";

    private HttpFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    // -- 1. OutputFile projection: order / zero / absent ----------------------

    #[Test]
    public function output_file_emits_page_index_and_position_after_already_optimal_kind(): void
    {
        $o = new OutputFile(
            'https://x/a',
            'a.png',
            1024,
            'convert',
            63,
            true,
            0.82,
            'ssimulacra2',
            true,
            'not_smaller',
            pageIndex: 3,
            position: 2,
        );
        self::assertSame(
            [
                'url', 'filename', 'sizeBytes', 'operation',
                'chosenQuality', 'targetSizeMet', 'measuredQuality', 'qualityMetric',
                'alreadyOptimal', 'alreadyOptimalKind',
                'pageIndex', 'position',
            ],
            array_keys($o->toArray()),
        );
        self::assertSame(3, $o->toArray()['pageIndex'] ?? null);
        self::assertSame(2, $o->toArray()['position'] ?? null);
    }

    #[Test]
    public function output_file_keeps_position_zero(): void
    {
        $o = new OutputFile('https://x/s0', 'seg0.mp3', 2048, 'split', position: 0);
        self::assertSame(0, $o->position);
        self::assertNull($o->pageIndex);
        self::assertSame(
            ['url' => 'https://x/s0', 'filename' => 'seg0.mp3', 'sizeBytes' => 2048, 'operation' => 'split', 'position' => 0],
            $o->toArray(),
        );
    }

    #[Test]
    public function output_file_without_either_field_is_byte_identical_to_the_pre_feature_shape(): void
    {
        $o = new OutputFile('https://x/a', 'a.jpg', 20480, 'compress');
        self::assertNull($o->pageIndex);
        self::assertNull($o->position);
        self::assertSame(
            '{"url":"https:\/\/x\/a","filename":"a.jpg","sizeBytes":20480,"operation":"compress"}',
            \json_encode($o->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    // -- 2. End-to-end single-job projection off /downloads ------------------

    #[Test]
    public function run_projects_page_index_and_position_single_job(): void
    {
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse(self::TERMINAL_SSE),
            $this->statusResponse(),
            $this->downloadsResponse([[
                'ref' => 'op',
                'files' => [
                    $this->file('page-1.png', 1000, ['page_index' => 1]),
                    $this->file('page-8.png', 1000, ['page_index' => 8]),
                    $this->file('seg0.mp3', 2000, ['position' => 0]),
                    $this->file('seg1.mp3', 2000, ['position' => 1]),
                    $this->file('plain.jpg', 3000, []),
                ],
            ]]),
        ]);

        $result = (new Recipe(FileInput::uploadId('file_existing'), null, [], null, null, $this->makeClient($http)))
            ->compress()
            ->run();

        self::assertTrue($result->ok);
        self::assertCount(5, $result->artifacts);
        self::assertSame([1, 8, null, null, null], array_map(static fn (OutputFile $o) => $o->pageIndex, $result->artifacts));
        self::assertSame([null, null, 0, 1, null], array_map(static fn (OutputFile $o) => $o->position, $result->artifacts));
        // The per-input succeeded outputs carry the same projection as the flat artifacts.
        self::assertSame([1, 8, null, null, null], array_map(static fn (OutputFile $o) => $o->pageIndex, $result->succeeded[0]->outputs));
        self::assertSame([null, null, 0, 1, null], array_map(static fn (OutputFile $o) => $o->position, $result->succeeded[0]->outputs));

        $arr = $result->toArray();
        self::assertSame(['url', 'filename', 'sizeBytes', 'operation', 'pageIndex'], array_keys($arr['artifacts'][0]));
        self::assertSame(8, $arr['artifacts'][1]['pageIndex'] ?? null);
        self::assertSame(['url', 'filename', 'sizeBytes', 'operation', 'position'], array_keys($arr['artifacts'][2]));
        self::assertSame(0, $arr['artifacts'][2]['position'] ?? null);
        self::assertSame(['url', 'filename', 'sizeBytes', 'operation'], array_keys($arr['artifacts'][4]));
    }

    #[Test]
    public function run_without_either_field_projects_no_keys(): void
    {
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse(self::TERMINAL_SSE),
            $this->statusResponse(),
            $this->downloadsResponse([[
                'ref' => 'op',
                'files' => [$this->file('plain.jpg', 3000, [])],
            ]]),
        ]);

        $result = (new Recipe(FileInput::uploadId('file_existing'), null, [], null, null, $this->makeClient($http)))
            ->compress()
            ->run();

        self::assertNull($result->artifacts[0]->pageIndex);
        self::assertNull($result->artifacts[0]->position);
        self::assertSame(
            ['url' => 'https://cdn.example.com/plain.jpg', 'filename' => 'plain.jpg', 'sizeBytes' => 3000, 'operation' => 'compress'],
            $result->toArray()['artifacts'][0],
        );
    }

    // -- 3. End-to-end multi-job fan-out projection ---------------------------

    #[Test]
    public function files_fan_out_projects_page_index_and_position_per_job(): void
    {
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse(self::TERMINAL_SSE),
            $this->multiJobStatusResponse(['file-0', 'file-1']),
            $this->downloadsResponse([
                ['ref' => 'file-0', 'files' => [
                    $this->file('a-p3.png', 1000, ['page_index' => 3]),
                    $this->file('a-s0.mp4', 2000, ['position' => 0]),
                ]],
                ['ref' => 'file-1', 'files' => [$this->file('b.jpg', 3000, [])]],
            ]),
        ]);

        $result = $this->makeClient($http)
            ->files([FileInput::uploadId('id0'), FileInput::uploadId('id1')])
            ->compress()
            ->run();

        self::assertSame(['0', '1'], array_map(static fn ($s) => $s->key, $result->succeeded));
        self::assertSame(3, $result->artifacts[0]->pageIndex);
        self::assertNull($result->artifacts[0]->position);
        self::assertSame(0, $result->artifacts[1]->position);
        self::assertNull($result->artifacts[1]->pageIndex);
        self::assertNull($result->artifacts[2]->pageIndex);
        self::assertNull($result->artifacts[2]->position);
        self::assertSame(3, $result->byKey('0')->outputs[0]->pageIndex);
        self::assertSame(0, $result->byKey('0')->outputs[1]->position);
        self::assertNull($result->byKey('1')->outputs[0]->pageIndex);

        $arr = $result->toArray();
        self::assertSame(['url', 'filename', 'sizeBytes', 'operation', 'position'], array_keys($arr['succeeded'][0]['outputs'][1]));
        self::assertSame(['url', 'filename', 'sizeBytes', 'operation'], array_keys($arr['succeeded'][1]['outputs'][0]));
    }

    // ----------------------------------------------------------------------
    // Stub plumbing — mirrors RunResultAlreadyOptimalTest.
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

    private function statusResponse(): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['workflow_id' => self::WORKFLOW_ID, 'status' => 'completed', 'jobs' => []],
        ]);
    }

    /**
     * @param list<string> $refs
     */
    private function multiJobStatusResponse(array $refs): ResponseInterface
    {
        $jobsWire = [];
        foreach ($refs as $i => $ref) {
            $jobsWire[] = [
                'job_id' => \sprintf('01936fb2-00%02d-7000-8000-0000000000%02d', $i + 2, $i + 2),
                'ref' => $ref,
                'status' => 'completed',
                'operations' => [],
            ];
        }
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['workflow_id' => self::WORKFLOW_ID, 'status' => 'completed', 'jobs' => $jobsWire],
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function file(string $filename, int $sizeBytes, array $extra): array
    {
        return [
            'operation' => 'compress',
            'operation_id' => '01936fb4-0001-7000-8000-0000000009b4',
            'filename' => $filename,
            'size_bytes' => $sizeBytes,
            'download_url' => 'https://cdn.example.com/' . $filename,
        ] + $extra;
    }

    /**
     * @param list<array{ref: string, files: list<array<string, mixed>>}> $jobs
     */
    private function downloadsResponse(array $jobs): ResponseInterface
    {
        $downloads = [];
        foreach ($jobs as $i => $job) {
            $downloads[] = [
                'job_id' => \sprintf('01936fb2-00%02d-7000-8000-0000000000%02d', $i + 2, $i + 2),
                'ref' => $job['ref'],
                'files' => $job['files'],
            ];
        }
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['downloads' => $downloads],
        ]);
    }
}
