<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Tests\Parity\Comparator;
use Gisl\Sdk\Tests\Parity\FixtureLoader;
use Gisl\Sdk\Tests\Parity\Invoke;
use Gisl\Sdk\Tests\Parity\StubPsr18Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * cEUWPgKW reference conformance (mirrors TS tests/parity/conformance.test.ts):
 * fixtures under tests/parity-conformance are DELIBERATELY wrong, one expected
 * value each. The parity comparison must FAIL on them and name the broken
 * path, so a comparator that silently passed everything cannot leave the whole
 * parity suite green.
 */
#[CoversClass(Comparator::class)]
final class ParityConformanceTest extends TestCase
{
    private static function path(string $stem): string
    {
        return __DIR__ . "/../../../../tests/parity-conformance/fixtures/{$stem}.yaml";
    }

    public function test_a_broken_expected_payload_fails_naming_the_path(): void
    {
        $fixture = FixtureLoader::loadByPath(self::path('cf_lowering_payload_mismatch'));
        $issues = Comparator::compareReturn($fixture->expectedPayload, Invoke::lower($fixture), 'expected_payload');

        self::assertNotSame([], $issues);
        self::assertMatchesRegularExpression('/expected_payload\.jobs\[0\]\.operations\[0\]\.options\.quality/', \implode("\n", $issues));
    }

    public function test_a_broken_expected_run_result_fails_naming_the_path(): void
    {
        $fixture = FixtureLoader::loadByPath(self::path('cf_run_result_mismatch'));
        $stub = new StubPsr18Client($fixture->responses, $fixture->absolutePath);
        $issues = Comparator::compareReturn($fixture->expectedRunResult, Invoke::runRecipe($fixture, $stub), 'expected_run_result');

        self::assertNotSame([], $issues);
        self::assertMatchesRegularExpression('/expected_run_result\.workflowId/', \implode("\n", $issues));
    }
}
