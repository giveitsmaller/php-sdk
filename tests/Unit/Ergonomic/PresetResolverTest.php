<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Ergonomic;

use Gisl\Sdk\Ergonomic\PresetResolver;
use Gisl\Sdk\Ergonomic\ResolvedOptions;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Generated\SdkSpec\Enums\AudioBitrate;
use Gisl\Sdk\Generated\SdkSpec\Enums\ImageFormat;
use Gisl\Sdk\Generated\SdkSpec\Enums\ImageMetadataPolicy;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Generated\SdkSpec\Enums\VideoCodec;
use Gisl\Sdk\Generated\SdkSpec\Version;
use Gisl\Sdk\Preset\AudioCompressPresetOptions;
use Gisl\Sdk\Preset\ImageCompressPresetOptions;
use Gisl\Sdk\Preset\VideoCompressPresetOptions;
use Gisl\Sdk\PresetDefaults;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PresetResolver::class)]
#[CoversClass(ResolvedOptions::class)]
final class PresetResolverTest extends TestCase
{
    // -----------------------------------------------------------------
    // Generated-constant invariant (mirrors TS preset-resolver.test.ts)
    // -----------------------------------------------------------------

    /**
     * The public PRESET_VERSION constant must equal the generated sdk_spec
     * constant — guards the direct consumer path (OperationBuilder reads
     * PresetResolver::PRESET_VERSION) against a same-value hand-typed
     * regression that the resolver-output pin alone would not catch.
     */
    public function testPresetVersionConstantTracksGeneratedConstant(): void
    {
        $this->assertSame(Version::PRESET_VERSION, PresetResolver::PRESET_VERSION);
    }

    // -----------------------------------------------------------------
    // Layer precedence
    // -----------------------------------------------------------------

    public function testExplicitOnlyNoOptimize(): void
    {
        $out = PresetResolver::resolveCompress('image', null, null, null, null, ['quality' => 80]);

        $this->assertSame(['quality' => 80], $out['wireOptions']);
        $ro = $out['resolvedOptions'];
        $this->assertNull($ro->preset);
        $this->assertSame(['quality' => 80], $ro->applied);
        $this->assertSame(['quality'], $ro->sources->explicit);
        $this->assertSame([], $ro->sources->sdkDefault);
        $this->assertSame([], $ro->sources->clientDefault);
        $this->assertSame(Version::PRESET_VERSION, $ro->presetVersion);
        $this->assertNull($ro->presetConfigHash);
        // overrides back-compat mirrors sources.explicit.
        $this->assertSame(['quality'], $ro->overrides);
    }

    public function testShippedDefaultsAppliedWhenOptimizeSet(): void
    {
        $out = PresetResolver::resolveCompress('image', null, null, null, OptimizeFor::Size, []);
        $ro = $out['resolvedOptions'];

        $this->assertSame('Size', $ro->preset);
        // Stable cell values from the generated PRESETS image_compress/Size cell.
        // v2.80.0 honesty pass: image wire surface is only {quality, metadata,
        // output_format} — mode / icc_profile / progressive are gone.
        $this->assertSame(65, $out['wireOptions']['quality']);
        // v2.107.0 metadata rename: the shipped Size cell now ships `strip` (was `all`).
        $this->assertSame('strip', $out['wireOptions']['metadata']);
        // Every shipped field is attributed to sdkDefault (wire names, sorted).
        $this->assertSame(
            ['metadata', 'output_format', 'quality'],
            $ro->sources->sdkDefault,
        );
        $this->assertSame([], $ro->sources->explicit);
        // Only sdkDefault participated → no presetConfigHash.
        $this->assertNull($ro->presetConfigHash);
    }

    public function testClientDefaultBeatsShipped(): void
    {
        $defaults = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 75));

        $out = PresetResolver::resolveCompress('image', $defaults, null, null, OptimizeFor::Size, []);
        $ro = $out['resolvedOptions'];

        $this->assertSame(75, $out['wireOptions']['quality']);
        $this->assertSame(['quality'], $ro->sources->clientDefault);
        $this->assertNotContains('quality', $ro->sources->sdkDefault);
        $this->assertIsString($ro->presetConfigHash);
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $ro->presetConfigHash);
    }

    public function testCallPresetOverrideBeatsClientAndShipped(): void
    {
        $defaults = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 75));

        $out = PresetResolver::resolveCompress('image', $defaults, null, ['quality' => 90], OptimizeFor::Size, []);
        $ro = $out['resolvedOptions'];

        $this->assertSame(90, $out['wireOptions']['quality']);
        $this->assertSame(['quality'], $ro->sources->callPresetOverride);
        $this->assertNotContains('quality', $ro->sources->clientDefault);
        $this->assertNotContains('quality', $ro->sources->sdkDefault);
    }

    public function testExplicitBeatsEveryLowerLayer(): void
    {
        $defaults = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 75));

        $out = PresetResolver::resolveCompress('image', $defaults, null, ['quality' => 90], OptimizeFor::Size, ['quality' => 100]);
        $ro = $out['resolvedOptions'];

        $this->assertSame(100, $out['wireOptions']['quality']);
        $this->assertSame(['quality'], $ro->sources->explicit);
        $this->assertNotContains('quality', $ro->sources->callPresetOverride);
        $this->assertNotContains('quality', $ro->sources->clientDefault);
    }

    public function testOptimizeUnsetSkipsClientAndShippedButKeepsOverrideAndExplicit(): void
    {
        $defaults = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 75));

        // No optimize ⇒ shipped + client layers contribute nothing.
        $out = PresetResolver::resolveCompress('image', $defaults, null, ['metadata' => ImageMetadataPolicy::All], null, ['quality' => 50]);
        $ro = $out['resolvedOptions'];

        $this->assertNull($ro->preset);
        $this->assertEqualsCanonicalizing(['quality' => 50, 'metadata' => 'all'], $out['wireOptions']);
        $this->assertSame([], $ro->sources->sdkDefault);
        $this->assertSame([], $ro->sources->clientDefault);
        $this->assertSame(['metadata'], $ro->sources->callPresetOverride);
        $this->assertSame(['quality'], $ro->sources->explicit);
    }

    // -----------------------------------------------------------------
    // Enum case → wire value + alias
    // -----------------------------------------------------------------

    public function testEnumCaseConvertedToWireValue(): void
    {
        $out = PresetResolver::resolveCompress('video', null, null, null, null, ['codec' => VideoCodec::H264]);
        $this->assertSame('h264', $out['wireOptions']['codec']);
    }

    public function testCamelFieldAliasedToSnakeWire(): void
    {
        $out = PresetResolver::resolveCompress('image', null, null, null, null, ['outputFormat' => ImageFormat::Webp]);
        $this->assertArrayHasKey('output_format', $out['wireOptions']);
        $this->assertArrayNotHasKey('outputFormat', $out['wireOptions']);
        $this->assertSame('webp', $out['wireOptions']['output_format']);
        $this->assertSame(['output_format'], $out['resolvedOptions']->sources->explicit);
    }

    // -----------------------------------------------------------------
    // targetSize derivation
    // -----------------------------------------------------------------

    public function testTargetSizeStringDerivesWireFields(): void
    {
        $out = PresetResolver::resolveCompress('video', null, null, null, null, ['targetSize' => '8MB', 'codec' => 'h264']);
        $wire = $out['wireOptions'];

        $this->assertSame(8_388_608, $wire['target_size_bytes']);
        $this->assertSame('target_size', $wire['encoding_mode']);
        $this->assertSame('h264', $wire['codec']);
        $this->assertArrayNotHasKey('targetSize', $wire);
        $this->assertSame(
            ['codec', 'encoding_mode', 'target_size_bytes'],
            $out['resolvedOptions']->sources->explicit,
        );
    }

    public function testTargetSizeIntegerPassesThrough(): void
    {
        $out = PresetResolver::resolveCompress('video', null, null, null, null, ['targetSize' => 5_000_000, 'codec' => 'h264']);
        $this->assertSame(5_000_000, $out['wireOptions']['target_size_bytes']);
        $this->assertSame('target_size', $out['wireOptions']['encoding_mode']);
    }

    public function testExplicitCrfDerivesCrfEncodingMode(): void
    {
        $out = PresetResolver::resolveCompress('video', null, null, null, null, ['crf' => 23, 'codec' => 'h264']);
        $this->assertSame('crf', $out['wireOptions']['encoding_mode']);
        $this->assertSame(23, $out['wireOptions']['crf']);
    }

    public function testLowerLayerCrfDroppedWhenTargetSizeWins(): void
    {
        // sdkDefault video/Size carries crf:30; an explicit targetSize must
        // supersede it (encoding_mode=target_size) AND drop the orphaned crf
        // from both the wire and the source buckets (TS codex r1 HIGH).
        $out = PresetResolver::resolveCompress('video', null, null, null, OptimizeFor::Size, ['targetSize' => '8MB', 'codec' => 'h264']);
        $wire = $out['wireOptions'];
        $ro = $out['resolvedOptions'];

        $this->assertArrayNotHasKey('crf', $wire);
        $this->assertSame('target_size', $wire['encoding_mode']);
        $this->assertNotContains('crf', $ro->sources->sdkDefault);
        $this->assertNotContains('crf', $ro->sources->explicit);
    }

    // -----------------------------------------------------------------
    // presetConfigHash — cross-language determinism pins
    // -----------------------------------------------------------------

    public function testPresetConfigHashExactForSingleOverride(): void
    {
        $out = PresetResolver::resolveCompress('image', null, null, ['quality' => 70], null, []);
        $this->assertSame(
            'sha256:5a5b9d555e824e78f2a06f0b57fe9c5c09c9e2fc396d79f2b0510a457018bd23',
            $out['resolvedOptions']->presetConfigHash,
        );
    }

    public function testPresetConfigHashSortsKeysDeterministically(): void
    {
        // CROSS-ANCHORED with TS. metadata + quality, regardless of insertion
        // order, hash to the SAME byte-identical value (recursive key-sort,
        // camelCase record) — the TS suite pins this exact digest too.
        $a = PresetResolver::resolveCompress('image', null, null, ['quality' => 70, 'metadata' => ImageMetadataPolicy::All], null, []);
        $b = PresetResolver::resolveCompress('image', null, null, ['metadata' => ImageMetadataPolicy::All, 'quality' => 70], null, []);

        $this->assertSame(
            $a['resolvedOptions']->presetConfigHash,
            $b['resolvedOptions']->presetConfigHash,
        );
        $this->assertSame(
            'sha256:62daaae9d0b8717221d87f7a2e9823cea7fd833220b5cda7cdcd9c845f96c104',
            $a['resolvedOptions']->presetConfigHash,
        );
    }

    public function testPresetConfigHashForClientDefaultOnly(): void
    {
        // CROSS-ANCHORED with TS (SVQcoR1K). PHP and TS now reconstruct a
        // registered client-default cell into the SAME sparse camelCase record
        // ({quality:75}), so the clientDefault-layer hash is byte-identical
        // across SDKs — the TS suite pins this exact digest too. (Previously a
        // within-PHP-only pin: TS carried extra undefined-valued keys into the
        // hash input; fixed by normalising TS's presetDefaultsCellRecord.)
        $defaults = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 75));
        $out = PresetResolver::resolveCompress('image', $defaults, null, null, OptimizeFor::Size, []);
        $this->assertSame(
            'sha256:2d0bc3473e067653a67d92c0e03ff3666ff498f737b74b9550eb116e91bb2c96',
            $out['resolvedOptions']->presetConfigHash,
        );
    }

    public function testPresetConfigHashForClientDefaultEnumField(): void
    {
        // CROSS-ANCHORED with TS (SVQcoR1K). Guards enum->wire conversion on
        // the REGISTERED-cell path: the canonical clientDefault record is
        // {outputFormat:"webp"} (camelCase key, wire enum value). The override
        // anchors only exercise enum parity on the override path — this is the
        // only guard for the registered-cell path.
        $defaults = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(outputFormat: ImageFormat::Webp));
        $out = PresetResolver::resolveCompress('image', $defaults, null, null, OptimizeFor::Size, []);
        $this->assertSame(
            'sha256:77af8c77695cc88aed893346ccb5e74b6a2c0df8596ce01854cd312578c69878',
            $out['resolvedOptions']->presetConfigHash,
        );
    }

    public function testPresetConfigHashForScopedDefaultOnly(): void
    {
        // CROSS-ANCHORED with TS (SVQcoR1K). scopedDefault (P7) shares the same
        // record-normalisation path as clientDefault, so its hash must also be
        // byte-identical across SDKs. Canonical input:
        // {clientDefault:null, scopedDefault:{quality:88}, callPresetOverride:null}.
        $scoped = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 88));
        $out = PresetResolver::resolveCompress('image', null, $scoped, null, OptimizeFor::Size, []);
        $this->assertSame(
            'sha256:4db0c1f71bf073f0031652da39ee1124a11793b8ff8aa26ea2c2af55a3e9272d',
            $out['resolvedOptions']->presetConfigHash,
        );
    }

    public function testEmptyRegisteredOverrideHashesAsEmptyObject(): void
    {
        // A registered-but-empty override participates (presence), and its
        // canonical form is `{}` — NOT `[]`. Locks the PHP empty-array edge.
        $out = PresetResolver::resolveCompress('image', null, null, [], null, []);
        $this->assertSame(
            'sha256:1ff6cf5e4bcbc2dbeb458597b0726417ab369f013c4696b3b1a485a475cfb25d',
            $out['resolvedOptions']->presetConfigHash,
        );
    }

    public function testPresetConfigHashKeepsFalsyClientDefaultValue(): void
    {
        // CROSS-ANCHORED with TS. v2.80.0 dropped the boolean `progressive`
        // field that previously guarded falsy-KEEP symmetry, so this uses a
        // falsy NUMBER (quality:0): PHP leaf normalisation keeps a defined-but-
        // falsy value (never drops 0/false), matching TS `definedFieldsOf`. A
        // regression dropping the registered cell because its only value is
        // falsy would make the hash disappear.
        $defaults = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 0));
        $out = PresetResolver::resolveCompress('image', $defaults, null, null, OptimizeFor::Size, []);
        $this->assertSame(
            'sha256:65a242e8070dccc9befe4770232de2c9b2f83eb942e1644212dcafc26b03e8bb',
            $out['resolvedOptions']->presetConfigHash,
        );
    }

    // -----------------------------------------------------------------
    // Validations (post-merge)
    // -----------------------------------------------------------------

    public function testTargetSizeWithNonH264ThrowsInvalidCombination(): void
    {
        try {
            PresetResolver::resolveCompress('video', null, null, null, null, ['targetSize' => '8MB', 'codec' => 'vp9']);
            $this->fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            $this->assertSame('invalid_combination', $e->reason);
            $this->assertSame(['targetSize', 'codec'], $e->conflictingFields);
        }
    }

    public function testTargetSizeWithExplicitCrfThrowsInvalidCombination(): void
    {
        try {
            PresetResolver::resolveCompress('video', null, null, null, null, ['targetSize' => '8MB', 'crf' => 23]);
            $this->fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            $this->assertSame('invalid_combination', $e->reason);
            $this->assertSame(['targetSize', 'crf'], $e->conflictingFields);
        }
    }

    public function testUnknownFieldThrows(): void
    {
        try {
            PresetResolver::resolveCompress('image', null, null, null, null, ['bogus' => 1]);
            $this->fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            $this->assertSame('unknown_field', $e->reason);
            $this->assertSame(['bogus'], $e->conflictingFields);
        }
    }

    public function testMismatchedOverrideMediaThrowsTypeMismatch(): void
    {
        try {
            // Video-only field set passed as image presetOverrides.
            PresetResolver::resolveCompress('image', null, null, ['codec' => 'h264'], null, []);
            $this->fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            $this->assertSame('type_mismatch', $e->reason);
            $this->assertSame(['codec'], $e->conflictingFields);
            $this->assertNotNull($e->suggestion);
            $this->assertStringContainsString('VideoCompressPresetOptions', (string) $e->suggestion);
        }
    }

    /**
     * cySAEZHR: `width` is a legal image resize, expressible via output(). It
     * used to be blamed on video, because MEDIA_FIELDS['video'] owns
     * width/height/fit and image does not — so a user attempting a resize was
     * told they had passed video options to an image.
     */
    public function testImageResizeOverrideNamesOutputVerbNotVideo(): void
    {
        try {
            PresetResolver::resolveCompress('image', null, null, ['width' => 800], null, []);
            $this->fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            $this->assertSame('type_mismatch', $e->reason);
            $this->assertSame(['width'], $e->conflictingFields);
            $this->assertStringContainsString('output()', $e->getMessage());
            $this->assertStringNotContainsString('video', $e->getMessage());
            $this->assertStringContainsString('output(format', (string) $e->suggestion);
        }
    }

    public function testImageMultipleCrossVerbOverridesNameOutputOnce(): void
    {
        try {
            PresetResolver::resolveCompress(
                'image',
                null,
                null,
                ['width' => 800, 'height' => 600, 'fit' => 'max'],
                null,
                [],
            );
            $this->fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            $this->assertSame(['width', 'height', 'fit'], $e->conflictingFields);
            $this->assertStringContainsString('are options on output()', $e->getMessage());
        }
    }

    /**
     * Classification is PER FIELD: a legal image `width` must still be reported
     * as a resize on output() even when it arrives next to a bogus key.
     */
    public function testImageResizeMixedWithVideoOnlyKeyReportsBothSeparately(): void
    {
        try {
            PresetResolver::resolveCompress('image', null, null, ['width' => 800, 'codec' => 'h264'], null, []);
            $this->fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            $this->assertSame('type_mismatch', $e->reason);
            $this->assertStringContainsString('output()', $e->getMessage());
            $this->assertStringContainsString('codec', $e->getMessage());
            $this->assertSame(['width', 'codec'], $e->conflictingFields);
        }
    }

    /**
     * The suggestion must be copy-pasteable: the output() surface is
     * snake_case, so echoing the camelCase preset spelling back would hand the
     * caller a call that does not work.
     */
    public function testImageCrossVerbSuggestionUsesOutputSpelling(): void
    {
        try {
            PresetResolver::resolveCompress('image', null, null, ['autoOrient' => true], null, []);
            $this->fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            $this->assertStringContainsString('auto_orient', $e->getMessage());
            $this->assertStringContainsString('auto_orient', (string) $e->suggestion);
            $this->assertStringNotContainsString('autoOrient', (string) $e->suggestion);
        }
    }

    public function testAudioOutputFormatOverrideNamesConvertVerb(): void
    {
        try {
            PresetResolver::resolveCompress('audio', null, null, ['outputFormat' => 'mp3'], null, []);
            $this->fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            $this->assertSame('type_mismatch', $e->reason);
            $this->assertStringContainsString('convert()', $e->getMessage());
        }
    }

    public function testUnknownMediaThrows(): void
    {
        $this->expectException(GislConfigError::class);
        PresetResolver::resolveCompress('hologram', null, null, null, null, []);
    }

    // -----------------------------------------------------------------
    // parseTargetSize
    // -----------------------------------------------------------------

    public function testParseTargetSizeBinaryUnits(): void
    {
        $this->assertSame(1024, PresetResolver::parseTargetSize('1KB'));
        $this->assertSame(8_388_608, PresetResolver::parseTargetSize('8MB'));
        $this->assertSame(1_610_612_736, PresetResolver::parseTargetSize('1.5GB'));
        $this->assertSame(100, PresetResolver::parseTargetSize('100')); // bare → bytes
        $this->assertSame(100, PresetResolver::parseTargetSize('100B'));
        $this->assertSame(2048, PresetResolver::parseTargetSize(2048)); // int passthrough
    }

    public function testParseTargetSizeRejectsBadInput(): void
    {
        foreach (['abc', '5XB', '0', '-5', ''] as $bad) {
            try {
                PresetResolver::parseTargetSize($bad);
                $this->fail("expected GislConfigError for '{$bad}'");
            } catch (GislConfigError $e) {
                $this->assertSame('invalid_target_size', $e->reason, "input '{$bad}'");
            }
        }
    }

    public function testParseTargetSizeRejectsNonPositiveInteger(): void
    {
        $this->expectException(GislConfigError::class);
        PresetResolver::parseTargetSize(0);
    }

    // -----------------------------------------------------------------
    // Cross-media coverage (non-image leaf wired correctly)
    // -----------------------------------------------------------------

    public function testVideoShippedDefaultsResolveThroughLeaf(): void
    {
        $out = PresetResolver::resolveCompress('video', null, null, null, OptimizeFor::Balanced, []);
        // video_compress/Balanced cell: crf 23, preset medium, audio_bitrate.
        // v2.66.0 (ADR-0020): the cell no longer bakes codec (server
        // container-resolves it), so it is absent from the wire + sdkDefault.
        $this->assertSame(23, $out['wireOptions']['crf']);
        $this->assertArrayNotHasKey('codec', $out['wireOptions']);
        $this->assertContains('crf', $out['resolvedOptions']->sources->sdkDefault);
        $this->assertNotContains('codec', $out['resolvedOptions']->sources->sdkDefault);
    }

    public function testVideoPresetOverrideAsLeafDto(): void
    {
        // presetOverrides supplied as a typed leaf DTO instance.
        $out = PresetResolver::resolveCompress(
            'video',
            null,
            null,
            new VideoCompressPresetOptions(codec: VideoCodec::H265),
            null,
            [],
        );
        $this->assertSame('h265', $out['wireOptions']['codec']);
        $this->assertSame(['codec'], $out['resolvedOptions']->sources->callPresetOverride);
    }

    // -----------------------------------------------------------------
    // Additional coverage (review follow-ups)
    // -----------------------------------------------------------------

    public function testClientDefaultMergesFieldByFieldWithShipped(): void
    {
        // Client sets only `quality`; the shipped `metadata` must survive (the
        // client layer must NOT wipe the whole sdkDefault layer).
        $defaults = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 75));
        $out = PresetResolver::resolveCompress('image', $defaults, null, null, OptimizeFor::Size, []);
        $ro = $out['resolvedOptions'];

        $this->assertSame(75, $out['wireOptions']['quality']);
        // v2.107.0 metadata rename: the surviving shipped `metadata` is now `strip`.
        $this->assertSame('strip', $out['wireOptions']['metadata']);
        $this->assertSame(['quality'], $ro->sources->clientDefault);
        $this->assertContains('metadata', $ro->sources->sdkDefault);
    }

    public function testTargetSizeSourcedFromClientDefaultAttributesDerivedKeys(): void
    {
        // targetSize set via a client default (NOT explicit) — the derived
        // wire keys must be attributed to the clientDefault bucket (exercises
        // the non-explicit arm of targetSizeSource()).
        $defaults = PresetDefaults::create()->videoCompress(
            OptimizeFor::Size,
            new VideoCompressPresetOptions(codec: VideoCodec::H264, targetSize: '100MB'),
        );
        $out = PresetResolver::resolveCompress('video', $defaults, null, null, OptimizeFor::Size, []);
        $wire = $out['wireOptions'];
        $ro = $out['resolvedOptions'];

        $this->assertSame(104_857_600, $wire['target_size_bytes']);
        $this->assertSame('target_size', $wire['encoding_mode']);
        $this->assertSame('h264', $wire['codec']);
        $this->assertContains('target_size_bytes', $ro->sources->clientDefault);
        $this->assertContains('encoding_mode', $ro->sources->clientDefault);
        $this->assertArrayNotHasKey('crf', $wire); // sdk crf dropped under target_size
    }

    public function testScopedDefaultStaysEmptyInP6(): void
    {
        $defaults = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 75));
        $out = PresetResolver::resolveCompress('image', $defaults, null, ['metadata' => ImageMetadataPolicy::All], OptimizeFor::Size, ['quality' => 100]);
        // scopedDefault is reserved for P7 — always empty regardless of the
        // other layers being populated.
        $this->assertSame([], $out['resolvedOptions']->sources->scopedDefault);
    }

    public function testMixedOverrideKeysFallThroughToUnknownField(): void
    {
        // `bogus` belongs to no media, so detectMismatchedOverrides must NOT
        // raise type_mismatch — it falls through to the merged unknown_field
        // check (reason unknown_field, not type_mismatch).
        try {
            PresetResolver::resolveCompress('image', null, null, ['quality' => 70, 'bogus' => 1], null, []);
            $this->fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            $this->assertSame('unknown_field', $e->reason);
            $this->assertSame(['bogus'], $e->conflictingFields);
        }
    }

    // -----------------------------------------------------------------
    // Scoped layer (P7 / 5k3ZWo6B) — layer 3
    // -----------------------------------------------------------------

    public function testScopedDefaultBeatsClientDefault(): void
    {
        $client = PresetDefaults::create()->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 70));
        $scoped = PresetDefaults::create()->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 85));

        $out = PresetResolver::resolveCompress('image', $client, $scoped, null, OptimizeFor::Size, []);
        $ro = $out['resolvedOptions'];

        $this->assertSame(85, $out['wireOptions']['quality']);
        $this->assertSame(['quality'], $ro->sources->scopedDefault);
        $this->assertNotContains('quality', $ro->sources->clientDefault);
        $this->assertNotContains('quality', $ro->sources->sdkDefault);
    }

    public function testExplicitBeatsScopedDefault(): void
    {
        $scoped = PresetDefaults::create()->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 85));

        $out = PresetResolver::resolveCompress('image', null, $scoped, null, OptimizeFor::Size, ['quality' => 100]);
        $ro = $out['resolvedOptions'];

        $this->assertSame(100, $out['wireOptions']['quality']);
        $this->assertSame(['quality'], $ro->sources->explicit);
        $this->assertNotContains('quality', $ro->sources->scopedDefault);
    }

    public function testScopedMergeNotReplacePreservesClientField(): void
    {
        // T4c headline footgun: a scoped `quality` override must NOT wipe the
        // client-default's `outputFormat` — they land in distinct buckets and
        // both reach the wire.
        $client = PresetDefaults::create()->imageCompress(
            OptimizeFor::Size,
            new ImageCompressPresetOptions(outputFormat: ImageFormat::Webp),
        );
        $scoped = PresetDefaults::create()->imageCompress(
            OptimizeFor::Size,
            new ImageCompressPresetOptions(quality: 92),
        );

        $out = PresetResolver::resolveCompress('image', $client, $scoped, null, OptimizeFor::Size, []);
        $ro = $out['resolvedOptions'];

        $this->assertSame(92, $out['wireOptions']['quality']);
        $this->assertArrayHasKey('output_format', $out['wireOptions']);
        $this->assertSame(['quality'], $ro->sources->scopedDefault);
        $this->assertSame(['output_format'], $ro->sources->clientDefault);
        $this->assertIsString($ro->presetConfigHash);
    }

    public function testParseTargetSizeRejectsNonStringNonIntWithConflictingFields(): void
    {
        foreach ([1.5, true, []] as $bad) {
            try {
                PresetResolver::parseTargetSize($bad);
                $this->fail('expected GislConfigError for ' . \get_debug_type($bad));
            } catch (GislConfigError $e) {
                $this->assertSame('invalid_target_size', $e->reason);
                $this->assertSame(['targetSize'], $e->conflictingFields);
            }
        }
    }

    public function testWrongMediaTypedDtoRejectedEvenWithOverlappingFields(): void
    {
        // `width` exists on both image and video, so field-based detection
        // can't catch it — but the DTO's class media (video) does not match
        // the operation media (image), so the class guard rejects it.
        try {
            PresetResolver::resolveCompress(
                'image',
                null,
                null,
                new VideoCompressPresetOptions(width: 100),
                null,
                [],
            );
            $this->fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            $this->assertSame('type_mismatch', $e->reason);
            $this->assertSame(['presetOverrides'], $e->conflictingFields);
        }
    }

    public function testExplicitCrfNullStillDerivesEncodingMode(): void
    {
        // An explicit `crf => null` array key is PRESENT (mirrors TS
        // `!== undefined`), so encoding_mode='crf' is still derived.
        $out = PresetResolver::resolveCompress('video', null, null, null, null, ['crf' => null]);
        $this->assertArrayHasKey('encoding_mode', $out['wireOptions']);
        $this->assertSame('crf', $out['wireOptions']['encoding_mode']);
    }

    public function testArrayExplicitNullPreserved(): void
    {
        // Array-provided null is intentional (no `undefined` in PHP) and is
        // kept, unlike a sparse DTO's null which is dropped.
        $out = PresetResolver::resolveCompress('image', null, null, null, null, ['quality' => null]);
        $this->assertArrayHasKey('quality', $out['wireOptions']);
        $this->assertNull($out['wireOptions']['quality']);
    }

    // -----------------------------------------------------------------
    // 0Vcogefw — audio_compress lossless bitrate drop. Mirrors the TS
    // `resolveCompressOptions — audio lossless bitrate drop` block.
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{OptimizeFor, int, int}>
     */
    public static function audioLevels(): array
    {
        return [
            'Size' => [OptimizeFor::Size, 96, 44100],
            'Balanced' => [OptimizeFor::Balanced, 192, 44100],
            'Quality' => [OptimizeFor::Quality, 320, 48000],
        ];
    }

    #[DataProvider('audioLevels')]
    public function testLossyAudioKeepsShippedBitrate(OptimizeFor $optimize, int $expectedBitrate, int $expectedSampleRate): void
    {
        $out = PresetResolver::resolveCompress('audio', null, null, null, $optimize, [], audioLossless: false);
        $wire = $out['wireOptions'];

        $this->assertSame($expectedBitrate, $wire['bitrate']);
        $this->assertSame($expectedSampleRate, $wire['sample_rate']);
        $this->assertArrayHasKey('normalize', $wire);
        $this->assertContains('bitrate', $out['resolvedOptions']->sources->sdkDefault);
    }

    #[DataProvider('audioLevels')]
    public function testLosslessAudioDropsShippedBitrateButKeepsRest(OptimizeFor $optimize, int $unusedBitrate, int $expectedSampleRate): void
    {
        $out = PresetResolver::resolveCompress('audio', null, null, null, $optimize, [], audioLossless: true);
        $wire = $out['wireOptions'];

        $this->assertArrayNotHasKey('bitrate', $wire);
        $this->assertSame($expectedSampleRate, $wire['sample_rate']);
        $this->assertArrayHasKey('normalize', $wire);
        // No layer is credited with a bitrate after the drop.
        $this->assertNotContains('bitrate', $out['resolvedOptions']->sources->sdkDefault);
        $this->assertArrayNotHasKey('bitrate', $out['resolvedOptions']->applied);
    }

    public function testLosslessAudioOptimizeUnsetDoesNotCrash(): void
    {
        // No optimize ⇒ no sdkDefault layer ⇒ no bitrate to drop.
        $out = PresetResolver::resolveCompress('audio', null, null, null, null, [], audioLossless: true);
        $this->assertSame([], $out['wireOptions']);
    }

    public function testLosslessAudioKeepsExplicitBitrate(): void
    {
        // Worker-authoritative: a user-supplied (explicit) bitrate is NEVER
        // dropped — it reaches the wire so the worker's 422 surfaces.
        $out = PresetResolver::resolveCompress(
            'audio',
            null,
            null,
            null,
            OptimizeFor::Size,
            ['bitrate' => AudioBitrate::_320],
            audioLossless: true,
        );
        $this->assertSame(320, $out['wireOptions']['bitrate']);
        $this->assertContains('bitrate', $out['resolvedOptions']->sources->explicit);
    }

    public function testLosslessAudioKeepsPresetOverrideBitrate(): void
    {
        $out = PresetResolver::resolveCompress(
            'audio',
            null,
            null,
            ['bitrate' => AudioBitrate::_192],
            OptimizeFor::Size,
            [],
            audioLossless: true,
        );
        $this->assertSame(192, $out['wireOptions']['bitrate']);
        $this->assertContains('bitrate', $out['resolvedOptions']->sources->callPresetOverride);
    }

    public function testLosslessAudioKeepsClientDefaultBitrate(): void
    {
        $defaults = PresetDefaults::create()
            ->audioCompress(OptimizeFor::Size, new AudioCompressPresetOptions(bitrate: AudioBitrate::_320));
        $out = PresetResolver::resolveCompress('audio', $defaults, null, null, OptimizeFor::Size, [], audioLossless: true);

        $this->assertSame(320, $out['wireOptions']['bitrate']);
        $this->assertContains('bitrate', $out['resolvedOptions']->sources->clientDefault);
    }

    public function testAudioLosslessFlagInertForNonAudioMedia(): void
    {
        // Defensive: the flag does nothing for image (no bitrate concept).
        $out = PresetResolver::resolveCompress('image', null, null, null, OptimizeFor::Size, [], audioLossless: true);
        $this->assertSame(65, $out['wireOptions']['quality']);
        $this->assertArrayNotHasKey('bitrate', $out['wireOptions']);
    }
}
