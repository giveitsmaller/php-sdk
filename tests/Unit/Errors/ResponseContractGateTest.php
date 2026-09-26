<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Errors;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * u6Q9oxuI — the gate that stops a NEW unwrapped deserialiser call site.
 *
 * The error-taxonomy gate enumerates the GislError classes we define, not what
 * can escape, so it could never see a half-null DTO or a raw deserialiser
 * throwable. This enumerates the other population: every call of the generated
 * `ObjectSerializer::deserialize` in the hand-written source. Each must be in
 * one of the two reviewed functions below; anything else fails here until it
 * routes through `GislClient::hydrate()`.
 *
 * Keyed by ENCLOSING FUNCTION, not file: a file-level exemption would let a
 * second call in `GislClient.php` through, and the two reviewed calls are
 * byte-identical lines, so a line key could not tell them apart either.
 *
 * ⚠️ AUTHORING SOURCES ONLY: `src/Generated/` is vendored contract code.
 *
 * Mirrors `packages/typescript/tests/unit/response-contract-gate.test.ts`.
 */
final class ResponseContractGateTest extends TestCase
{
    private const CALL = '/ObjectSerializer::deserialize\s*\(/';

    /** `file :: function` => why the call there is safe. */
    private const ALLOWED = [
        'GislClient.php :: hydrate' => 'the wrapper every success body goes through',
        'GislClient.php :: tryDeserialize' => 'error envelopes: returns null on failure BY DESIGN, so the'
            . ' dispatcher falls through to the base GislApiError',
    ];

    /**
     * Every deserialiser call in `$text`, as `file :: enclosing function`.
     *
     * @return list<string>
     */
    private static function deserialiserCalls(string $file, string $text): array
    {
        $hits = [];
        $function = '<file scope>';
        foreach (\explode("\n", $text) as $line) {
            $trimmed = \trim($line);
            if (\preg_match('/\bfunction\s+(\w+)\s*\(/', $trimmed, $m) === 1) {
                $function = $m[1];
            }
            if (\str_starts_with($trimmed, '*') || \str_starts_with($trimmed, '//')
                || \str_starts_with($trimmed, '/*')) {
                continue;
            }
            if (\preg_match(self::CALL, $trimmed) === 1) {
                $hits[] = "{$file} :: {$function}";
            }
        }
        return $hits;
    }

    /** @return list<string> */
    private static function allCalls(): array
    {
        $root = \dirname(__DIR__, 3) . '/src';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        $calls = [];
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $relative = \substr($file->getPathname(), \strlen($root) + 1);
            if ($file->getExtension() !== 'php' || \str_starts_with($relative, 'Generated/')) {
                continue;
            }
            $text = \file_get_contents($file->getPathname());
            self::assertIsString($text);
            \array_push($calls, ...self::deserialiserCalls($relative, $text));
        }
        return $calls;
    }

    #[Test]
    public function every_deserialiser_call_in_src_is_a_reviewed_wrapped_site(): void
    {
        $unreviewed = \array_values(\array_filter(
            self::allCalls(),
            static fn (string $c): bool => !\array_key_exists($c, self::ALLOWED),
        ));

        self::assertSame([], $unreviewed, 'route new calls through GislClient::hydrate()');
    }

    #[Test]
    public function every_allow_listed_site_still_exists(): void
    {
        $calls = self::allCalls();
        foreach (\array_keys(self::ALLOWED) as $site) {
            self::assertCount(1, \array_keys($calls, $site, true), $site);
        }
    }

    /** Positive control: the matcher must be able to FIRE, or green proves nothing. */
    #[Test]
    public function the_matcher_flags_a_call_outside_the_wrappers(): void
    {
        $source = <<<'PHP'
            private function hydrate(string $c, array $d): object
            {
                return ObjectSerializer::deserialize($d, $c, []);
            }

            public function getThing(): Thing
            {
                // ObjectSerializer::deserialize($x) in a comment is not a call
                return ObjectSerializer::deserialize ($data, Thing::class, []);
            }
            PHP;

        self::assertSame(
            ['x.php :: hydrate', 'x.php :: getThing'],
            self::deserialiserCalls('x.php', $source),
        );
    }
}
