<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislError;
use Gisl\Sdk\Errors\NotYetImplementedDispatch;
use Gisl\Sdk\Tests\Parity\Fixture;
use Gisl\Sdk\Tests\Parity\Invoke;
use Gisl\Sdk\Tests\Parity\StubPsr18Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the parity-adapter ergonomic-dispatch seam.
 *
 * Originally (P0 / Bljva8nj) eight verbs short-circuited here. PHP P2
 * (7QXkzoIi) shipped real dispatch for `compress` / `thumbnail` /
 * `convert`; PHP P3 (dxIeLVbP) shipped `merge` via the multi-input
 * dispatch path; PHP P4 (`OMuSCt7y`) unparked `mapEach` (callable on
 * `OperationBuilder` returning a {@see \Gisl\Sdk\Ergonomic\MapEachBuilder}
 * scaffold). Those wired verbs are exercised by the unit tests under
 * `Tests/Unit/Ergonomic/` and by the parity fixtures. Three verbs
 * remain parked on the seam (`watermark` / `archive` / `bundle`) — see
 * `ergonomicMethodProvider` docblock for the per-verb deferral cards.
 *
 * The seam itself lives in {@see Invoke::run()} → `dispatch()` (parity
 * dir, not src/). The error class under test
 * ({@see NotYetImplementedDispatch}) is in src/Errors/ so any future
 * LocalError emitter that filters on `instanceof GislError` picks it up.
 *
 * Seam-targeted fixtures are SYNTHESIZED directly rather than loaded
 * from YAML — `FixtureLoader::KNOWN_SDK_METHODS` does not list the
 * still-parked verbs (it lists the five P2-wired verbs alongside the
 * low-level methods). The seam itself is loader-independent so
 * synthesising covers the contract end-to-end through `Invoke::run()`.
 */
#[CoversClass(NotYetImplementedDispatch::class)]
final class ParityErgonomicSeamTest extends TestCase
{
    /**
     * Every ergonomic verb still parked on the seam (i.e. NOT yet wired
     * end-to-end in {@see Invoke}). Post-PHP-P3 (dxIeLVbP) `merge`
     * shipped via the multi-input dispatch path; PHP P4 (`OMuSCt7y`)
     * unparked `mapEach` (callable via `OperationBuilder::mapEach()` →
     * {@see \Gisl\Sdk\Ergonomic\MapEachBuilder} scaffold, with the
     * KNOWN LIMITATION still documented on the class). Three verbs
     * remain parked — `watermark`/`archive` for preset+multi-input
     * downstream of P5+, `bundle` for `wpHoJhuo` (P4b — single-workflow
     * `.mapEach().bundle()` per `docs/plans/sdk-ergonomics/lowering.md`).
     *
     * @return array<string, array{string}>
     */
    public static function ergonomicMethodProvider(): array
    {
        return [
            'watermark' => ['watermark'],
            'archive' => ['archive'],
            'bundle' => ['bundle'],
        ];
    }

    #[DataProvider('ergonomicMethodProvider')]
    public function test_ergonomic_method_short_circuits_with_structured_error(string $method): void
    {
        $fixture = self::synthesizeFixture("ergonomic_seam_{$method}", $method);
        $stub = new StubPsr18Client([], null);

        $result = Invoke::run($fixture, $stub);

        $this->assertInstanceOf(
            NotYetImplementedDispatch::class,
            $result->thrown,
            "Ergonomic verb \"{$method}\" must short-circuit with NotYetImplementedDispatch.",
        );

        /** @var NotYetImplementedDispatch $err */
        $err = $result->thrown;
        $this->assertSame('not_yet_implemented', $err->code());
        $this->assertSame('config', $err->category());
        $this->assertSame($method, $err->method);
        $this->assertSame(['fixture' => "ergonomic_seam_{$method}"], $err->metadata);
        $this->assertNotNull($err->hint, "Hint should point at the P-card that ships {$method}.");

        // Seam must NOT touch the wire — no captured requests, no
        // transport calls, no temp files.
        $this->assertSame([], $stub->captured());
        $this->assertSame([], $result->tempFiles);
        $this->assertNull($result->returnValue);
        $this->assertNull($result->sseEvents);
    }

    public function test_extends_gisl_error_hierarchy(): void
    {
        $err = new NotYetImplementedDispatch(method: 'compress');

        $this->assertInstanceOf(
            GislConfigError::class,
            $err,
            'NotYetImplementedDispatch must extend GislConfigError so callers catching '
            . 'GislConfigError pick up the pre-I/O capability gap alongside missing-credentials etc.',
        );
        $this->assertInstanceOf(
            GislError::class,
            $err,
            'Future LocalError emitter filters on instanceof GislError; the seam error must satisfy it.',
        );
    }

    public function test_low_level_method_falls_through_to_switch(): void
    {
        // A low-level method that is NOT in ERGONOMIC_METHODS must reach
        // the dispatch switch verbatim. `cancelWorkflow` exercises the
        // POST + JSON-envelope hydration path; the seam not firing AND a
        // request reaching the stub is the assertion.
        $fixture = self::synthesizeFixture(
            name: 'low_level_smoke',
            method: 'cancelWorkflow',
            args: ['019539ac-2222-7000-8000-000000000001'],
        );
        $stub = new StubPsr18Client(
            [[
                'status' => 200,
                'headers' => ['content-type' => 'application/json'],
                'body' => [
                    'type' => 'json',
                    'value' => [
                        'success' => true,
                        'data' => [
                            'workflow_id' => '019539ac-2222-7000-8000-000000000001',
                            'status' => 'cancelled',
                            'cancelled_at' => '2026-04-26T14:00:00Z',
                            'billing_effect' => 'unspent_reservation_released',
                        ],
                    ],
                ],
            ]],
            null,
        );

        $result = Invoke::run($fixture, $stub);

        $this->assertNotInstanceOf(
            NotYetImplementedDispatch::class,
            $result->thrown,
            'Low-level methods must NOT route through the ergonomic seam.',
        );
        $this->assertCount(
            1,
            $stub->captured(),
            'cancelWorkflow must reach the wire — one HTTP request captured.',
        );
    }

    public function test_error_metadata_carries_fixture_name(): void
    {
        // Uses a still-parked verb (`bundle`) so the seam fires; the
        // P2/P3/P4-wired verbs (compress/merge/mapEach/etc.) no longer
        // reach this code path.
        $fixture = self::synthesizeFixture('custom_fixture_label', 'bundle');
        $result = Invoke::run($fixture, new StubPsr18Client([], null));

        $this->assertInstanceOf(NotYetImplementedDispatch::class, $result->thrown);
        /** @var NotYetImplementedDispatch $err */
        $err = $result->thrown;
        $this->assertSame(['fixture' => 'custom_fixture_label'], $err->metadata);
    }

    public function test_error_message_names_method_and_hint(): void
    {
        $err = new NotYetImplementedDispatch(
            method: 'bundle',
            hint: 'lands in wpHoJhuo (P4b — single-workflow .mapEach().bundle())',
        );

        $this->assertStringContainsString('bundle', $err->getMessage());
        // Don't pin the literal P-card number — `ERGONOMIC_METHODS` may
        // renumber as the plan shifts. The hint substring is stable.
        $this->assertStringContainsString('single-workflow .mapEach().bundle()', $err->getMessage());
    }

    /**
     * Pins the current `Invoke::run()` ordering: `mode=webhook` routes to
     * `runWebhook()` BEFORE the ergonomic guard in `dispatch()` runs. A
     * fixture with a webhook mode + an ergonomic method name is malformed
     * (FixtureLoader rejects `mode=webhook + method!=verifyWebhook` at
     * load) — this test exists so any future ordering flip (seam-first,
     * webhook-second) is a conscious choice that fails this assertion.
     *
     * `runWebhook` throws synchronously (no surrounding try/catch in
     * `Invoke::run`) when the webhook block is missing, so the exception
     * escapes directly rather than landing on `InvokeResult::$thrown`.
     */
    public function test_webhook_mode_short_circuits_before_ergonomic_guard(): void
    {
        $fixture = new Fixture(
            name: 'webhook_ordering_pin',
            description: 'pins webhook short-circuit precedes ergonomic guard',
            mode: Fixture::MODE_WEBHOOK,
            // Use a still-parked verb so the assertion fires meaningfully —
            // a wired verb (compress/merge/mapEach/etc.) would reach the
            // P2/P3/P4 dispatch paths rather than the seam, masking the
            // ordering intent.
            sdkMethod: 'bundle',
            args: [],
            requests: [],
            responses: [],
            expectedReturn: null,
            hasExpectedReturn: false,
            webhook: null,
            expectsError: true,
            absolutePath: '/dev/null',
        );

        try {
            Invoke::run($fixture, new StubPsr18Client([], null));
            $this->fail('Expected webhook short-circuit to throw a RuntimeException, not reach the seam.');
        } catch (NotYetImplementedDispatch $e) {
            $this->fail(
                'When mode=webhook, runWebhook() must short-circuit BEFORE dispatch(); '
                . 'a malformed webhook fixture should surface runWebhook errors, not the seam.',
            );
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('missing webhook block', $e->getMessage());
        }
    }

    /**
     * Malformed `args` (non-list, garbage object) must not crash the
     * ergonomic guard. The seam keys on `$method` alone — `$args` is
     * inspected only inside the low-level switch cases that follow, none
     * of which run for ergonomic verbs. A future refactor that inlines
     * arg validation above the guard would regress this.
     */
    public function test_ergonomic_seam_robust_to_malformed_args(): void
    {
        $fixture = new Fixture(
            name: 'ergonomic_with_garbage_args',
            description: 'malformed args do not bypass the seam',
            mode: Fixture::MODE_REQUEST_RESPONSE,
            // Still-parked verb keeps this test asserting the seam path;
            // a P2/P3/P4-wired verb would surface a different failure
            // mode (an argument-validation error rather than the seam throw).
            sdkMethod: 'bundle',
            args: [new \stdClass(), ['nested' => ['junk' => null]], 'a string in the wrong slot'],
            requests: [],
            responses: [],
            expectedReturn: null,
            hasExpectedReturn: false,
            webhook: null,
            expectsError: true,
            absolutePath: '/dev/null',
        );

        $result = Invoke::run($fixture, new StubPsr18Client([], null));

        $this->assertInstanceOf(
            NotYetImplementedDispatch::class,
            $result->thrown,
            'Ergonomic seam must throw the structured error regardless of args shape.',
        );
        /** @var NotYetImplementedDispatch $err */
        $err = $result->thrown;
        $this->assertSame('bundle', $err->method);
    }

    /**
     * Pins the `ERGONOMIC_METHODS` keyset so a typo (e.g. accidentally
     * registering a low-level method like `cancelWorkflow` in the map)
     * fails loud. The map is private; reflection is the cheap way to
     * read it without widening surface.
     */
    public function test_ergonomic_methods_keyset_is_stable(): void
    {
        $reflection = new \ReflectionClass(Invoke::class);
        /** @var array<string, string> $methods */
        $methods = $reflection->getReflectionConstant('ERGONOMIC_METHODS')->getValue();
        $this->assertSame(
            ['watermark', 'archive', 'bundle'],
            \array_keys($methods),
            'ERGONOMIC_METHODS keyset drifted — update or revert if intentional. '
            . 'Post-PHP-P4 (OMuSCt7y) only these three verbs remain parked: '
            . 'watermark+archive (deferred to preset/multi-input work), '
            . 'bundle (wpHoJhuo / P4b — single-workflow .mapEach().bundle()).',
        );
    }

    /**
     * Pins the multi-input dispatch keyset added in PHP P3 (dxIeLVbP).
     * Currently only `merge` lives here; future multi-input verbs
     * (`bundle`/`archive` once they ship) would join.
     */
    public function test_ergonomic_multi_input_verbs_keyset_is_stable(): void
    {
        $reflection = new \ReflectionClass(Invoke::class);
        /** @var list<string> $verbs */
        $verbs = $reflection->getReflectionConstant('ERGONOMIC_MULTI_INPUT_VERBS')->getValue();
        $this->assertSame(
            ['merge'],
            $verbs,
            'ERGONOMIC_MULTI_INPUT_VERBS keyset drifted — `merge` is the only PHP P3 wired multi-input verb.',
        );
    }

    /**
     * Pins the symmetric companion list: verbs the dispatch path
     * actually wires end-to-end via {@see Invoke::dispatchErgonomic()}.
     * A typo or a regression that moves a verb back to the seam shows
     * up here as a single-place assertion break.
     */
    public function test_ergonomic_dispatch_verbs_keyset_is_stable(): void
    {
        $reflection = new \ReflectionClass(Invoke::class);
        /** @var list<string> $verbs */
        $verbs = $reflection->getReflectionConstant('ERGONOMIC_DISPATCH_VERBS')->getValue();
        $this->assertSame(
            ['compress', 'thumbnail', 'convert', 'split'],
            $verbs,
            'ERGONOMIC_DISPATCH_VERBS keyset drifted — these are the PHP wired single-op verbs (P2 + split, JpgY3328).',
        );
    }

    public function test_default_metadata_is_empty_array(): void
    {
        $err = new NotYetImplementedDispatch(method: 'compress');
        $this->assertSame([], $err->metadata);
        $this->assertNull($err->hint);
    }

    /**
     * @param list<mixed> $args
     */
    private static function synthesizeFixture(string $name, string $method, array $args = []): Fixture
    {
        return new Fixture(
            name: $name,
            description: "synthesized for {$method} seam test",
            mode: Fixture::MODE_REQUEST_RESPONSE,
            sdkMethod: $method,
            args: $args,
            requests: [],
            responses: [],
            expectedReturn: null,
            hasExpectedReturn: false,
            webhook: null,
            expectsError: true,
            absolutePath: '/dev/null',
        );
    }
}
