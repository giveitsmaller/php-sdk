<?php

declare(strict_types=1);

namespace Gisl\Sdk;

use Gisl\Generated\OpenApi\Model\AccountLimits;
use Gisl\Generated\OpenApi\Model\CreditsBalanceResponse;
use Gisl\Generated\OpenApi\Model\CreditsUsageResponse;
use Gisl\Generated\OpenApi\Model\OperationCapability;
use Gisl\Sdk\Ergonomic\Asset;
use Gisl\Sdk\Ergonomic\CapabilitiesSnapshot;
use Gisl\Sdk\Ergonomic\Handle;
use Gisl\Sdk\Ergonomic\Merge;
use Gisl\Sdk\Ergonomic\MergeBuilder;
use Gisl\Sdk\Ergonomic\MergeOptions;
use Gisl\Sdk\Ergonomic\OperationBuilder;
use Gisl\Sdk\Ergonomic\OptionValidation;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\FilesRecipe;
use Gisl\Sdk\FileFirst\Recipe;
use Gisl\Sdk\Http\MultipartPartUploader;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Ergonomic-surface subclass of {@see GislClient}. Adds per-operation
 * factory methods that return an {@see OperationBuilder} ready for
 * `->run(...)` or `->submit(...)`. The low-level surface (`uploadFile`,
 * `createWorkflow`, etc.) is inherited verbatim — instances of this
 * class are full `GislClient` substitutes (LSP-safe; `instanceof
 * GislClient` continues to hold).
 *
 * Mirrors the TS Proxy at `packages/typescript/src/gisl.ts:102-133`
 * (`wrapErgonomic`). PHP has no Proxy primitive, so we use the
 * idiomatic equivalent: a subclass that adds the factory methods. See
 * the docblock on {@see GislClient} for the deliberate un-finalling
 * marker.
 *
 * Instantiation: call {@see Gisl::create()} — the inner factory
 * constructs a `GislErgonomicClient` (returned via the covariant
 * `Gisl::create(): GislErgonomicClient` signature). Direct construction
 * is supported for tests but the credential-chain resolution is the
 * ergonomic factory's job.
 */
class GislErgonomicClient extends GislClient
{
    /**
     * Mirrors the TS `wrapErgonomic` closure (`gisl.ts:116-136`) that captures
     * `presetDefaults` and injects it into each `new OperationBuilder(...)`.
     * The extra parameter is appended AFTER the inherited four so existing
     * positional (`Gisl::createInternal`) and named-argument (parity adapter)
     * construction keep working — it defaults to null. LSP holds:
     * `instanceof GislClient` is unaffected.
     */
    /**
     * Scoped preset defaults attached via {@see withPresetDefaults()} (P7 /
     * `5k3ZWo6B`). Layered between the client-default and per-call-override
     * layers in the resolver. NON-readonly because the immutable derive is a
     * clone-wither and PHP 8.1 forbids modifying a readonly property on a
     * clone; only {@see withPresetDefaults()} ever writes it, and only on the
     * freshly-cloned copy — `$this` is never mutated. (`sessionCookie` on the
     * parent {@see GislClient} is the existing non-readonly-property precedent.)
     */
    private ?PresetDefaults $scopedPresetDefaults;

    public function __construct(
        GislClientConfig $config,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly ?PresetDefaults $presetDefaults = null,
        ?PresetDefaults $scopedPresetDefaults = null,
        ?MultipartPartUploader $partUploader = null,
    ) {
        parent::__construct($config, $httpClient, $requestFactory, $streamFactory, $partUploader);
        $this->scopedPresetDefaults = $scopedPresetDefaults;
    }

    /**
     * Immutable scoped derive (P7 / `5k3ZWo6B`, TS T4c). Returns a NEW client
     * that resolves compress presets with `$defaults` layered on top of any
     * existing scoped defaults — `$defaults`' per-cell fields win, the prior
     * scope fills the gaps (see {@see PresetDefaults::merge()}). Chains:
     * `$client->withPresetDefaults($a)->withPresetDefaults($b)`.
     *
     * The derive is a `clone` of this client, so the underlying transport
     * (`httpClient`/`requestFactory`/`streamFactory`), `config`, credentials,
     * and the `sessionCookie` value are carried over BY the clone — there is
     * NO env/profile re-resolution and the parent is left completely unchanged
     * (concurrency-safe).
     *
     * **Divergence from the TS reference (intentional):** TS `withPresetDefaults`
     * returns a Proxy wrapping the SAME live `GislClient` target
     * (`packages/typescript/src/gisl.ts:141-156`), so a later `login()` on the
     * parent is observed by the derived client. PHP has no Proxy seam — this
     * subclass IS the client — so `clone` takes a derive-time SNAPSHOT of the
     * session-cookie state; a post-derive `login()`/`logout()` on the parent
     * (or child) does NOT propagate to the other. This satisfies the "preserve
     * session-cookie state exactly" contract at derive time and is harmless for
     * the documented "configure the next N jobs" use case.
     */
    public function withPresetDefaults(PresetDefaults $defaults): self
    {
        $derived = clone $this;
        $derived->scopedPresetDefaults = $this->scopedPresetDefaults === null
            ? $defaults
            : PresetDefaults::merge($this->scopedPresetDefaults, $defaults);

        return $derived;
    }

    /**
     * File-first entry point — the subject of the file-first ergonomic surface.
     * Returns an immutable {@see Recipe} you call operations on
     * (`->compress()`, `->convert()`, `->thumbnail()`, `->textWatermark()`),
     * chaining sequentially. A bare string is treated as a filesystem path; pass
     * a {@see FileInput} (e.g. {@see FileInput::uploadId()}) to reuse a
     * pre-uploaded file.
     *
     * `$key` is RESULT-addressing only (`$result->byKey($key)` in FF2b) — never
     * input wiring.
     *
     * FF2a builds the recipe + lowering only; execution (`run()`) lands in FF2b.
     */
    public function file(string|FileInput $input, ?string $key = null): Recipe
    {
        $fileInput = $input instanceof FileInput ? $input : FileInput::path($input);

        return new Recipe($fileInput, $key, [], $this->presetDefaults, $this->scopedPresetDefaults, $this);
    }

    /**
     * Homogeneous fan-out entry point (FF3a / u0hBt6fl). Apply ONE recipe (op
     * chain) to MANY input files in ONE workflow. Each element is a filesystem
     * path (string, auto-wrapped via {@see FileInput::path()}) or a
     * {@see FileInput} (e.g. {@see FileInput::uploadId()}) passed through.
     * Returns an immutable {@see FilesRecipe} you call the same ops on
     * (`->compress()` / `->convert()` / `->thumbnail()` / `->textWatermark()`);
     * the chain applies to every input. `run()` returns a partitioned
     * {@see \Gisl\Sdk\FileFirst\RunResult} keyed by each input's 0-based index —
     * one bad input does not sink the rest. `submit()` is out of scope (a
     * separate card).
     *
     * @param list<string|FileInput> $inputs Ordered input files.
     */
    public function files(array $inputs): FilesRecipe
    {
        if ($inputs === []) {
            // A zero-input fan-out is a caller error — "one bad input does not
            // sink the rest" is meaningless with no inputs, and it would
            // otherwise create an empty-jobs workflow the API 422s.
            throw new GislConfigError(
                'files() requires at least one input file.',
                reason: 'no_inputs',
            );
        }

        $resolved = [];
        foreach ($inputs as $input) {
            $resolved[] = $input instanceof FileInput ? $input : FileInput::path($input);
        }

        return new FilesRecipe($resolved, [], $this->presetDefaults, $this->scopedPresetDefaults, $this);
    }

    /**
     * Reattach to a previously-created workflow (FF5a / Ao8RPVxD). Returns a
     * client-bound {@see Handle} you can `->status()` / `->wait()` /
     * `->result()`. The handle carries no `webhookSecret` and no recipe key, so
     * the {@see \Gisl\Sdk\FileFirst\RunResult} from `wait()`/`result()` is
     * keyless (`succeeded[].key === null`) — address its outputs positionally
     * or via the sinks rather than `byKey()`.
     */
    public function workflow(string $id): Handle
    {
        return new Handle($id, null, $this);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function compress(string $input, array $options = []): OperationBuilder
    {
        return new OperationBuilder($this, 'compress', $input, $options, $this->presetDefaults, $this->scopedPresetDefaults);
    }

    /**
     * Generate a preview thumbnail. `width` + `height` are REQUIRED (the contract
     * marks both required for image/video/document); an unknown option key or a
     * missing dimension is rejected pre-upload (ExVcchMz), not as a server 422.
     *
     * `width` + `height` are REQUIRED at runtime (enforced by the guard below);
     * the shape keys are all marked optional only so the `= []` default type-checks.
     *
     * @param array{
     *   width?: int,
     *   height?: int,
     *   fit?: 'max'|'crop'|'scale',
     *   format?: 'jpg'|'png'|'webp',
     *   quality?: int,
     *   background?: string,
     *   timestamp?: string,
     *   source?: 'page'|'cover',
     *   page?: int,
     * } $options
     */
    public function thumbnail(string $input, array $options = []): OperationBuilder
    {
        OptionValidation::validateVerbOptions('thumbnail', $options);
        OptionValidation::assertThumbnailDimensions($options);

        return new OperationBuilder($this, 'thumbnail', $input, $options, $this->presetDefaults, $this->scopedPresetDefaults);
    }

    /**
     * Convert to another format. The single-op builder has no positional format —
     * the target rides the bag as the wire key `output_format` (REQUIRED). An
     * unknown option key or a missing `output_format` is rejected pre-upload
     * (ExVcchMz), not as a server 422.
     *
     * `output_format` is REQUIRED at runtime (enforced by the guard below); the
     * shape keys are all marked optional only so the `= []` default type-checks.
     *
     * @param array{
     *   output_format?: string,
     *   quality?: int,
     *   background?: string,
     *   crf?: int,
     *   trim_start?: float,
     *   trim_end?: float,
     *   fps?: int|float,
     *   width?: int,
     *   height?: int,
     *   fit?: 'max'|'crop'|'scale',
     *   metadata?: 'strip'|'keep',
     *   color_profile?: 'keep'|'srgb'|'strip',
     *   auto_orient?: bool,
     *   max_colors?: int,
     *   loop?: int,
     *   dither?: 'none'|'bayer'|'floyd_steinberg'|'sierra2'|'sierra2_4a',
     *   bitrate?: 64|96|128|192|256|320,
     *   pages?: string,
     *   dpi?: int,
     * } $options
     */
    public function convert(string $input, array $options = []): OperationBuilder
    {
        OptionValidation::validateSingleOpConvertOptions($options);

        return new OperationBuilder($this, 'convert', $input, $options, $this->presetDefaults, $this->scopedPresetDefaults);
    }

    /**
     * Multi-input merge compose. PHP P3 / dxIeLVbP. Mirrors the TS
     * reference `client.merge(...assets, options?)` at
     * `packages/typescript/src/gisl.ts:115-128` — PHP collapses the
     * variadic+last-arg-options TS idiom into an explicit array + named
     * options arg (matches the rest of the SDK's signature shape).
     *
     * Bare strings are auto-wrapped via {@see Merge::asset()}; pre-uploaded
     * file_ids enter the asset set via {@see Merge::handle()}. See the
     * {@see MergeBuilder} docblock for wire-truth boundaries per media kind.
     *
     * @param list<Asset|string> $assets Declared assets (paths or handles).
     *                                   At least 2 are required at run/submit;
     *                                   image/audio/video kind is inferred from
     *                                   the first asset's path unless
     *                                   {@see MergeOptions::$mediaKind} is set.
     */
    public function merge(array $assets, ?MergeOptions $options = null): MergeBuilder
    {
        $coerced = [];
        foreach ($assets as $a) {
            $coerced[] = $a instanceof Asset ? $a : Merge::asset($a);
        }
        return new MergeBuilder($this, $coerced, $options ?? new MergeOptions());
    }

    // 8yqUXLCS — first-class ergonomic billing/limits accessors (thin fluent
    // aliases over the inherited low-level getters, surfaced + documented here).

    /** Current credit balance (sugar for {@see GislClient::getCreditsBalance()}). */
    public function credits(): CreditsBalanceResponse
    {
        return $this->getCreditsBalance();
    }

    /** Credit usage history (sugar for {@see GislClient::getCreditsUsage()}). */
    public function creditsUsage(?CreditsUsageOptions $options = null): CreditsUsageResponse
    {
        return $this->getCreditsUsage($options);
    }

    /** Effective account limits / tier-resolved caps (sugar for {@see GislClient::getAccountLimits()}). */
    public function limits(): AccountLimits
    {
        return $this->getAccountLimits();
    }

    /**
     * Operation-capability read helper (qUhxfDA5). A typed projection over
     * {@see GislClient::getSchema()} that surfaces the tier-scoped
     * operation-capability matrix, the output-property table, and the
     * image-encode capability matrix — without dropping to the low-level
     * `getSchema()` and its hit/not-modified result union. Mirrors the TS
     * `capabilities()` on the ergonomic client.
     *
     * Called with no argument it returns the full {@see CapabilitiesSnapshot};
     * called with an operation type it returns just that op's
     * {@see OperationCapability}, or `null` when the op is absent from the
     * server's capability matrix.
     *
     * Degraded fallback: if the client is configured to force conditional
     * revalidation (a static `If-None-Match` header) the schema fetch may 304
     * with no body — in that case the snapshot is empty / the per-op lookup is
     * `null`.
     *
     * @return ($opType is null ? CapabilitiesSnapshot : OperationCapability|null)
     */
    public function capabilities(?string $opType = null): CapabilitiesSnapshot|OperationCapability|null
    {
        $result = $this->getSchema();
        $schema = $result instanceof GetSchemaHitResult ? $result->schema : null;
        /** @var array<string, OperationCapability> $operations */
        $operations = $schema?->getCapabilities() ?? [];
        if ($opType !== null) {
            return $operations[$opType] ?? null;
        }
        return new CapabilitiesSnapshot(
            operations: $operations,
            outputProperties: $schema?->getOutputProperties() ?? [],
            imageEncode: $schema?->getImageEncodeCapabilities(),
        );
    }

    /**
     * Generic operation escape hatch (qUhxfDA5). Build + run any operation type
     * with no first-class verb — e.g. `text_watermark`, `split`, or a
     * not-yet-in-contract op. Builds a SINGLE-input, SINGLE-operation job (the
     * generic sibling of {@see compress()}/{@see convert()}/{@see thumbnail()}).
     * `$options` reach the wire unchanged — there is NO pre-upload validation
     * (the server validates) and NO preset resolution unless `$opType` is
     * `compress`.
     *
     * Multi-input operations (merge, archive, image/video/audio overlay
     * watermarks) canNOT be expressed here — they need multiple sources and have
     * dedicated builders ({@see merge()}, `files(...)->archive(...)`,
     * `file($a)->watermark($b)`). Use those instead.
     *
     * @param array<string, mixed> $options
     */
    public function operation(string $opType, string $input, array $options = []): OperationBuilder
    {
        return new OperationBuilder($this, $opType, $input, $options, $this->presetDefaults, $this->scopedPresetDefaults);
    }

    // `watermark()` and `archive()` factories are NOT shipped in P2/P3.
    //
    // - `watermark`: the v2 `OperationType` enum has NO bare `watermark`
    //   value — the contract split it into `image_watermark` /
    //   `text_watermark` / (planned) `audio_watermark`. A bare
    //   `OperationDef(type: 'watermark', ...)` would be rejected by the
    //   server with a validation error. Wiring this needs a preset-style
    //   mapping that picks the right sub-op for the given input MIME +
    //   options — tracked as a follow-up alongside the preset matrix
    //   (P5+ in the batch plan).
    //
    // - `archive`: the contract models `archive` as a MULTI-INPUT
    //   operation (`JobDefinitionPayload.inputs[]`), but the single-input
    //   `OperationBuilder` here always sends `source`. A correct archive
    //   factory needs a dedicated multi-input/bundle builder — that's
    //   exactly what P4 (.bundle archive sugar) ships.
    //
    // Both verbs stay on the {@see \Gisl\Sdk\Tests\Parity\Invoke}
    // ergonomic seam (NotYetImplementedDispatch) until those follow-ups
    // land. Codex review caught this gap in PHP P2 (7QXkzoIi) before
    // merge — the original plan listed five verbs but only three are
    // structurally implementable today.
}
