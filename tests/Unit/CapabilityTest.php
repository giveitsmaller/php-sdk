<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Tests\Capability;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\SkippedTest;
use PHPUnit\Framework\TestCase;

/**
 * AGvREzBD — the guard's four outcomes. Each asserts the thrown TYPE, because
 * a skip and a failure are both exceptions and a guard that confuses them is
 * the exact defect the helper exists to remove.
 */
final class CapabilityTest extends TestCase
{
    private string|false $saved;

    protected function setUp(): void
    {
        $this->saved = \getenv(Capability::ENV);
    }

    protected function tearDown(): void
    {
        \putenv($this->saved === false ? Capability::ENV : Capability::ENV . '=' . $this->saved);
    }

    public function testAbsentCapabilitySkipsWhenNothingRequiresIt(): void
    {
        \putenv(Capability::ENV);

        try {
            Capability::require('ext-demo', false, 'demo reason');
            self::fail('expected a skip');
        } catch (SkippedTest $skip) {
            self::assertStringContainsString('ext-demo', $skip->getMessage());
        }
    }

    public function testAbsentCapabilityFailsWhenRequiredAndSaysWhatToDo(): void
    {
        \putenv(Capability::ENV . '=1');

        try {
            Capability::require('ext-demo', false, 'demo reason');
            self::fail('unreachable');
        } catch (SkippedTest $skip) {
            self::fail('a required capability must fail, not skip: ' . $skip->getMessage());
        } catch (AssertionFailedError $failure) {
            $message = $failure->getMessage();
            self::assertStringContainsString('ext-demo', $message);
            self::assertStringContainsString('demo reason', $message);
            // The observed env value is in the message: proves the variable
            // reached THIS process, not just the shell that launched docker.
            self::assertStringContainsString('getenv(GISL_REQUIRE_CAPABILITIES) = "1"', $message);
            self::assertStringContainsString('do not delete this test', $message);
        }
    }

    public function testPresentCapabilityPassesInBothModes(): void
    {
        \putenv(Capability::ENV);
        Capability::require('ext-demo', true, 'demo reason');
        \putenv(Capability::ENV . '=1');
        Capability::require('ext-demo', true, 'demo reason');
        $this->addToAssertionCount(1);
    }

    public function testAnUnrecognisedValueFailsRatherThanMeaningOff(): void
    {
        \putenv(Capability::ENV . '=true');

        try {
            Capability::require('ext-demo', false, 'demo reason');
            self::fail('unreachable');
        } catch (SkippedTest $skip) {
            self::fail('"true" must not silently disable the guard: ' . $skip->getMessage());
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('must be "1" or unset', $failure->getMessage());
        }
    }
}
