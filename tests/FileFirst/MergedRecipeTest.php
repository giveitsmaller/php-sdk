<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\FileFirst;

use Gisl\Sdk\Ergonomic\MergeOptions;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\FilesRecipe;
use Gisl\Sdk\FileFirst\MergedRecipe;
use Gisl\Sdk\FileFirst\RecipeStep;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
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
 * Fluent `files([...])->merge(...)` (FF3b). N→1 combine + post-combine chain.
 */
#[CoversClass(MergedRecipe::class)]
final class MergedRecipeTest extends TestCase
{
    public function test_getInputCount_and_getStepCount_report_the_recipe_shape(): void
    {
        // BuflcZvO — TS-parity introspection getters (MergedRecipe.inputCount/stepCount).
        $merged = new MergedRecipe(
            [FileInput::path('a.mp4'), FileInput::path('b.mp4'), FileInput::path('c.mp4')],
            new MergeOptions(mediaKind: 'video'),
        );
        self::assertSame(3, $merged->getInputCount());
        self::assertSame(0, $merged->getStepCount());

        $chained = $merged->compress()->convert('mp4');
        self::assertSame(3, $chained->getInputCount());
        self::assertSame(2, $chained->getStepCount());
    }

    public function test_merge_lowers_to_one_passthrough_src_per_input_plus_a_merge_job(): void
    {
        $merged = new MergedRecipe(
            [FileInput::path('intro.mp4'), FileInput::path('body.mp4'), FileInput::path('outro.mp4')],
            new MergeOptions(transition: 'crossfade', crossfadeDuration: 0.5, mediaKind: 'video'),
        );

        $payload = $merged->toWorkflowPayload(['f0', 'f1', 'f2'], null);

        // 3 passthrough source jobs + 1 merge job (last).
        self::assertCount(4, $payload->jobs);
        for ($i = 0; $i < 3; $i++) {
            self::assertSame("src_{$i}", $payload->jobs[$i]->id);
            self::assertSame('passthrough', $payload->jobs[$i]->operations[0]->type);
            self::assertSame(['type' => 'upload', 'file_id' => "f{$i}"], $payload->jobs[$i]->source);
        }

        $mergeJob = $payload->jobs[3];
        self::assertSame('merge', $mergeJob->id);
        // Merge job consumes the src jobs via job_output, in input order.
        self::assertNotNull($mergeJob->inputs);
        self::assertCount(3, $mergeJob->inputs);
        self::assertSame(['type' => 'job_output', 'from' => 'src_0'], $mergeJob->inputs[0]['source']);
        self::assertSame(['type' => 'job_output', 'from' => 'src_2'], $mergeJob->inputs[2]['source']);

        // Merge op first, options wired through MergeBuilder::wireMergeOptions.
        self::assertSame('merge', $mergeJob->operations[0]->type);
        $opts = $mergeJob->operations[0]->options;
        self::assertNotNull($opts);
        self::assertSame('crossfade', $opts['transition']);
        self::assertSame(0.5, $opts['crossfade_duration']);
    }

    public function test_merge_then_compress_appends_compress_after_merge_in_the_same_job(): void
    {
        // The flagship chain (example 14): merge N videos, then compress the
        // single merged output — compress lands AFTER merge in the merge job.
        $merged = (new MergedRecipe(
            [FileInput::path('a.mp4'), FileInput::path('b.mp4')],
            new MergeOptions(mediaKind: 'video'),
        ))->compress(OptimizeFor::Size);

        $payload = $merged->toWorkflowPayload(['f0', 'f1'], null);

        $mergeJob = $payload->jobs[2]; // 2 src jobs + merge
        self::assertSame('merge', $mergeJob->id);
        self::assertCount(2, $mergeJob->operations);
        self::assertSame('merge', $mergeJob->operations[0]->type);
        self::assertSame('compress', $mergeJob->operations[1]->type);
    }

    public function test_callback_url_is_wired_into_the_payload(): void
    {
        $merged = new MergedRecipe(
            [FileInput::path('a.mp4'), FileInput::path('b.mp4')],
            new MergeOptions(mediaKind: 'video'),
        );
        $payload = $merged->toWorkflowPayload(['f0', 'f1'], 'https://example.com/cb');
        self::assertSame('https://example.com/cb', $payload->callbackUrl);
    }

    public function test_merge_infers_audio_media_from_a_resource_content_type_hint(): void
    {
        // fFwaKsN5 (codex r1): merge media inference honours a resource's
        // contentType hint (MIME-first), so gap_duration (AUDIO-only on the wire)
        // survives wireMergeOptions instead of being dropped under the default
        // video kind. Mirrors the TS Blob branch of MergedRecipe.inferMediaKind.
        $s0 = \fopen('php://temp', 'r+b');
        $s1 = \fopen('php://temp', 'r+b');
        self::assertIsResource($s0);
        self::assertIsResource($s1);
        try {
            $merged = new MergedRecipe(
                [
                    FileInput::resource($s0, contentType: 'audio/mpeg'),
                    FileInput::resource($s1, contentType: 'audio/mpeg'),
                ],
                new MergeOptions(gapDuration: 1.5),
            );
            $payload = $merged->toWorkflowPayload(['f0', 'f1'], null);
            $opts = $payload->jobs[2]->operations[0]->options;
            self::assertNotNull($opts);
            self::assertSame(
                1.5,
                $opts['gap_duration'] ?? null,
                'audio inferred from the contentType hint → the audio-only gap_duration is kept',
            );
        } finally {
            \fclose($s0);
            \fclose($s1);
        }
    }

    public function test_merge_must_be_the_first_op_on_files(): void
    {
        // files([...])->compress()->merge() is rejected — per-file ops before a
        // combine are not yet supported.
        $recipe = new FilesRecipe(
            [FileInput::path('a.mp4'), FileInput::path('b.mp4')],
            [new RecipeStep('compress', ['optimize' => null])],
        );

        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/merge\(\) must be the first operation/');
        $recipe->merge();
    }

    public function test_merge_run_rejects_fewer_than_two_inputs_before_any_upload(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([], $captured));

        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/at least 2 inputs/');
        try {
            $client->files([FileInput::path('only.mp4')])->merge()->submit();
        } finally {
            self::assertSame([], $captured, 'no upload may fire when there are too few inputs');
        }
    }

    public function test_merge_rejects_more_than_ten_inputs_before_any_upload(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([], $captured));
        $inputs = [];
        for ($i = 0; $i < 11; $i++) {
            $inputs[] = FileInput::path("clip-{$i}.mp4");
        }

        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/at most 10 inputs/');
        try {
            $client->files($inputs)->merge()->submit();
        } finally {
            self::assertSame([], $captured, 'no upload may fire when there are too many inputs');
        }
    }

    public function test_merge_rejects_image_merge_without_output_type_before_any_upload(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([], $captured));

        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/output_type/');
        try {
            // image inferred from .jpg, but no output / outputType set.
            $client->files([FileInput::path('a.jpg'), FileInput::path('b.jpg')])->merge()->submit();
        } finally {
            self::assertSame([], $captured, 'no upload may fire when an image merge lacks output_type');
        }
    }

    public function test_run_mid_batch_timeout_message_names_the_merge_label(): void
    {
        // xxy5Rlsy follow-up (Wi4OnaJE): pin the merge label noun the shared
        // MultiInputUpload helper threads into its timeout message. A mid-batch
        // deadline (maxWait 1ms + a slow first upload over two inputs) trips the
        // `during {uploadsLabel} uploads` throw.
        $a = $this->tempFile('mp4');
        $b = $this->tempFile('mp4');
        $http = $this->slowFirstStubClient([$this->uploadResponse(), $this->uploadResponse()]);
        $client = $this->makeClient($http);
        try {
            $client->files([FileInput::path($a), FileInput::path($b)])
                ->merge()
                ->run(maxWait: 1);
            self::fail('expected GislTimeoutError');
        } catch (GislTimeoutError $e) {
            self::assertStringContainsString('merge', $e->getMessage());
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
                    throw new \RuntimeException('Stub queue exhausted on ' . $request->getUri());
                }
                return $next;
            }
        };
    }

    private function uploadResponse(string $fileId = '01936fb1-7bb3-7000-8000-0000000060d1'): ResponseInterface
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            (string) \json_encode([
                'success' => true,
                'data' => ['file_id' => $fileId, 'content_type' => 'video/mp4', 'size_bytes' => 2048],
            ], JSON_THROW_ON_ERROR),
        );
    }

    private function tempFile(string $ext): string
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'gisl_merge_');
        self::assertNotFalse($tmp);
        $path = $tmp . '.' . $ext;
        \rename($tmp, $path);
        \file_put_contents($path, \str_repeat('x', 64));
        return $path;
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
}
