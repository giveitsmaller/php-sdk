<?php

declare(strict_types=1);

namespace Gisl\Sdk;

/**
 * The PHP SDK's own version, read from the package's composer.json (zDwyRcaD).
 *
 * The User-Agent used to be the literal `giveitsmaller-sdk-php/0.1.0`. No version
 * bump touched it, so every request the PHP SDK ever made claimed 0.1.0 and any
 * server-side segmentation by client version was wrong about every PHP caller.
 * ⚠️ The defect class is "a literal that a version bump does not touch", so this
 * value is DERIVED, never written down.
 *
 * Why composer.json beside src/, rather than Composer\InstalledVersions: the
 * published package always ships composer.json at its root next to src/
 * (mirror.yml pins that file list), so the path is reliable for Packagist
 * installs, path repositories and vendored copies alike. InstalledVersions
 * returns null outside a Composer install, and its "pretty version" is the
 * mirror's tag (`v0.23.0`), not the manifest version.
 *
 * Fails SOFT: an unreadable or version-less manifest yields "unknown". A version
 * string in a header must never throw inside request building.
 *
 * @internal
 */
final class SdkVersion
{
    private static ?string $cached = null;

    public static function current(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }
        $version = 'unknown';
        $manifest = \dirname(__DIR__) . '/composer.json';
        $raw = \is_readable($manifest) ? @\file_get_contents($manifest) : false;
        if (\is_string($raw)) {
            $decoded = \json_decode($raw, true);
            if (\is_array($decoded) && \is_string($decoded['version'] ?? null) && $decoded['version'] !== '') {
                $version = $decoded['version'];
            }
        }

        return self::$cached = $version;
    }

    public static function userAgent(): string
    {
        return 'giveitsmaller-sdk-php/' . self::current();
    }
}
