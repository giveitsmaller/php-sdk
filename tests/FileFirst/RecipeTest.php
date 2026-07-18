<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\FileFirst;

use Gisl\Sdk\Ergonomic\PresetResolver;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\Recipe;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use Gisl\Sdk\Preset\ImageCompressPresetOptions;
use Gisl\Sdk\PresetDefaults;
use Gisl\Sdk\Tests\Unit\Ergonomic\GislErgonomicClientFactoryTestHelper;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * FF2a — the file-first {@see Recipe} builder: immutability (clone-on-write),
 * single-input op mapping, sequential-chain lowering to ONE job, and compress
 * preset delegation. Network-free: only the pure `toWorkflowPayload()` lowering
 * seam is exercised (no `run()` until FF2b).
 */
final class RecipeTest extends TestCase
{
    private const FILE_ID = 'file_0001';

    private function recipe(string $path, ?string $key = null): Recipe
    {
        return new Recipe(FileInput::path($path), $key);
    }

    /**
     * A no-I/O ergonomic client carrying client-scope preset defaults — the
     * HTTP transport throws if any request is issued (file()/lowering never
     * touch the wire).
     */
    private function clientWithPresetDefaults(PresetDefaults $defaults): GislErgonomicClient
    {
        $factory = new HttpFactory();
        $http = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new \LogicException('RecipeTest: lowering must not perform I/O.');
            }
        };

        return new GislErgonomicClient(
            config: new GislClientConfig(baseUrl: 'https://api.test', apiKey: 'sk_test'),
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
            presetDefaults: $defaults,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function loweredJob(Recipe $recipe): array
    {
        $wire = $recipe->toWorkflowPayload(self::FILE_ID)->toWire();
        self::assertIsArray($wire['jobs']);
        self::assertCount(1, $wire['jobs'], 'a single-input chain lowers to exactly one job');
        $job = $wire['jobs'][0];
        self::assertIsArray($job);

        return $job;
    }

    // --- Immutability (the headline AC) -------------------------------------

    #[Test]
    public function chaining_an_op_does_not_mutate_the_original_recipe(): void
    {
        $base = $this->recipe('photo.jpg');
        $compressed = $base->compress(OptimizeFor::Balanced);

        self::assertSame(0, $base->stepCount(), 'the base recipe is untouched');
        self::assertSame(1, $compressed->stepCount());
        self::assertNotSame($base, $compressed, 'each op returns a fresh Recipe');
    }

    #[Test]
    public function two_branches_off_one_base_are_independent(): void
    {
        $base = $this->recipe('clip.mov');
        $branchA = $base->convert('mp4')->compress(OptimizeFor::Size);
        $branchB = $base->thumbnail(['width' => 320, 'height' => 240]);

        self::assertSame(0, $base->stepCount());
        self::assertSame(2, $branchA->stepCount());
        self::assertSame(1, $branchB->stepCount());

        // Branch A's operations must not leak into branch B (aliasing trap).
        $opsA = $this->operations($branchA);
        $opsB = $this->operations($branchB);
        self::assertSame(['convert', 'compress'], array_column($opsA, 'type'));
        self::assertSame(['thumbnail'], array_column($opsB, 'type'));

        // Re-LOWER the base AFTER both branches are built — its operations[]
        // must still be empty. Asserting on the lowered wire (not just
        // stepCount, which reads the same field) catches a shared-array
        // mutation that corrupts contents without changing the count.
        self::assertSame([], $this->operations($base), 'the base recipe still lowers to zero operations');
    }

    // --- file() entry point -------------------------------------------------

    #[Test]
    public function client_file_returns_a_recipe_carrying_the_key(): void
    {
        $client = GislErgonomicClientFactoryTestHelper::client();
        $recipe = $client->file('photo.jpg', key: 'hero');

        self::assertInstanceOf(Recipe::class, $recipe);
        self::assertSame('hero', $recipe->key());
        self::assertSame(0, $recipe->stepCount());
    }

    #[Test]
    public function client_file_accepts_a_preuploaded_file_input(): void
    {
        $client = GislErgonomicClientFactoryTestHelper::client();
        $recipe = $client->file(FileInput::uploadId('uploaded-123'))->convert('webp');

        $job = $this->loweredJob($recipe);
        self::assertSame(['type' => 'upload', 'file_id' => self::FILE_ID], $job['source']);
    }

    #[Test]
    public function an_op_with_empty_options_omits_the_options_key(): void
    {
        // A compress on an upload-id input has no inferable media, so preset
        // resolution yields empty options — which must omit the `options` wire
        // key (not emit `[]`), matching the TS `undefined` → absent behaviour.
        $ops = $this->operations($this->recipe('photo')->compress());
        self::assertSame([['type' => 'compress']], $ops);
        self::assertArrayNotHasKey('options', $ops[0]);
    }

    // --- Single-op lowering -------------------------------------------------

    #[Test]
    public function convert_lowers_to_an_output_format_option(): void
    {
        $ops = $this->operations($this->recipe('clip.mov')->convert('mp4'));
        self::assertSame([['type' => 'convert', 'options' => ['output_format' => 'mp4']]], $ops);
    }

    #[Test]
    public function text_watermark_lowers_to_the_text_watermark_op(): void
    {
        $ops = $this->operations($this->recipe('photo.jpg')->textWatermark('PROOF'));
        self::assertSame([['type' => 'text_watermark', 'options' => ['text' => 'PROOF']]], $ops);
    }

    // --- Single-input sole_op split (IQc01rj0) ------------------------------

    #[Test]
    public function text_watermark_then_compress_splits_into_sole_op_plus_post_job(): void
    {
        $wire = $this->recipe('photo.jpg')->textWatermark('X')->compress(OptimizeFor::Size)
            ->toWorkflowPayload(self::FILE_ID)->toWire();
        self::assertIsArray($wire['jobs']);
        self::assertCount(2, $wire['jobs'], 'text_watermark (sole_op) + compress splits into two jobs');
        // text_watermark is sole_op: alone in its job, consuming the upload.
        self::assertSame('text_watermark', $wire['jobs'][0]['id']);
        self::assertSame(['type' => 'upload', 'file_id' => self::FILE_ID], $wire['jobs'][0]['source']);
        self::assertSame([['type' => 'text_watermark', 'options' => ['text' => 'X']]], $wire['jobs'][0]['operations']);
        // post steps consume the sole_op output via job_output (the hidden DAG).
        self::assertSame('post', $wire['jobs'][1]['id']);
        self::assertSame(['type' => 'job_output', 'from' => 'text_watermark'], $wire['jobs'][1]['source']);
        self::assertSame(['compress'], \array_column($wire['jobs'][1]['operations'], 'type'));
    }

    #[Test]
    public function text_watermark_alone_stays_a_single_job_no_split(): void
    {
        $wire = $this->recipe('photo.jpg')->textWatermark('X')->toWorkflowPayload(self::FILE_ID)->toWire();
        self::assertCount(1, $wire['jobs']);
        self::assertArrayNotHasKey('id', $wire['jobs'][0]);
        self::assertSame(['type' => 'upload', 'file_id' => self::FILE_ID], $wire['jobs'][0]['source']);
        self::assertSame([['type' => 'text_watermark', 'options' => ['text' => 'X']]], $wire['jobs'][0]['operations']);
    }

    #[Test]
    public function sole_op_mid_chain_splits_with_an_upstream_pre_job(): void
    {
        $wire = $this->recipe('photo.jpg')->compress(OptimizeFor::Size)->textWatermark('X')->convert('webp')
            ->toWorkflowPayload(self::FILE_ID)->toWire();
        self::assertCount(3, $wire['jobs']);
        self::assertSame('pre', $wire['jobs'][0]['id']);
        self::assertSame(['type' => 'upload', 'file_id' => self::FILE_ID], $wire['jobs'][0]['source']);
        self::assertSame(['compress'], \array_column($wire['jobs'][0]['operations'], 'type'));
        self::assertSame('text_watermark', $wire['jobs'][1]['id']);
        self::assertSame(['type' => 'job_output', 'from' => 'pre'], $wire['jobs'][1]['source']);
        self::assertSame('post', $wire['jobs'][2]['id']);
        self::assertSame(['type' => 'job_output', 'from' => 'text_watermark'], $wire['jobs'][2]['source']);
        self::assertSame(['convert'], \array_column($wire['jobs'][2]['operations'], 'type'));
    }

    #[Test]
    public function chaining_two_sole_op_ops_throws_multi_sole_op_unsupported(): void
    {
        try {
            $this->recipe('photo.jpg')->textWatermark('a')->textWatermark('b')->toWorkflowPayload(self::FILE_ID);
            self::fail('two sole_op ops must throw');
        } catch (GislConfigError $err) {
            self::assertSame('multi_sole_op_unsupported', $err->reason);
        }
    }

    #[Test]
    public function a_splitting_recipe_nested_as_a_watermark_base_fails_fast(): void
    {
        $wm = $this->recipe('photo.jpg')->compress(OptimizeFor::Size)->textWatermark('X')
            ->watermark($this->recipe('logo.png'));
        try {
            $wm->toWorkflowPayload([self::FILE_ID, 'file_overlay']);
            self::fail('a splitting watermark base must fail fast');
        } catch (GislConfigError $err) {
            self::assertSame('nested_sole_op_unsupported', $err->reason);
        }
    }

    #[Test]
    public function thumbnail_carries_both_dimensions(): void
    {
        $ops = $this->operations($this->recipe('photo.jpg')->thumbnail(['width' => 320, 'height' => 240]));
        self::assertSame([['type' => 'thumbnail', 'options' => ['width' => 320, 'height' => 240]]], $ops);
    }

    #[Test]
    public function thumbnail_rejects_a_missing_height(): void
    {
        // Dhje3Faq: both dimensions are contract-required; a width-only thumbnail
        // throws synchronously at the verb call (pre-upload), naming `height`.
        try {
            $this->recipe('photo.jpg')->thumbnail(['width' => 320]);
            self::fail('a width-only thumbnail must throw');
        } catch (GislConfigError $err) {
            self::assertSame('missing_required_field', $err->reason);
            self::assertNotNull($err->conflictingFields);
            self::assertContains('height', $err->conflictingFields);
        }
    }

    #[Test]
    public function thumbnail_rejects_a_missing_width(): void
    {
        try {
            $this->recipe('photo.jpg')->thumbnail(['height' => 240]);
            self::fail('a height-only thumbnail must throw');
        } catch (GislConfigError $err) {
            self::assertSame('missing_required_field', $err->reason);
            self::assertNotNull($err->conflictingFields);
            self::assertContains('width', $err->conflictingFields);
        }
    }

    // --- Job shape ----------------------------------------------------------

    #[Test]
    public function lowering_emits_one_job_with_upload_source_and_no_id(): void
    {
        $job = $this->loweredJob($this->recipe('photo.jpg')->convert('webp'));

        self::assertSame(['type' => 'upload', 'file_id' => self::FILE_ID], $job['source']);
        self::assertArrayNotHasKey('id', $job, 'a single referenced-by-nothing job omits id (server auto-assigns)');
        self::assertSame(['source', 'operations'], array_keys($job), 'wire key order: source before operations');
    }

    #[Test]
    public function a_chain_preserves_operation_order_in_one_job(): void
    {
        $ops = $this->operations($this->recipe('clip.mov')->convert('mp4')->thumbnail(['width' => 100, 'height' => 100]));
        self::assertSame(['convert', 'thumbnail'], array_column($ops, 'type'));
    }

    // --- compress preset delegation -----------------------------------------

    #[Test]
    public function compress_delegates_to_the_shared_preset_resolver(): void
    {
        // The Recipe must lower compress to EXACTLY what the operation-first
        // resolver produces for the same media + optimize — proving it reuses
        // the resolver rather than re-implementing preset logic.
        $expected = PresetResolver::resolveCompress(
            media: 'image',
            presetDefaults: null,
            scopedDefaults: null,
            presetOverrides: null,
            optimize: OptimizeFor::Balanced,
            explicitOptions: [],
        )['wireOptions'];

        $ops = $this->operations($this->recipe('photo.jpg')->compress(OptimizeFor::Balanced));
        self::assertSame('compress', $ops[0]['type']);
        self::assertSame($expected, $ops[0]['options']);
        self::assertNotEmpty($ops[0]['options'], 'a known-media compress resolves to concrete wire fields');
    }

    #[Test]
    public function compress_accepts_the_optimize_string_value(): void
    {
        $viaEnum = $this->operations($this->recipe('photo.jpg')->compress(OptimizeFor::Size));
        $viaString = $this->operations($this->recipe('photo.jpg')->compress('Size'));
        self::assertSame($viaEnum, $viaString);
    }

    #[Test]
    public function compress_rejects_an_unknown_optimize_string(): void
    {
        $this->expectException(GislConfigError::class);
        $this->recipe('photo.jpg')->compress('Smallest');
    }

    #[Test]
    public function compress_with_optimize_on_unknown_media_fails_fast(): void
    {
        // 'photo' has no extension → media cannot be inferred. An explicit
        // optimize must NOT be silently dropped — lowering throws.
        $this->expectException(GislConfigError::class);
        $this->recipe('photo')->compress(OptimizeFor::Size)->toWorkflowPayload(self::FILE_ID);
    }

    /**
     * @param 'image'|'audio'|'video'|'document_office'|'document_odf'|'document_epub' $media
     * @return array<string, mixed>
     */
    private function expectedCompressWire(string $media, OptimizeFor $optimize): array
    {
        return PresetResolver::resolveCompress(
            media: $media,
            presetDefaults: null,
            scopedDefaults: null,
            presetOverrides: null,
            optimize: $optimize,
            explicitOptions: [],
        )['wireOptions'];
    }

    #[Test]
    public function compress_with_optimize_on_a_resource_content_type_resolves_the_preset(): void
    {
        // fFwaKsN5: a resource carrying a contentType hint infers media (image),
        // so compress(optimize:) resolves the preset instead of failing
        // media_unknown — the ergonomic file-first path now works for in-memory
        // media without dropping to uploadFile() + FileInput::uploadId().
        $stream = \fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        try {
            $ops = $this->operations(
                (new Recipe(FileInput::resource($stream, contentType: 'image/jpeg')))->compress(OptimizeFor::Size),
            );
            self::assertSame('compress', $ops[0]['type']);
            self::assertSame($this->expectedCompressWire('image', OptimizeFor::Size), $ops[0]['options']);
        } finally {
            \fclose($stream);
        }
    }

    #[Test]
    public function compress_with_optimize_on_a_resource_filename_resolves_via_extension(): void
    {
        // No contentType → fall back to the filename hint's extension (video).
        $stream = \fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        try {
            $ops = $this->operations(
                (new Recipe(FileInput::resource($stream, filename: 'clip.mov')))->compress(OptimizeFor::Size),
            );
            self::assertSame($this->expectedCompressWire('video', OptimizeFor::Size), $ops[0]['options']);
        } finally {
            \fclose($stream);
        }
    }

    #[Test]
    public function compress_on_a_resource_prefers_content_type_over_the_filename(): void
    {
        // MIME is canonical (mirrors the TS Blob branch): an audio/* contentType
        // wins over a .mp4 (video) filename.
        $stream = \fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        try {
            $ops = $this->operations(
                (new Recipe(FileInput::resource($stream, filename: 'track.mp4', contentType: 'audio/mpeg')))
                    ->compress(OptimizeFor::Size),
            );
            self::assertSame($this->expectedCompressWire('audio', OptimizeFor::Size), $ops[0]['options']);
        } finally {
            \fclose($stream);
        }
    }

    #[Test]
    public function compress_with_optimize_on_a_hintless_resource_still_fails_fast(): void
    {
        // The existing media_unknown contract is PRESERVED for resources that
        // carry no filename/contentType — fail before any upload.
        $stream = \fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        try {
            $this->expectException(GislConfigError::class);
            (new Recipe(FileInput::resource($stream)))->compress(OptimizeFor::Size)->toWorkflowPayload(self::FILE_ID);
        } finally {
            \fclose($stream);
        }
    }

    #[Test]
    public function compress_uses_client_preset_defaults_via_file(): void
    {
        // `client->file()` must forward the client's preset defaults into the
        // Recipe so a file-first compress resolves with them — the constructor
        // arm the other tests (which use the bare helper) never exercise.
        $defaults = PresetDefaults::create()->imageCompress(
            OptimizeFor::Size,
            new ImageCompressPresetOptions(quality: 50),
        );
        $client = $this->clientWithPresetDefaults($defaults);

        $expected = PresetResolver::resolveCompress(
            media: 'image',
            presetDefaults: $defaults,
            scopedDefaults: null,
            presetOverrides: null,
            optimize: OptimizeFor::Size,
            explicitOptions: [],
        )['wireOptions'];

        $ops = $this->operations($client->file('photo.jpg')->compress(OptimizeFor::Size));
        self::assertSame('compress', $ops[0]['type']);
        self::assertSame($expected, $ops[0]['options']);
        self::assertSame(50, $ops[0]['options']['quality'] ?? null, 'the client default quality (50) won, not the shipped 65');
    }

    #[Test]
    public function compress_on_a_flac_path_lowers_without_bitrate(): void
    {
        // 0Vcogefw: a file-first compress on a lossless *.flac input drops the
        // shipped-preset bitrate (worker rejects it on flac/wav) while keeping
        // the rest of the audio Size cell. Proves the FILE-FIRST call-site is
        // wired — a regression here = only the operation-first site got fixed.
        $ops = $this->operations($this->recipe('track.flac')->compress(OptimizeFor::Size));
        self::assertSame('compress', $ops[0]['type']);
        $options = $ops[0]['options'];
        self::assertIsArray($options);
        self::assertArrayNotHasKey('bitrate', $options);
        self::assertSame(44100, $options['sample_rate']);
        self::assertArrayHasKey('normalize', $options);
    }

    #[Test]
    public function compress_on_an_mp3_path_keeps_the_bitrate(): void
    {
        // Lossy audio (mp3) keeps the shipped bitrate.
        $ops = $this->operations($this->recipe('song.mp3')->compress(OptimizeFor::Size));
        $options = $ops[0]['options'];
        self::assertIsArray($options);
        self::assertSame(96, $options['bitrate']);
        self::assertSame(44100, $options['sample_rate']);
    }

    #[Test]
    public function compress_on_a_flac_resource_content_type_lowers_without_bitrate(): void
    {
        // A resource whose contentType hint is a lossless audio MIME also
        // drops the bitrate (MIME-first, mirrors the TS Blob branch).
        $stream = \fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        try {
            $ops = $this->operations(
                (new Recipe(FileInput::resource($stream, contentType: 'audio/flac')))->compress(OptimizeFor::Size),
            );
            $options = $ops[0]['options'];
            self::assertIsArray($options);
            self::assertArrayNotHasKey('bitrate', $options);
            self::assertSame(44100, $options['sample_rate']);
        } finally {
            \fclose($stream);
        }
    }

    #[Test]
    public function lowering_serialises_to_a_byte_stable_json_string(): void
    {
        // Cross-language byte parity: this MUST equal the literal string the TS
        // test pins (file-first-recipe.test.ts) — same FILE_ID, same chain.
        $json = \json_encode($this->recipe('clip.mov')->convert('mp4')->toWorkflowPayload(self::FILE_ID)->toWire());
        self::assertSame(
            '{"jobs":[{"source":{"type":"upload","file_id":"file_0001"},"operations":[{"type":"convert","options":{"output_format":"mp4"}}]}]}',
            $json,
        );
    }

    #[Test]
    public function example_02_chain_lowers_convert_then_resolved_compress(): void
    {
        // examples/php/02-chain.php: clip.mov → convert(mp4) → compress(Size).
        $expectedCompress = PresetResolver::resolveCompress(
            media: 'video',
            presetDefaults: null,
            scopedDefaults: null,
            presetOverrides: null,
            optimize: OptimizeFor::Size,
            explicitOptions: [],
        )['wireOptions'];

        $ops = $this->operations(
            $this->recipe('clip.mov')->convert('mp4')->compress(OptimizeFor::Size),
        );

        self::assertSame(['convert', 'compress'], array_column($ops, 'type'));
        self::assertSame(['output_format' => 'mp4'], $ops[0]['options']);
        self::assertSame($expectedCompress, $ops[1]['options']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function operations(Recipe $recipe): array
    {
        $job = $this->loweredJob($recipe);
        self::assertIsArray($job['operations']);

        /** @var list<array<string, mixed>> $operations */
        $operations = $job['operations'];

        return $operations;
    }
}
