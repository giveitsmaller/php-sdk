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
 * bYOCX61m — a same-format compress that cannot make a file smaller returns the
 * ORIGINAL, marked `already_optimal` (+ `already_optimal_kind`) on its
 * `/downloads` entry (contracts v2.221.0). The file-first projection lands both
 * on each {@see OutputFile}. Mirrors the TS `file-first-already-optimal.test.ts`.
 *
 * Load-bearing invariants pinned here:
 *  1. **omit-when-null (parity-critical):** an output the API did not mark keeps
 *     the pre-feature `toArray()` shape — the two keys are omitted, not `=> null`.
 *  2. **`false` is a value, not an absence:** it is projected verbatim, like
 *     `targetSizeMet`, so the SDK does not erase "the API said no" into "the
 *     API said nothing" (the contract tells consumers to treat both the same).
 *  3. **field order:** the two keys come LAST, after the auto_quality keys.
 *  4. **an unknown kind is carried, not fatal:** a value this SDK predates
 *     reaches the caller verbatim and the run still succeeds (the generated
 *     setter would throw; drJpKvXS's tolerant deserialiser is what carries it).
 */
final class RunResultAlreadyOptimalTest extends TestCase
{
    private const WORKFLOW_ID = '01936fb2-0000-7000-8000-0000000009a1';
    private const TERMINAL_SSE = "event: workflow.completed\ndata: {\"status\":\"completed\"}\n\n";

    private HttpFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    // -- 1. OutputFile projection: present / false / absent / order ------------

    #[Test]
    public function output_file_projects_already_optimal_and_kind(): void
    {
        $o = new OutputFile('https://x/a', 'a.jpg', 51200, 'compress', alreadyOptimal: true, alreadyOptimalKind: 'not_smaller');
        self::assertTrue($o->alreadyOptimal);
        self::assertSame('not_smaller', $o->alreadyOptimalKind);
        self::assertSame(
            [
                'url' => 'https://x/a',
                'filename' => 'a.jpg',
                'sizeBytes' => 51200,
                'operation' => 'compress',
                'alreadyOptimal' => true,
                'alreadyOptimalKind' => 'not_smaller',
            ],
            $o->toArray(),
        );
    }

    #[Test]
    public function output_file_keeps_an_explicit_false(): void
    {
        $o = new OutputFile('https://x/a', 'a.jpg', 20480, 'compress', alreadyOptimal: false);
        self::assertFalse($o->alreadyOptimal);
        self::assertNull($o->alreadyOptimalKind);
        self::assertSame(
            ['url' => 'https://x/a', 'filename' => 'a.jpg', 'sizeBytes' => 20480, 'operation' => 'compress', 'alreadyOptimal' => false],
            $o->toArray(),
        );
    }

    #[Test]
    public function output_file_omits_both_keys_when_absent(): void
    {
        $o = new OutputFile('https://x/a', 'a.jpg', 20480, 'compress');
        self::assertNull($o->alreadyOptimal);
        self::assertNull($o->alreadyOptimalKind);
        self::assertSame(
            ['url' => 'https://x/a', 'filename' => 'a.jpg', 'sizeBytes' => 20480, 'operation' => 'compress'],
            $o->toArray(),
        );
    }

    #[Test]
    public function output_file_full_ten_key_order(): void
    {
        $o = new OutputFile('https://x/a', 'a.webp', 30720, 'compress', 63, true, 0.82, 'ssimulacra2', true, 'not_smaller');
        self::assertSame(
            [
                'url', 'filename', 'sizeBytes', 'operation',
                'chosenQuality', 'targetSizeMet', 'measuredQuality', 'qualityMetric',
                'alreadyOptimal', 'alreadyOptimalKind',
            ],
            array_keys($o->toArray()),
        );
    }

    // -- 2. End-to-end single-job projection off /downloads ------------------

    #[Test]
    public function run_projects_already_optimal_true_false_and_absent_single_job(): void
    {
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse(self::TERMINAL_SSE),
            $this->statusResponse(),
            $this->downloadsResponse([[
                'ref' => 'op',
                'files' => [
                    $this->file('original.jpg', 51200, ['already_optimal' => true, 'already_optimal_kind' => 'not_smaller']),
                    $this->file('smaller.jpg', 20480, ['already_optimal' => false]),
                    $this->file('plain.jpg', 10000, []),
                ],
            ]]),
        ]);

        $result = (new Recipe(FileInput::uploadId('file_existing'), null, [], null, null, $this->makeClient($http)))
            ->compress()
            ->run();

        self::assertTrue($result->ok);
        self::assertCount(3, $result->artifacts);
        self::assertTrue($result->artifacts[0]->alreadyOptimal);
        self::assertSame('not_smaller', $result->artifacts[0]->alreadyOptimalKind);
        self::assertFalse($result->artifacts[1]->alreadyOptimal);
        self::assertNull($result->artifacts[1]->alreadyOptimalKind);
        self::assertNull($result->artifacts[2]->alreadyOptimal);
        self::assertNull($result->artifacts[2]->alreadyOptimalKind);

        $arr = $result->toArray();
        self::assertSame(['url', 'filename', 'sizeBytes', 'operation', 'alreadyOptimal', 'alreadyOptimalKind'], array_keys($arr['artifacts'][0]));
        self::assertSame(['url', 'filename', 'sizeBytes', 'operation', 'alreadyOptimal'], array_keys($arr['artifacts'][1]));
        self::assertSame(['url', 'filename', 'sizeBytes', 'operation'], array_keys($arr['artifacts'][2]));
    }

    #[Test]
    public function an_unknown_kind_reaches_the_caller_verbatim_and_the_run_succeeds(): void
    {
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse(self::TERMINAL_SSE),
            $this->statusResponse(),
            $this->downloadsResponse([[
                'ref' => 'op',
                'files' => [
                    $this->file('original.jpg', 51200, ['already_optimal' => true, 'already_optimal_kind' => 'declined_efficient_source']),
                ],
            ]]),
        ]);

        $result = (new Recipe(FileInput::uploadId('file_existing'), null, [], null, null, $this->makeClient($http)))
            ->compress()
            ->run();

        self::assertTrue($result->ok);
        self::assertTrue($result->artifacts[0]->alreadyOptimal);
        self::assertSame('declined_efficient_source', $result->artifacts[0]->alreadyOptimalKind);
    }

    // -- 3. End-to-end multi-job fan-out projection ---------------------------

    #[Test]
    public function files_fan_out_projects_already_optimal_per_job(): void
    {
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse(self::TERMINAL_SSE),
            $this->multiJobStatusResponse(['file-0', 'file-1']),
            $this->downloadsResponse([
                ['ref' => 'file-0', 'files' => [$this->file('a.jpg', 51200, ['already_optimal' => true, 'already_optimal_kind' => 'not_smaller'])]],
                ['ref' => 'file-1', 'files' => [$this->file('b.jpg', 20480, [])]],
            ]),
        ]);

        $result = $this->makeClient($http)
            ->files([FileInput::uploadId('id0'), FileInput::uploadId('id1')])
            ->compress()
            ->run();

        self::assertSame(['0', '1'], array_map(static fn ($s) => $s->key, $result->succeeded));
        self::assertTrue($result->artifacts[0]->alreadyOptimal);
        self::assertSame('not_smaller', $result->artifacts[0]->alreadyOptimalKind);
        self::assertNull($result->artifacts[1]->alreadyOptimal);
        self::assertNull($result->artifacts[1]->alreadyOptimalKind);
        // The per-input succeeded outputs carry the same projection as the flat artifacts.
        self::assertTrue($result->byKey('0')->outputs[0]->alreadyOptimal);
        self::assertSame('not_smaller', $result->byKey('0')->outputs[0]->alreadyOptimalKind);
        self::assertNull($result->byKey('1')->outputs[0]->alreadyOptimal);
    }

    // ----------------------------------------------------------------------
    // Stub plumbing — mirrors RunResultMeasuredQualityTest.
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
            'operation_id' => '01936fb4-0001-7000-8000-0000000009a4',
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
