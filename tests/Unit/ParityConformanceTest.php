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

    /** Exozpn36 — mirrors the TS conformance case of the same fixture. */
    public function test_a_wrong_error_class_kind_and_payload_field_each_fail_naming_the_field(): void
    {
        $fixture = FixtureLoader::loadByPath(self::path('cf_error_subclass_mismatch'));
        $stub = new StubPsr18Client($fixture->responses, $fixture->absolutePath);
        $result = Invoke::run($fixture, $stub);
        try {
            $issues = Comparator::compareThrownError(
                $fixture,
                Comparator::projectThrownError(
                    $result->thrown,
                    \array_map('strval', \array_keys($fixture->expectedPayloadFields ?? [])),
                ),
            );
        } finally {
            $result->cleanup();
        }

        $joined = \implode("\n", $issues);
        self::assertMatchesRegularExpression('/expected_error_class: expected GislApiError, got GislTierRestrictedError/', $joined);
        self::assertMatchesRegularExpression('/expected_error_kind: expected "size_tier", but GislTierRestrictedError carries no kind/', $joined);
        self::assertMatchesRegularExpression('/expected_payload_fields\.current_tier: expected "free", got "basic"/', $joined);
        self::assertMatchesRegularExpression('/expected_payload_fields\.max_size_bytes: .*does not expose it/', $joined);
        self::assertCount(4, $issues);
    }
}
