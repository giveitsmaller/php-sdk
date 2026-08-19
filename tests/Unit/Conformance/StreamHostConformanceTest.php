<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Conformance;

use Gisl\Generated\Operations\CompressMetadata;
use Gisl\Sdk\Credentials;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Stream-host declaration guard (card `VUozk5Bc`). PHP arm of the TS
 * `stream-host-conformance.test.ts`.
 *
 * The SSE stream lives on a SECOND host.
 * {@see Credentials::ENVIRONMENT_STREAM_ENDPOINTS} is the SDK's hand-maintained
 * projection of the contract's declaration — hand-held because the SDK does not
 * load `availability.json` at runtime, the same shape as the preset planned
 * gate and the watermark gate.
 *
 * A hand table without a gate is a guess that ages. THIS SUITE IS THE GATE, and
 * it fails CLOSED IN BOTH DIRECTIONS:
 *
 *   1. every host we ship is one the contract actually declares — we cannot
 *      invent a hostname (the failure mode that put prod on the gateway path
 *      was a hostname nobody declared), and
 *   2. every host the contract declares for a NAMED ENVIRONMENT is one we ship —
 *      so when the prod stream host finally lands in the contract, this test
 *      goes red on the re-vendor instead of the SDK quietly continuing to have
 *      no prod stream host.
 *
 * (2) is the one that earns its keep. Without it the missing prod entry stays
 * missing silently for exactly as long as nobody thinks to look.
 */
#[CoversClass(Credentials::class)]
final class StreamHostConformanceTest extends TestCase
{
    private const STREAM_ENDPOINT_KEY = 'GET /api/workflows/{id}/events';

    /** @var list<string> Stream server URLs declared by the vendored contract. */
    private static array $declared = [];

    public static function setUpBeforeClass(): void
    {
        // operations/src/CompressMetadata.php -> dirname x3 = generated/php root.
        $opFile = (new \ReflectionClass(CompressMetadata::class))->getFileName();
        $root = \dirname((string) $opFile, 3);
        $avail = \json_decode((string) \file_get_contents($root . '/availability/availability.json'), true);
        self::assertIsArray($avail, 'availability.json must decode to an array');

        $servers = $avail['endpoints'][self::STREAM_ENDPOINT_KEY]['servers'] ?? null;
        self::assertIsArray(
            $servers,
            'availability.json must declare a `servers` block on ' . self::STREAM_ENDPOINT_KEY
            . '. Every assertion in this suite is a subset/superset check against it and would '
            . 'pass VACUOUSLY if the endpoint entry were renamed or dropped by a re-vendor.',
        );

        $urls = [];
        foreach ($servers as $server) {
            $url = \is_array($server) ? ($server['url'] ?? null) : null;
            if (\is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }
        self::$declared = $urls;
    }

    /**
     * Contract-declared hosts that belong to a NAMED environment. `localhost` is
     * deliberately excluded: the contract declares it as a development server,
     * but there is no `localhost` case on {@see \Gisl\Sdk\Environment}, so it is
     * not something the environment table can carry. Local callers pass an
     * explicit stream base URL instead.
     *
     * @return list<string>
     */
    private static function declaredNonLocal(): array
    {
        return \array_values(\array_filter(
            self::$declared,
            static fn (string $url): bool => \preg_match('#^https?://localhost(:|/|$)#', $url) !== 1,
        ));
    }

    #[Test]
    public function the_contract_declares_stream_servers_at_all(): void
    {
        // Positive control for every other assertion in this class.
        self::assertNotSame([], self::$declared);
    }

    #[Test]
    public function ships_no_stream_host_the_contract_does_not_declare(): void
    {
        foreach (Credentials::ENVIRONMENT_STREAM_ENDPOINTS as $environment => $url) {
            self::assertContains(
                $url,
                self::$declared,
                "ENVIRONMENT_STREAM_ENDPOINTS[{$environment}] = {$url} is not declared by the "
                . 'contract (declared: ' . \implode(', ', self::$declared) . '). The SDK must not '
                . 'invent a stream hostname.',
            );
        }
    }

    #[Test]
    public function ships_every_non_localhost_stream_host_the_contract_declares(): void
    {
        $shipped = \array_values(Credentials::ENVIRONMENT_STREAM_ENDPOINTS);
        foreach (self::declaredNonLocal() as $url) {
            self::assertContains(
                $url,
                $shipped,
                "The contract declares stream host {$url} but ENVIRONMENT_STREAM_ENDPOINTS does "
                . 'not ship it. If this is the production stream host finally landing, add it to '
                . 'the table (BOTH languages) in this same change — that is what this assertion '
                . 'exists to catch.',
            );
        }
    }

    #[Test]
    public function keys_the_stream_table_only_with_known_environment_names(): void
    {
        foreach (\array_keys(Credentials::ENVIRONMENT_STREAM_ENDPOINTS) as $environment) {
            self::assertArrayHasKey($environment, Credentials::ENVIRONMENT_ENDPOINTS);
        }
    }

    #[Test]
    public function the_two_languages_declare_the_same_table(): void
    {
        // Parity by construction rather than by hope. If one language ships a
        // stream host the other does not, the SDKs stream to different places
        // on the same configuration and no per-language test would notice.
        $tsPath = \dirname(__DIR__, 4) . '/typescript/src/credentials.ts';

        // ⚠️ FAILS, DOES NOT SKIP — the method name promises a CROSS-LANGUAGE
        // check, and that name is what a reader sees in a green list. A skip
        // here would deliver nothing while still reporting the promise as kept.
        //
        // The skip this replaces had NEVER FIRED: every runner that executes
        // this suite (CI, and `make project/test`) mounts the whole repo, so it
        // guarded a case that does not occur while blinding the one that would
        // matter. If packages/typescript is genuinely absent, the environment is
        // broken and should say so rather than quietly covering less than the
        // test claims.
        self::assertFileIsReadable(
            $tsPath,
            'packages/typescript is not present — this cross-language check cannot run, and a '
            . 'test named "the two languages declare the same table" must not report green '
            . 'without having compared them.',
        );
        $tsSource = (string) \file_get_contents($tsPath);

        foreach (Credentials::ENVIRONMENT_STREAM_ENDPOINTS as $environment => $url) {
            self::assertStringContainsString(
                "{$environment}: '{$url}'",
                $tsSource,
                "PHP declares stream host {$url} for '{$environment}' but the TS "
                . 'ENVIRONMENT_STREAM_ENDPOINTS does not carry the same entry.',
            );
        }
    }
}
