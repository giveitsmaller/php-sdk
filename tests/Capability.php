<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests;

use PHPUnit\Framework\Assert;

/**
 * AGvREzBD — a capability guard that can tell "not provisioned here" from
 * "provisioned and gone".
 *
 * A plain skip absorbs a missing `ext-curl`, a disabled `proc_open` or a PHP
 * downgrade into a green run. So: when `GISL_REQUIRE_CAPABILITIES=1` is set in
 * the PHP process's environment, the caller asserts every capability below is
 * provisioned, and a missing one FAILS. Unset (a bare laptop, the 8.1-8.4
 * matrix), it skips honestly as before.
 *
 * ⚠️ The signal is explicit, not autodetected, and it must be passed INTO the
 * container (`docker run -e ...`): an outer shell export does not cross
 * `docker run`, which would disable this silently.
 */
final class Capability
{
    public const ENV = 'GISL_REQUIRE_CAPABILITIES';

    /**
     * @param string $capability what is missing, named for the failure (e.g. `ext-curl`)
     * @param bool   $isPresent  the observable check, evaluated by the caller
     * @param string $reason     why the test cannot run without it
     */
    public static function require(string $capability, bool $isPresent, string $reason): void
    {
        $required = \getenv(self::ENV);
        if ($required !== false && $required !== '' && $required !== '1') {
            // A typo ("true", "yes") must not quietly mean "off" — that is the
            // silent-disable this class exists to prevent.
            Assert::fail(\sprintf(
                '%s must be "1" or unset; getenv() returned "%s".',
                self::ENV,
                $required,
            ));
        }

        if ($isPresent) {
            return;
        }

        if ($required !== '1') {
            Assert::markTestSkipped("{$capability} unavailable: {$reason}");
        }

        Assert::fail(\sprintf(
            '%s is required (getenv(%s) = "1") but missing: %s. The pinned composer:2 image provisions it; '
            . 'if it is genuinely gone, fix the image or unset %s for this run — do not delete this test.',
            $capability,
            self::ENV,
            $reason,
            self::ENV,
        ));
    }
}
