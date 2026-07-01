<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Ergonomic;

use Gisl\Sdk\Ergonomic\Artifact;
use Gisl\Sdk\Ergonomic\ClipOptions;
use Gisl\Sdk\Ergonomic\Handle;
use Gisl\Sdk\Ergonomic\Merge;
use Gisl\Sdk\Ergonomic\MergeBuilder;
use Gisl\Sdk\Ergonomic\MergeOptions;
use Gisl\Sdk\Ergonomic\PathAsset;
use Gisl\Sdk\Ergonomic\Result;
use Gisl\Sdk\Ergonomic\RunOptions;
use Gisl\Sdk\Ergonomic\SubmitOptions;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislPerInputOptionsNotSupportedError;
use Gisl\Sdk\Errors\GislUndeclaredAssetError;
use Gisl\Sdk\Errors\GislUnusedAssetError;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(MergeBuilder::class)]
#[CoversClass(GislUndeclaredAssetError::class)]
#[CoversClass(GislUnusedAssetError::class)]
#[CoversClass(GislPerInputOptionsNotSupportedError::class)]
final class MergeBuilderTest extends TestCase
{
    public function test_submit_uploads_each_unique_asset_once_then_creates_workflow(): void
    {
        $pathA = self::writeTempFile('aaaa');
        $pathB = self::writeTempFile('bbbb');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-00000000aa01', 'a.mp4', 'video/mp4', 4),
            self::uploadResponse('01936fb1-7bb3-7000-8000-00000000aa02', 'b.mp4', 'video/mp4', 4),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-00000000aa03'),
        ], $captured);

        $client = self::makeClient($http);
        $handle = $client
            ->merge([$pathA, $pathB], new MergeOptions(mediaKind: 'video'))
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $this->assertInstanceOf(Handle::class, $handle);
        $this->assertSame('01936fb2-0000-7000-8000-00000000aa03', $handle->workflowId);
        // FF5a back-compat: submit() now returns the enriched Handle CLASS, but
        // its toArray() shape must stay byte-identical to {workflowId,
        // webhookSecret} — no `client` leakage, no extra keys.
        $this->assertSame(
            [
                'workflowId' => '01936fb2-0000-7000-8000-00000000aa03',
                'webhookSecret' => 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789',
            ],
            $handle->toArray(),
        );
        $this->assertArrayNotHasKey('client', $handle->toArray());

        // 2 uploads + 1 workflow create.
        $this->assertCount(3, $captured);
        $this->assertStringContainsString('/api/uploads', (string) $captured[0]->getUri());
        $this->assertStringContainsString('/api/uploads', (string) $captured[1]->getUri());
        $this->assertStringContainsString('/api/workflows', (string) $captured[2]->getUri());

        $body = self::decodeJson($captured[2]);
        $this->assertSame('https://example.com/cb', $body['callback_url']);
        // p0SuJEeK — 2 unique assets → 2 passthrough src jobs + merge job last.
        $mergeJob = $this->assertMergeShapeAndReturnMergeJob($body, 2);
        $this->assertCount(2, $mergeJob['inputs']);
        // Each merge input references its source job; the uploaded file_ids
        // now live on the src jobs, not the merge inputs.
        $this->assertSame('src_0', $mergeJob['inputs'][0]['source']['from']);
        $this->assertSame('src_1', $mergeJob['inputs'][1]['source']['from']);
        $this->assertSame('01936fb1-7bb3-7000-8000-00000000aa01', $body['jobs'][0]['source']['file_id']);
        $this->assertSame('01936fb1-7bb3-7000-8000-00000000aa02', $body['jobs'][1]['source']['file_id']);
        $this->assertArrayNotHasKey('per_input_options', $mergeJob['inputs'][0]);
        $this->assertArrayNotHasKey('per_input_options', $mergeJob['inputs'][1]);
    }

    public function test_merge_submit_uploads_seekable_resource_assets(): void
    {
        // VOxtu0RZ-B4: Merge::resource() wraps a seekable stream as a merge
        // asset; each is uploaded just like a path.
        $streamA = \fopen('php://temp', 'r+b');
        $streamB = \fopen('php://temp', 'r+b');
        self::assertNotFalse($streamA);
        self::assertNotFalse($streamB);
        \fwrite($streamA, 'aaaa');
        \fwrite($streamB, 'bbbb');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000e1', 'upload.bin', 'video/mp4', 4),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000e2', 'upload.bin', 'video/mp4', 4),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-0000000000e3'),
        ], $captured);

        $client = self::makeClient($http);
        try {
            $handle = $client
                ->merge([Merge::resource($streamA), Merge::resource($streamB)], new MergeOptions(mediaKind: 'video'))
                ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));
        } finally {
            \fclose($streamA);
            \fclose($streamB);
        }

        $this->assertSame('01936fb2-0000-7000-8000-0000000000e3', $handle->workflowId);
        // 2 distinct resource handles → 2 uploads + 1 workflow create.
        $this->assertCount(3, $captured);
        $this->assertStringContainsString('/api/uploads', (string) $captured[0]->getUri());
        $this->assertStringContainsString('/api/uploads', (string) $captured[1]->getUri());
        $this->assertStringContainsString('/api/workflows', (string) $captured[2]->getUri());
        // The stream bytes crossed the wire.
        $this->assertStringContainsString('aaaa', (string) $captured[0]->getBody());
        $this->assertStringContainsString('bbbb', (string) $captured[1]->getBody());
    }

    public function test_merge_dedupes_same_resource_handle_to_single_upload(): void
    {
        // Referential identity: the SAME resource referenced twice uploads ONCE
        // (dedupe by resource id), mirroring the path/handle dedupe contract.
        $stream = \fopen('php://temp', 'r+b');
        self::assertNotFalse($stream);
        \fwrite($stream, 'shared');
        $asset = Merge::resource($stream);

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000f3', 'upload.bin', 'video/mp4', 6),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-0000000000f4'),
        ], $captured);

        $client = self::makeClient($http);
        try {
            $client
                ->merge([$asset, $asset], new MergeOptions(mediaKind: 'video'))
                ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));
        } finally {
            \fclose($stream);
        }

        // 1 upload (deduped) + 1 workflow create.
        $this->assertCount(2, $captured);
        $this->assertStringContainsString('/api/uploads', (string) $captured[0]->getUri());
        $this->assertStringContainsString('/api/workflows', (string) $captured[1]->getUri());
    }

    public function test_merge_missing_path_fails_before_any_upload(): void
    {
        // planSequence preflights path existence before uploadUniqueAssets
        // (codex VOxtu0RZ-B4 r3), so a missing path uploads nothing.
        $valid = self::writeTempFile('a');
        $captured = [];
        $http = self::stubClient([], $captured);
        $client = self::makeClient($http);
        try {
            $client
                ->merge([Merge::asset($valid), Merge::asset('/nonexistent/missing.mp4')], new MergeOptions(mediaKind: 'video'))
                ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));
            $this->fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            $this->assertStringContainsString('File not found', $e->getMessage());
        }
        $this->assertCount(0, $captured, 'no upload may fire when a path preflight fails');
    }

    public function test_sequence_with_clip_emits_per_input_options_on_video(): void
    {
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp4', 'video/mp4', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.mp4', 'video/mp4', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a01'),
        ], $captured);

        $client = self::makeClient($http);
        $client->merge([$pathA, $pathB], new MergeOptions(mediaKind: 'video'))
            ->sequence([
                Merge::asset($pathA),
                Merge::clip($pathB, new ClipOptions(transition: 'crossfade', crossfadeDuration: 1.5)),
            ])
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $body = self::decodeJson($captured[2]);
        $mergeJob = $this->assertMergeShapeAndReturnMergeJob($body, 2);
        $inputs = $mergeJob['inputs'];
        $this->assertCount(2, $inputs);
        $this->assertArrayNotHasKey('per_input_options', $inputs[0]);
        $this->assertSame(
            ['transition' => 'crossfade', 'crossfade_duration' => 1.5],
            $inputs[1]['per_input_options'],
        );
    }

    public function test_handle_asset_short_circuits_upload(): void
    {
        $pathA = self::writeTempFile('a');

        $captured = [];
        $http = self::stubClient([
            // Only ONE upload — the handle skips its own upload.
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp4', 'video/mp4', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a02'),
        ], $captured);

        $client = self::makeClient($http);
        $client->merge(
            [Merge::asset($pathA), Merge::handle('01936fb1-7bb3-7000-8000-0000000000d4')],
            new MergeOptions(mediaKind: 'video'),
        )->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        // Only 2 outbound requests (1 upload + 1 workflow create).
        $this->assertCount(2, $captured);
        $body = self::decodeJson($captured[1]);
        // p0SuJEeK — a handle asset still gets its own passthrough src job;
        // the file_ids (uploaded + handle) live on the src jobs now.
        $mergeJob = $this->assertMergeShapeAndReturnMergeJob($body, 2);
        $this->assertSame('src_0', $mergeJob['inputs'][0]['source']['from']);
        $this->assertSame('src_1', $mergeJob['inputs'][1]['source']['from']);
        $this->assertSame('01936fb1-7bb3-7000-8000-0000000000a1', $body['jobs'][0]['source']['file_id']);
        $this->assertSame('01936fb1-7bb3-7000-8000-0000000000d4', $body['jobs'][1]['source']['file_id']);
    }

    public function test_repeated_path_dedupes_to_one_upload(): void
    {
        // ONE path referenced THREE times in the asset set + sequence → one upload.
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp4', 'video/mp4', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.mp4', 'video/mp4', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a03'),
        ], $captured);

        $client = self::makeClient($http);
        $client->merge([$pathA, $pathB, $pathA], new MergeOptions(mediaKind: 'video'))
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        // Three positions, two uploads. Wire emits three merge `inputs[]`
        // entries but only TWO src jobs (the repeated pathA re-uses its src).
        $this->assertCount(3, $captured);
        $body = self::decodeJson($captured[2]);
        $mergeJob = $this->assertMergeShapeAndReturnMergeJob($body, 2);
        $this->assertCount(3, $mergeJob['inputs']);
        // Positions 0 and 2 are the same asset → SAME src job (not a second one).
        $this->assertSame(
            $mergeJob['inputs'][0]['source']['from'],
            $mergeJob['inputs'][2]['source']['from'],
        );
    }

    public function test_two_distinct_path_asset_objects_with_same_path_dedupe_to_one_upload(): void
    {
        // Reality-check from karen: PHP value semantics — two distinct
        // `PathAsset` instances carrying the same path must dedupe by
        // identity-string, not object reference.
        $path = self::writeTempFile('shared');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000c3', 'shared.mp4', 'video/mp4', 6),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a04'),
        ], $captured);

        $a = new PathAsset($path);
        $b = new PathAsset($path);
        $this->assertNotSame($a, $b, 'precondition: distinct objects');

        $client = self::makeClient($http);
        $client->merge([$a, $b], new MergeOptions(mediaKind: 'video'))
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $this->assertCount(2, $captured, 'two distinct PathAsset objects with identical path string upload once');
        $body = self::decodeJson($captured[1]);
        // 1 unique asset → 1 src job; both merge positions reference it.
        $mergeJob = $this->assertMergeShapeAndReturnMergeJob($body, 1);
        $this->assertCount(2, $mergeJob['inputs']);
        $this->assertSame(
            $mergeJob['inputs'][0]['source']['from'],
            $mergeJob['inputs'][1]['source']['from'],
            'both positions point at the same src job (same uploaded file_id)',
        );
    }

    public function test_undeclared_sequence_ref_raises_config_error(): void
    {
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');
        $pathC = self::writeTempFile('c');

        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));

        $thrown = null;
        try {
            $client->merge([$pathA, $pathB], new MergeOptions(mediaKind: 'video'))
                ->sequence([Merge::asset($pathA), Merge::asset($pathC)])
                ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));
        } catch (GislUndeclaredAssetError $e) {
            $thrown = $e;
        }

        // Tighten from the base GislConfigError to the SPECIFIC subclass — it
        // still satisfies `instanceof GislConfigError` for catch-all callers.
        $this->assertInstanceOf(GislUndeclaredAssetError::class, $thrown);
        $this->assertInstanceOf(GislConfigError::class, $thrown);
        $this->assertMatchesRegularExpression(
            "/Sequence references asset '.+' but it wasn't declared/",
            $thrown->getMessage(),
        );
        // Identities are namespaced (`path:<path>`); the undeclared reference is
        // $pathC and the declared set is {$pathA, $pathB}.
        $this->assertSame("path:{$pathC}", $thrown->assetId);
        $this->assertSame(["path:{$pathA}", "path:{$pathB}"], $thrown->declaredAssets);
        $this->assertSame([], $captured, 'undeclared-ref check fires before any upload');
    }

    public function test_unused_declared_asset_raises_config_error(): void
    {
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');
        $pathC = self::writeTempFile('c');

        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));

        $thrown = null;
        try {
            $client->merge([$pathA, $pathB, $pathC], new MergeOptions(mediaKind: 'video'))
                // Sequence omits $pathC — caught by unused-asset check.
                ->sequence([Merge::asset($pathA), Merge::asset($pathB)])
                ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));
        } catch (GislUnusedAssetError $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(GislUnusedAssetError::class, $thrown);
        $this->assertInstanceOf(GislConfigError::class, $thrown);
        $this->assertMatchesRegularExpression(
            '/were declared in merge\(\.\.\.\) but never sequenced/',
            $thrown->getMessage(),
        );
        // Only $pathC was declared-but-unsequenced.
        $this->assertSame(["path:{$pathC}"], $thrown->unusedAssets);
        $this->assertSame([], $captured, 'unused-asset check fires before any upload');
    }

    public function test_allow_unused_assets_bypasses_unused_check_and_skips_upload(): void
    {
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');
        $pathC = self::writeTempFile('c');

        $captured = [];
        $http = self::stubClient([
            // Only TWO uploads — pathC declared but never sequenced.
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp4', 'video/mp4', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.mp4', 'video/mp4', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a05'),
        ], $captured);

        $client = self::makeClient($http);
        $client->merge(
            [$pathA, $pathB, $pathC],
            new MergeOptions(mediaKind: 'video', allowUnusedAssets: true),
        )
            ->sequence([Merge::asset($pathA), Merge::asset($pathB)])
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $this->assertCount(3, $captured, 'pathC declared-but-unsequenced does NOT upload');
    }

    public function test_image_merge_rejects_per_input_clip_options(): void
    {
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));

        $thrown = null;
        try {
            $client->merge([$pathA, $pathB], new MergeOptions(mediaKind: 'image'))
                ->sequence([
                    Merge::asset($pathA),
                    Merge::clip($pathB, new ClipOptions(transition: 'fade')),
                ])
                ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));
        } catch (GislPerInputOptionsNotSupportedError $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(GislPerInputOptionsNotSupportedError::class, $thrown);
        $this->assertInstanceOf(GislConfigError::class, $thrown);
        $this->assertMatchesRegularExpression(
            '/image merge has no per-input options today/',
            $thrown->getMessage(),
        );
        $this->assertSame('image', $thrown->mediaKind);
        $this->assertSame([], $captured, 'per-input rejection fires before any upload');
    }

    public function test_video_merge_drops_merge_level_gap_duration(): void
    {
        // Video merges don't support merge-level gap_duration (TS R2 medium
        // ab2422e56ea0). Set it and assert it's stripped from the wire.
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp4', 'video/mp4', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.mp4', 'video/mp4', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a06'),
        ], $captured);

        $client = self::makeClient($http);
        $client->merge(
            [$pathA, $pathB],
            new MergeOptions(mediaKind: 'video', gapDuration: 2.0, transition: 'fade'),
        )->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $body = self::decodeJson($captured[2]);
        $mergeJob = $this->assertMergeShapeAndReturnMergeJob($body, 2);
        $options = $mergeJob['operations'][0]['options'];
        $this->assertSame('fade', $options['transition']);
        $this->assertArrayNotHasKey('gap_duration', $options);
    }

    public function test_audio_merge_keeps_merge_level_gap_duration(): void
    {
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp3', 'audio/mpeg', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.mp3', 'audio/mpeg', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a07'),
        ], $captured);

        $client = self::makeClient($http);
        $client->merge(
            [$pathA, $pathB],
            new MergeOptions(mediaKind: 'audio', gapDuration: 2.5),
        )->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $body = self::decodeJson($captured[2]);
        $mergeJob = $this->assertMergeShapeAndReturnMergeJob($body, 2);
        $this->assertSame(2.5, $mergeJob['operations'][0]['options']['gap_duration']);
    }

    public function test_video_per_input_clip_drops_gap_duration(): void
    {
        // Per-input gap_duration is audio-only (TS R1 medium 128404fa16a9).
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp4', 'video/mp4', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.mp4', 'video/mp4', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a08'),
        ], $captured);

        $client = self::makeClient($http);
        $client->merge([$pathA, $pathB], new MergeOptions(mediaKind: 'video'))
            ->sequence([
                Merge::asset($pathA),
                Merge::clip($pathB, new ClipOptions(transition: 'crossfade', gapDuration: 1.0)),
            ])
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $body = self::decodeJson($captured[2]);
        $mergeJob = $this->assertMergeShapeAndReturnMergeJob($body, 2);
        $perInput = $mergeJob['inputs'][1]['per_input_options'];
        $this->assertSame('crossfade', $perInput['transition']);
        $this->assertArrayNotHasKey('gap_duration', $perInput);
    }

    public function test_single_asset_below_min_inputs_raises_config_error(): void
    {
        $pathA = self::writeTempFile('a');

        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));

        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/at least 2 inputs/');

        $client->merge([$pathA], new MergeOptions(mediaKind: 'video'))
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));
    }

    public function test_sequence_above_max_inputs_raises_config_error(): void
    {
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));

        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/at most 10 inputs/');

        // 11 positions via repeats — exceeds max_inputs=10. Both pathA and
        // pathB are referenced so the unused-asset check passes; the
        // max-inputs check fires next.
        $seq = array_merge(
            [Merge::asset($pathA), Merge::asset($pathB)],
            array_fill(0, 9, Merge::asset($pathA)),
        );
        $client->merge([$pathA, $pathB], new MergeOptions(mediaKind: 'video'))
            ->sequence($seq)
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));
    }

    public function test_invalid_target_size_string_raises_config_error(): void
    {
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp4', 'video/mp4', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.mp4', 'video/mp4', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a09'),
        ], $captured);
        $client = self::makeClient($http);

        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/Invalid targetSize/');

        $client->merge([$pathA, $pathB], new MergeOptions(mediaKind: 'video', targetSize: 'garbage'))
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));
    }

    public function test_target_size_sized_string_lowers_to_byte_count_and_encoding_mode(): void
    {
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp4', 'video/mp4', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.mp4', 'video/mp4', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a0a'),
        ], $captured);
        $client = self::makeClient($http);

        $client->merge([$pathA, $pathB], new MergeOptions(mediaKind: 'video', targetSize: '10MB'))
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $body = self::decodeJson($captured[2]);
        $opts = $this->assertMergeShapeAndReturnMergeJob($body, 2)['operations'][0]['options'];
        $this->assertSame(10_000_000, $opts['target_size_bytes']);
        $this->assertSame('target_size', $opts['encoding_mode']);
    }

    public function test_bare_string_in_merge_auto_wraps_via_path_asset(): void
    {
        // The variadic overload accepts bare strings AND Asset instances —
        // strings auto-wrap via Merge::asset().
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp4', 'video/mp4', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.mp4', 'video/mp4', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a0b'),
        ], $captured);
        $client = self::makeClient($http);

        $client->merge([$pathA, $pathB])
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $this->assertCount(3, $captured);
    }

    public function test_numeric_target_size_emits_byte_count_verbatim_with_encoding_mode(): void
    {
        // Mirrors the sized-string test; covers the `is_int` branch of
        // wireMergeOptions() which a typo in the int/string union would
        // silently skip.
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp4', 'video/mp4', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.mp4', 'video/mp4', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a0d'),
        ], $captured);
        $client = self::makeClient($http);

        $client->merge([$pathA, $pathB], new MergeOptions(mediaKind: 'video', targetSize: 5_000_000))
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $body = self::decodeJson($captured[2]);
        $opts = $this->assertMergeShapeAndReturnMergeJob($body, 2)['operations'][0]['options'];
        $this->assertSame(5_000_000, $opts['target_size_bytes']);
        $this->assertSame('target_size', $opts['encoding_mode']);
    }

    public function test_wire_merge_options_drops_video_only_fields_on_image_merge(): void
    {
        // Codex R2 medium a5aa664e6c74 — image merge allowlist is
        // {output_type, transition, transition_duration, fps,
        //  duration_per_image, loop_count, video_format}. Setting
        // video-only fields (codec, crf, preset, targetSize, normalizeAudio)
        // alongside an image merge must silently drop them locally instead
        // of paying for uploads and hitting a server-side 422.
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.png', 'image/png', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.png', 'image/png', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a0f'),
        ], $captured);
        $client = self::makeClient($http);

        $client->merge(
            [$pathA, $pathB],
            new MergeOptions(
                mediaKind: 'image',
                output: 'video',
                // The following are wire-invalid for image merges and MUST
                // be dropped from the outbound payload.
                crossfadeDuration: 1.0,
                normalizeAudio: true,
                reEncodeMode: 'always',
                codec: 'h264',
                crf: 23,
                preset: 'fast',
                targetResolution: '1920x1080',
                targetSize: '5MB',
                // image-allowed fields below
                transitionDuration: 0.5,
                fps: 24.0,
                delay: 500,
            ),
        )->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $body = self::decodeJson($captured[2]);
        $opts = $this->assertMergeShapeAndReturnMergeJob($body, 2)['operations'][0]['options'];
        $this->assertArrayHasKey('output_type', $opts);
        $this->assertSame('video', $opts['output_type']);
        $this->assertArrayHasKey('transition_duration', $opts);
        $this->assertArrayHasKey('fps', $opts);
        $this->assertArrayHasKey('delay', $opts);
        $this->assertSame(500, $opts['delay']);
        $this->assertArrayNotHasKey('crossfade_duration', $opts);
        $this->assertArrayNotHasKey('normalize_audio', $opts);
        $this->assertArrayNotHasKey('re_encode_mode', $opts);
        $this->assertArrayNotHasKey('codec', $opts);
        $this->assertArrayNotHasKey('crf', $opts);
        $this->assertArrayNotHasKey('preset', $opts);
        $this->assertArrayNotHasKey('target_resolution', $opts);
        $this->assertArrayNotHasKey('target_size_bytes', $opts);
        $this->assertArrayNotHasKey('encoding_mode', $opts);
    }

    public function test_wire_merge_options_drops_image_only_fields_on_video_merge(): void
    {
        // Twin coverage of a5aa664e6c74 — image-only fields
        // (transition_duration, fps, duration_per_image, loop_count,
        // video_format) must not leak into video merge wire payload.
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp4', 'video/mp4', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.mp4', 'video/mp4', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a10'),
        ], $captured);
        $client = self::makeClient($http);

        $client->merge(
            [$pathA, $pathB],
            new MergeOptions(
                mediaKind: 'video',
                reEncodeMode: 'always',
                codec: 'h264',
                targetResolution: '1920x1080',
                // image-only — must drop
                transitionDuration: 0.5,
                fps: 24.0,
                durationPerImage: 2.0,
                delay: 500,
                loopCount: 1,
                videoFormat: 'webm',
            ),
        )->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $body = self::decodeJson($captured[2]);
        $opts = $this->assertMergeShapeAndReturnMergeJob($body, 2)['operations'][0]['options'];
        $this->assertSame('h264', $opts['codec']);
        $this->assertSame('always', $opts['re_encode_mode']);
        $this->assertSame('1920x1080', $opts['target_resolution']);
        $this->assertArrayNotHasKey('transition_duration', $opts);
        $this->assertArrayNotHasKey('fps', $opts);
        $this->assertArrayNotHasKey('duration_per_image', $opts);
        $this->assertArrayNotHasKey('delay', $opts);
        $this->assertArrayNotHasKey('loop_count', $opts);
        $this->assertArrayNotHasKey('video_format', $opts);
    }

    public function test_image_merge_without_output_type_raises_config_error_pre_upload(): void
    {
        // Codex R1 medium b53ad6d22f53 — the server requires output_type
        // for image merges. Detect locally so callers don't waste uploads
        // chasing a server-side 422.
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));

        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/image merges require an explicit output_type/');

        $client->merge([$pathA, $pathB], new MergeOptions(mediaKind: 'image'))
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        // No uploads happened — assertion would not be reached but the
        // empty captured list is the cheapest proof in case the expected
        // exception class changes upstream.
        $this->assertSame([], $captured);
    }

    public function test_invalid_target_size_string_raises_config_error_before_upload(): void
    {
        // Codex R1 medium a93bd61d39a9 — parseSizeString used to fire
        // from buildPayload() AFTER uploadUniqueAssets, so a garbage
        // targetSize would burn N uploads before throwing. The check
        // now fires inside planSequence, BEFORE any wire traffic.
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));

        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/Invalid targetSize/');

        $client->merge([$pathA, $pathB], new MergeOptions(mediaKind: 'video', targetSize: 'garbage'))
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $this->assertSame([], $captured, 'no uploads should fire before targetSize validation');
    }

    public function test_garbage_target_size_string_is_ignored_on_non_video_merge(): void
    {
        // DCJUvvfA (codex #176 r3) — targetSize → target_size_bytes is video-only
        // (wireMergeOptions drops it for image/audio). The pre-upload validation
        // is gated to video, so a garbage targetSize on an image merge must NOT
        // throw: the field never crosses the wire, so rejecting over its string
        // form would fail the workflow on a value the SDK silently discards.
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.png', 'image/png', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.png', 'image/png', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a0f'),
        ], $captured);
        $client = self::makeClient($http);

        $client->merge(
            [$pathA, $pathB],
            new MergeOptions(mediaKind: 'image', output: 'video', targetSize: 'garbage'),
        )->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $body = self::decodeJson($captured[2]);
        $opts = $this->assertMergeShapeAndReturnMergeJob($body, 2)['operations'][0]['options'];
        $this->assertArrayNotHasKey('target_size_bytes', $opts);
        $this->assertArrayNotHasKey('encoding_mode', $opts);
    }

    public function test_two_handle_assets_with_same_file_id_dedupe(): void
    {
        // Twin of the PathAsset-identity test, for HandleAsset. Two
        // distinct HandleAsset objects with identical fileId must collapse
        // to a single `inputs[]` source position — but BOTH positions still
        // emit (one per sequence position), pointing at the same fileId.
        $captured = [];
        $http = self::stubClient([
            // ZERO uploads — both assets are pre-uploaded handles.
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a0e'),
        ], $captured);
        $client = self::makeClient($http);

        $client->merge(
            [
                Merge::handle('01936fb1-7bb3-7000-8000-0000000000e5'),
                Merge::handle('01936fb1-7bb3-7000-8000-0000000000e5'),
            ],
            new MergeOptions(mediaKind: 'video'),
        )->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        // Only ONE outbound request (workflow create) — no uploads.
        $this->assertCount(1, $captured);
        $body = self::decodeJson($captured[0]);
        // Two duplicate handles → ONE unique asset → ONE src job carrying the
        // shared file_id; both merge positions reference src_0.
        $mergeJob = $this->assertMergeShapeAndReturnMergeJob($body, 1);
        $this->assertCount(
            2,
            $mergeJob['inputs'],
            'sequence positions are preserved even when handles dedupe',
        );
        $this->assertSame(
            '01936fb1-7bb3-7000-8000-0000000000e5',
            $body['jobs'][0]['source']['file_id'],
        );
        $this->assertSame(
            $mergeJob['inputs'][0]['source']['from'],
            $mergeJob['inputs'][1]['source']['from'],
        );
    }

    public function test_media_kind_inference_from_image_extension_rejects_per_input_opts(): void
    {
        // .png extension on the first asset infers 'image' kind; image
        // merges with per-input clip options must reject. A typo in the
        // extension list (e.g. swapping `jpe?g`/`png` for incorrect
        // patterns) would silently classify the merge as video and skip
        // the per-input-options rejection.
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');
        $imageA = sys_get_temp_dir() . '/gisl-merge-' . bin2hex(random_bytes(4)) . '.png';
        $imageB = sys_get_temp_dir() . '/gisl-merge-' . bin2hex(random_bytes(4)) . '.png';
        copy($pathA, $imageA);
        copy($pathB, $imageB);

        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));

        $thrown = null;
        try {
            // No explicit mediaKind — relies on extension sniffing.
            $client->merge([$imageA, $imageB])
                ->sequence([
                    Merge::asset($imageA),
                    Merge::clip($imageB, new ClipOptions(transition: 'fade')),
                ])
                ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));
        } catch (GislPerInputOptionsNotSupportedError $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(GislPerInputOptionsNotSupportedError::class, $thrown);
        $this->assertInstanceOf(GislConfigError::class, $thrown);
        $this->assertSame('image', $thrown->mediaKind);
        $this->assertSame([], $captured, 'inferred-image per-input rejection fires before any upload');
    }

    public function test_media_kind_inference_from_first_asset_extension(): void
    {
        // No mediaKind set on MergeOptions; .mp3 extension → audio kind →
        // merge-level gapDuration kept on the wire.
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');
        // Rename to .mp3 paths via temp directory.
        $audioA = sys_get_temp_dir() . '/gisl-merge-' . bin2hex(random_bytes(4)) . '.mp3';
        $audioB = sys_get_temp_dir() . '/gisl-merge-' . bin2hex(random_bytes(4)) . '.mp3';
        copy($pathA, $audioA);
        copy($pathB, $audioB);

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp3', 'audio/mpeg', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.mp3', 'audio/mpeg', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a0c'),
        ], $captured);
        $client = self::makeClient($http);

        $client->merge([$audioA, $audioB], new MergeOptions(gapDuration: 1.5))
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $body = self::decodeJson($captured[2]);
        $opts = $this->assertMergeShapeAndReturnMergeJob($body, 2)['operations'][0]['options'];
        $this->assertSame(1.5, $opts['gap_duration'], 'audio inference keeps gap_duration on the wire');
    }

    public function test_run_projects_only_merge_job_artifacts_excluding_passthrough_src_jobs(): void
    {
        // p0SuJEeK — run() filters getWorkflowDownloads to ONLY the merge
        // job's output (ref === 'merge'), dropping the passthrough src jobs
        // whose output is the unchanged upload (plumbing, not a deliverable).
        // PHP twin of merge.test.ts "drops src_* passthrough download groups".
        // The PHP filter ships at MergeBuilder.php:136-139 untested; the plain
        // OperationBuilder::run does NOT filter, so this is MergeBuilder-specific.
        $pathA = self::writeTempFile('aaaa');
        $pathB = self::writeTempFile('bbbb');

        $workflowId = '01936fb2-0000-7000-8000-00000000ab01';
        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-00000000ab02', 'a.mp4', 'video/mp4', 4),
            self::uploadResponse('01936fb1-7bb3-7000-8000-00000000ab03', 'b.mp4', 'video/mp4', 4),
            self::workflowCreatedResponse($workflowId),
            self::workflowStatusResponse($workflowId, 'completed'),
            self::workflowDownloadsResponse(),
        ], $captured);

        $client = self::makeClient($http);
        // useSSE: false routes awaitTerminal to the single-poll path. The
        // RunOptions default (useSSE: true) would issue an unstubbed
        // GET /events SSE request and exhaust the 5-response queue.
        $result = $client
            ->merge([$pathA, $pathB], new MergeOptions(mediaKind: 'video'))
            ->run(new RunOptions(maxWait: '5m', useSSE: false));

        $this->assertInstanceOf(Result::class, $result);
        $this->assertSame('completed', $result->status);

        // The downloads stub returns THREE groups (src_0, src_1, merge). Only
        // the merge group survives the filter — deleting it would yield 3.
        $this->assertCount(1, $result->artifacts, 'src_* passthrough groups are filtered out');
        $this->assertSame('merge', $result->artifacts[0]->ref);
        // Pin the surviving artifact's payload (mirrors merge.test.ts:549) — not
        // just its ref — so a mis-wired projection that kept the wrong group's
        // url would be caught.
        $this->assertSame('https://dl.test.example.com/merge.mp4', $result->artifacts[0]->url);

        $refs = array_map(static fn (Artifact $a): string => $a->ref, $result->artifacts);
        $this->assertNotContains('src_0', $refs);
        $this->assertNotContains('src_1', $refs);

        // Mirror the TS twin's operation assertion: the passthrough src-job
        // output must never surface as a deliverable artifact.
        $operations = array_map(static fn (Artifact $a): string => $a->operation, $result->artifacts);
        $this->assertNotContains('passthrough', $operations);

        // 5 outbound requests: 2 uploads, create, status poll, downloads.
        $this->assertCount(5, $captured);
        $this->assertStringContainsString('/status', (string) $captured[3]->getUri());
        $this->assertStringContainsString($workflowId, (string) $captured[3]->getUri());
        $this->assertStringContainsString('/downloads', (string) $captured[4]->getUri());
        $this->assertStringContainsString($workflowId, (string) $captured[4]->getUri());
    }

    // -----------------------------------------------------------------
    // New typed merge-error classes — direct instantiation
    // (mirrors the field+message coverage of the TS error classes in
    //  packages/typescript/src/errors.ts).
    // -----------------------------------------------------------------

    public function test_undeclared_asset_error_carries_message_and_props(): void
    {
        $error = new GislUndeclaredAssetError('path:c.mp4', ['path:a.mp4', 'path:b.mp4']);

        $this->assertInstanceOf(GislConfigError::class, $error);
        $this->assertSame('path:c.mp4', $error->assetId);
        $this->assertSame(['path:a.mp4', 'path:b.mp4'], $error->declaredAssets);
        $this->assertSame(
            "Sequence references asset 'path:c.mp4' but it wasn't declared in merge(...). "
            . 'Declared assets: [path:a.mp4, path:b.mp4]. '
            . 'Either pass it to merge(...) before sequencing, or remove the reference.',
            $error->getMessage(),
        );
    }

    public function test_unused_asset_error_carries_message_and_props(): void
    {
        $error = new GislUnusedAssetError(['path:c.mp4', 'path:d.mp4']);

        $this->assertInstanceOf(GislConfigError::class, $error);
        $this->assertSame(['path:c.mp4', 'path:d.mp4'], $error->unusedAssets);
        $this->assertSame(
            'Assets [path:c.mp4, path:d.mp4] were declared in merge(...) but never sequenced. '
            . 'Reference them in .sequence(...), remove them from the declaration, '
            . 'or pass {allowUnusedAssets: true} to opt out of this check.',
            $error->getMessage(),
        );
    }

    public function test_per_input_options_not_supported_error_carries_message_and_props(): void
    {
        $error = new GislPerInputOptionsNotSupportedError('image');

        $this->assertInstanceOf(GislConfigError::class, $error);
        $this->assertSame('image', $error->mediaKind);
        $this->assertSame(
            "image merge has no per-input options today; set 'transition' at the "
            . '.merge(...) level instead — it applies to every join.',
            $error->getMessage(),
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * p0SuJEeK — the merge payload now emits one single-input `passthrough`
     * source job per UNIQUE asset (first-seen position order) FOLLOWED BY the
     * merge job LAST. So `$body['jobs'][0]` is no longer the merge job. This
     * helper returns the merge job (the last element, id 'merge') and asserts
     * the leading source jobs carry `operations: [{type: passthrough}]`,
     * `source.type === upload`, and that every merge input references a
     * source job via `{type: job_output, from: src_<i>}`.
     *
     * @param array<string, mixed> $body
     * @param int $expectedSourceJobs number of unique assets = source job count
     * @return array<string, mixed> the merge job
     */
    private function assertMergeShapeAndReturnMergeJob(array $body, int $expectedSourceJobs): array
    {
        /** @var list<array<string, mixed>> $jobs */
        $jobs = $body['jobs'];
        $this->assertCount($expectedSourceJobs + 1, $jobs, 'one src job per unique asset + the merge job');

        $srcIds = [];
        for ($i = 0; $i < $expectedSourceJobs; $i++) {
            /** @var array<string, mixed> $job */
            $job = $jobs[$i];
            $this->assertSame("src_{$i}", $job['id']);
            $this->assertSame('upload', $job['source']['type']);
            $this->assertIsString($job['source']['file_id']);
            $this->assertArrayNotHasKey('inputs', $job);
            $this->assertSame([['type' => 'passthrough']], $job['operations']);
            $srcIds[$job['id']] = true;
        }

        /** @var array<string, mixed> $mergeJob */
        $mergeJob = $jobs[$expectedSourceJobs];
        $this->assertSame('merge', $mergeJob['id']);
        $this->assertSame('merge', $mergeJob['operations'][0]['type']);

        /** @var list<array<string, mixed>> $inputs */
        $inputs = $mergeJob['inputs'];
        foreach ($inputs as $input) {
            $this->assertSame('job_output', $input['source']['type']);
            $this->assertArrayHasKey($input['source']['from'], $srcIds, 'merge input references a known src job');
            $this->assertArrayNotHasKey('file_id', $input['source'], 'merge inputs no longer carry upload file_id');
        }

        return $mergeJob;
    }

    private static function writeTempFile(string $bytes): string
    {
        $dir = sys_get_temp_dir() . '/gisl-merge-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $path = $dir . '/fixture.bin';
        file_put_contents($path, $bytes);
        return $path;
    }

    private static function makeClient(ClientInterface $http): GislErgonomicClient
    {
        $factory = new HttpFactory();
        return new GislErgonomicClient(
            config: new GislClientConfig(
                baseUrl: 'https://api.test.example.com',
                apiKey: 'test-api-key',
                multipartConcurrency: 1,
            ),
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
        );
    }

    /**
     * @param list<ResponseInterface> $queue
     * @param-out list<RequestInterface> $captured
     */
    private static function stubClient(array $queue, array &$captured): ClientInterface
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
                $next = array_shift($this->queue);
                if ($next === null) {
                    throw new \RuntimeException(
                        'Stub PSR-18 client: response queue exhausted on request #' . count($this->captured),
                    );
                }
                return $next;
            }
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function jsonResponse(int $status, array $body): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private static function uploadResponse(string $fileId, string $name, string $mime, int $size): ResponseInterface
    {
        return self::jsonResponse(200, [
            'success' => true,
            'data' => [
                'file_id' => $fileId,
                'original_name' => $name,
                'mime_type' => $mime,
                'size_bytes' => $size,
            ],
        ]);
    }

    private static function workflowCreatedResponse(string $workflowId): ResponseInterface
    {
        return self::jsonResponse(201, [
            'success' => true,
            'data' => [
                'workflow_id' => $workflowId,
                'status' => 'pending',
                'webhook_secret' => 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789',
                'created_at' => '2026-05-27T11:00:00Z',
                'jobs' => [],
                'delivery_plan' => [
                    'mode' => 'individual',
                    'selection_type' => 'terminal',
                    'outputs' => [],
                    'hidden_outputs' => [],
                ],
                'processing_plan' => ['jobs' => []],
                'warnings' => [],
            ],
        ]);
    }

    private static function workflowStatusResponse(string $workflowId, string $status): ResponseInterface
    {
        // `status` deserializes through the WorkflowStatus enum, so it must be
        // an allowable value; a TERMINAL one ('completed') lets pollToTerminal
        // return on its first poll. created_at/updated_at/jobs mirror the real
        // envelope (projectResult reads them null-safely, but keep it honest).
        return self::jsonResponse(200, [
            'success' => true,
            'data' => [
                'workflow_id' => $workflowId,
                'status' => $status,
                'created_at' => '2026-05-27T11:00:00Z',
                'updated_at' => '2026-05-27T11:00:05Z',
                'jobs' => [],
            ],
        ]);
    }

    /**
     * Three download groups — two passthrough `src_*` jobs + the `merge` job —
     * so the merge-ref filter is exercised non-vacuously: without it, all three
     * would project to artifacts. job_id / operation_id MUST be UUID-v7 hex —
     * generated-model hydration runs through setters that enforce the pattern.
     */
    private static function workflowDownloadsResponse(): ResponseInterface
    {
        $file = static fn (string $operation, string $operationId): array => [
            'operation' => $operation,
            'operation_id' => $operationId,
            'filename' => $operation === 'merge' ? 'merged.mp4' : 'src.mp4',
            'size_bytes' => 2048,
            'download_url' => 'https://dl.test.example.com/' . $operation . '.mp4',
        ];

        return self::jsonResponse(200, [
            'success' => true,
            'data' => [
                'downloads' => [
                    [
                        'ref' => 'src_0',
                        'job_id' => '01936fb3-0000-7000-8000-0000000000a0',
                        'files' => [$file('passthrough', '01936fb4-0000-7000-8000-0000000000f0')],
                    ],
                    [
                        'ref' => 'src_1',
                        'job_id' => '01936fb3-0000-7000-8000-0000000000a1',
                        'files' => [$file('passthrough', '01936fb4-0000-7000-8000-0000000000f1')],
                    ],
                    [
                        'ref' => 'merge',
                        'job_id' => '01936fb3-0000-7000-8000-0000000000a2',
                        'files' => [$file('merge', '01936fb4-0000-7000-8000-0000000000f2')],
                    ],
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJson(RequestInterface $request): array
    {
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        return $body;
    }
}
