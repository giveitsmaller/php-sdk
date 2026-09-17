<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Credentials;
use Gisl\Sdk\Environment;
use Gisl\Sdk\Errors\GislConfigError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Credentials::class)]
#[CoversClass(Environment::class)]
final class CredentialsTest extends TestCase
{
    /** @var list<string> */
    private array $envSnapshot = [];

    private string $tmpDir;

    protected function setUp(): void
    {
        // Snapshot + clear the env vars the resolver reads. Tests
        // restore them in tearDown.
        $this->envSnapshot = [
            'GISL_API_KEY' => getenv('GISL_API_KEY'),
            'GISL_BASE_URL' => getenv('GISL_BASE_URL'),
            'GISL_ENVIRONMENT' => getenv('GISL_ENVIRONMENT'),
        ];
        foreach (array_keys($this->envSnapshot) as $name) {
            putenv($name);
        }
        $this->tmpDir = sys_get_temp_dir() . '/gisl-creds-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->envSnapshot as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv("{$name}={$value}");
            }
        }
        $this->rmDir($this->tmpDir);
    }

    // -----------------------------------------------------------------
    // resolveApiKey
    // -----------------------------------------------------------------

    public function testExplicitApiKeyWinsOverEverythingElse(): void
    {
        putenv('GISL_API_KEY=from-env');
        $profilePath = $this->writeProfile("[default]\napi_key = from-profile\n");

        $resolved = Credentials::resolveApiKey(
            apiKey: 'from-explicit',
            profilePath: $profilePath,
        );

        self::assertSame('from-explicit', $resolved);
    }

    public function testEnvironmentVariableUsedWhenNoExplicitKey(): void
    {
        putenv('GISL_API_KEY=env-key-value');

        self::assertSame('env-key-value', Credentials::resolveApiKey());
    }

    public function testProfileUsedWhenNoExplicitAndNoEnv(): void
    {
        $profilePath = $this->writeProfile("[default]\napi_key = profile-key-value\n");

        $resolved = Credentials::resolveApiKey(profilePath: $profilePath);

        self::assertSame('profile-key-value', $resolved);
    }

    public function testNonDefaultProfileSelected(): void
    {
        $profilePath = $this->writeProfile(
            "[default]\napi_key = default-key\n\n"
            . "[staging]\napi_key = staging-key\n",
        );

        $resolved = Credentials::resolveApiKey(
            profile: 'staging',
            profilePath: $profilePath,
        );

        self::assertSame('staging-key', $resolved);
    }

    public function testReturnsNullWhenNoSourceProducesKey(): void
    {
        $profilePath = $this->tmpDir . '/missing-credentials';

        self::assertNull(
            Credentials::resolveApiKey(profilePath: $profilePath),
        );
    }

    public function testEmptyExplicitFallsThroughToNextSource(): void
    {
        putenv('GISL_API_KEY=env-fallback');

        self::assertSame(
            'env-fallback',
            Credentials::resolveApiKey(apiKey: ''),
        );
    }

    public function testEmptyEnvFallsThroughToProfile(): void
    {
        putenv('GISL_API_KEY=');
        $profilePath = $this->writeProfile("[default]\napi_key = profile-fallback\n");

        self::assertSame(
            'profile-fallback',
            Credentials::resolveApiKey(profilePath: $profilePath),
        );
    }

    public function testProfileMissingFileReturnsNullNotError(): void
    {
        $missing = $this->tmpDir . '/no-such-file';

        self::assertNull(Credentials::resolveApiKey(profilePath: $missing));
    }

    public function testProfileNotFoundInFileThrowsNamingProfile(): void
    {
        $profilePath = $this->writeProfile("[default]\napi_key = some-value\n");

        try {
            Credentials::resolveApiKey(
                profile: 'no-such-profile',
                profilePath: $profilePath,
            );
            self::fail('Expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertStringContainsString("'no-such-profile'", $e->getMessage());
            self::assertStringContainsString($profilePath, $e->getMessage());
            // CRITICAL: never leak credential VALUES in error messages.
            self::assertStringNotContainsString('some-value', $e->getMessage());
        }
    }

    public function testMalformedProfileThrowsWithLineNumberWithoutLeakingValues(): void
    {
        // Garbage that parse_ini_string rejects. Include what looks
        // like a credential so we can assert it doesn't escape. The
        // resolver captures parse_ini_string's E_WARNING line number
        // (codex r1 #4fdbf1c44594 — without it, profile parse
        // failures are materially harder to diagnose) but the
        // warning body itself never contains the credential value,
        // so the line-number capture is safe.
        $profilePath = $this->tmpDir . '/malformed';
        // Put the bad token on a non-line-1 line so we can verify the
        // line number is captured rather than hard-coded.
        file_put_contents(
            $profilePath,
            "[default]\napi_key = ok-value\n[bad section\n",
        );

        try {
            Credentials::resolveApiKey(profilePath: $profilePath);
            self::fail('Expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertStringContainsString('Malformed', $e->getMessage());
            self::assertStringContainsString($profilePath, $e->getMessage());
            self::assertStringContainsString('line ', $e->getMessage());
            self::assertStringNotContainsString('ok-value', $e->getMessage());
        }
    }

    public function testProfileWithoutApiKeyReturnsNullSilently(): void
    {
        // Section exists but has no `api_key` entry — returns null,
        // does NOT throw. Callers fall through to the next source
        // (here, nothing, so the factory will throw missing-creds).
        $profilePath = $this->writeProfile("[default]\nsecret = ignored\n");

        self::assertNull(
            Credentials::resolveApiKey(profilePath: $profilePath),
        );
    }

    public function testProfileWithEmptyApiKeyReturnsNullNotEmptyString(): void
    {
        // `api_key =` (empty value) is treated as "no key here", not
        // as the empty-string credential.
        $profilePath = $this->writeProfile("[default]\napi_key =\n");

        self::assertNull(
            Credentials::resolveApiKey(profilePath: $profilePath),
        );
    }

    public function testProfileNameCollidesWithRootLevelKey(): void
    {
        // Top-level `foo = bar` (outside any section) registers `foo`
        // as a scalar key on the parsed root. Asking for profile
        // `foo` must surface as a typed config error, not coerce.
        $profilePath = $this->tmpDir . '/root-collision';
        file_put_contents(
            $profilePath,
            "foo = bar\n[default]\napi_key = x\n",
        );

        try {
            Credentials::resolveApiKey(
                profile: 'foo',
                profilePath: $profilePath,
            );
            self::fail('Expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertStringContainsString('not a valid section', $e->getMessage());
            self::assertStringContainsString($profilePath, $e->getMessage());
        }
    }

    public function testUnreadableProfileThrowsConfigError(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Running as root bypasses POSIX read perms.');
        }
        $profilePath = $this->writeProfile("[default]\napi_key = readable-value\n");
        chmod($profilePath, 0);
        // chmod(0) does not make a file unreadable to root, and the POSIX euid
        // guard above misses CI runners that lack the `posix` extension
        // (function_exists() is false → guard skipped → test runs as root and
        // the file stays readable). Skip when the precondition the test needs —
        // an actually-unreadable file — isn't met, regardless of how we got there.
        clearstatcache(true, $profilePath);
        if (is_readable($profilePath)) {
            chmod($profilePath, 0600);
            self::markTestSkipped('chmod 0 did not make the profile unreadable (running as root?).');
        }
        try {
            Credentials::resolveApiKey(profilePath: $profilePath);
            self::fail('Expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertStringContainsString('Failed to read', $e->getMessage());
            self::assertStringContainsString($profilePath, $e->getMessage());
            self::assertStringNotContainsString('readable-value', $e->getMessage());
        } finally {
            // Restore perms so tearDown's rmDir can unlink.
            chmod($profilePath, 0600);
        }
    }

    public function testDefaultProfilePathUsesHomeEnvVariable(): void
    {
        // Exercise the HOME branch of defaultProfilePath. Without a
        // profilePath arg, the resolver must read `$HOME/.gisl/credentials`.
        mkdir($this->tmpDir . '/.gisl', 0700, true);
        file_put_contents(
            $this->tmpDir . '/.gisl/credentials',
            "[default]\napi_key = home-resolved\n",
        );
        $originalHome = getenv('HOME');
        $originalUserProfile = getenv('USERPROFILE');
        putenv('HOME=' . $this->tmpDir);
        putenv('USERPROFILE');
        try {
            self::assertSame(
                'home-resolved',
                Credentials::resolveApiKey(),
            );
        } finally {
            if ($originalHome !== false) {
                putenv("HOME={$originalHome}");
            } else {
                putenv('HOME');
            }
            if ($originalUserProfile !== false) {
                putenv("USERPROFILE={$originalUserProfile}");
            }
        }
    }

    public function testDefaultProfilePathReturnsNullWithNoHome(): void
    {
        $originalHome = getenv('HOME');
        $originalUserProfile = getenv('USERPROFILE');
        putenv('HOME');
        putenv('USERPROFILE');
        try {
            self::assertNull(Credentials::resolveApiKey());
        } finally {
            if ($originalHome !== false) {
                putenv("HOME={$originalHome}");
            }
            if ($originalUserProfile !== false) {
                putenv("USERPROFILE={$originalUserProfile}");
            }
        }
    }

    public function testProfilePathOverrideTakesPrecedence(): void
    {
        $profilePath = $this->writeProfile("[default]\napi_key = explicit-path-key\n");

        // Pass the path explicitly even though no HOME is set.
        $original = getenv('HOME');
        putenv('HOME');
        try {
            self::assertSame(
                'explicit-path-key',
                Credentials::resolveApiKey(profilePath: $profilePath),
            );
        } finally {
            if ($original !== false) {
                putenv("HOME={$original}");
            }
        }
    }

    // -----------------------------------------------------------------
    // INI parser security — INI_SCANNER_RAW behaviour
    // -----------------------------------------------------------------

    /**
     * The "Norway problem" + env / constant interpolation suite. With
     * `INI_SCANNER_RAW`, parse_ini_string must NOT coerce yes/no/on/off
     * to booleans and must NOT interpolate ${VAR} or PHP constants.
     * Credential values are returned VERBATIM.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rawValuesProvider(): array
    {
        return [
            'norway-no' => ['no', 'no'],
            'yes' => ['yes', 'yes'],
            'on' => ['on', 'on'],
            'off' => ['off', 'off'],
            'true' => ['true', 'true'],
            'false' => ['false', 'false'],
            'none' => ['none', 'none'],
        ];
    }

    #[DataProvider('rawValuesProvider')]
    public function testIniRawScannerPreservesReservedWordValues(
        string $raw,
        string $expected,
    ): void {
        $profilePath = $this->writeProfile("[default]\napi_key = {$raw}\n");

        $resolved = Credentials::resolveApiKey(profilePath: $profilePath);

        self::assertSame($expected, $resolved);
    }

    public function testIniRawScannerDoesNotInterpolateEnvVars(): void
    {
        // If RAW mode is not active, parse_ini_string would substitute
        // ${HOME} against the host env. RAW returns the literal string.
        $profilePath = $this->writeProfile(
            "[default]\napi_key = \${HOME}-literal\n",
        );

        $resolved = Credentials::resolveApiKey(profilePath: $profilePath);

        self::assertSame('${HOME}-literal', $resolved);
    }

    public function testIniRawScannerDoesNotSubstitutePhpConstants(): void
    {
        // PHP_INT_MAX is a defined constant — without RAW, parse_ini
        // expands it. With RAW, it stays as the literal token.
        $profilePath = $this->writeProfile(
            "[default]\napi_key = PHP_INT_MAX\n",
        );

        $resolved = Credentials::resolveApiKey(profilePath: $profilePath);

        self::assertSame('PHP_INT_MAX', $resolved);
    }

    // -----------------------------------------------------------------
    // resolveEndpoint
    // -----------------------------------------------------------------

    public function testEndpointExplicitBaseUrlWins(): void
    {
        putenv('GISL_BASE_URL=https://env.example');
        putenv('GISL_ENVIRONMENT=staging');

        self::assertSame(
            'https://custom.example',
            Credentials::resolveEndpoint(
                baseUrl: 'https://custom.example',
                environment: Environment::Prod,
            ),
        );
    }

    public function testEndpointExplicitEnvironmentBeatsEnvVars(): void
    {
        putenv('GISL_BASE_URL=https://env-base.example');
        putenv('GISL_ENVIRONMENT=prod');

        self::assertSame(
            Credentials::ENVIRONMENT_ENDPOINTS['staging'],
            Credentials::resolveEndpoint(environment: Environment::Staging),
        );
    }

    public function testEndpointFallsBackToEnvBaseUrl(): void
    {
        putenv('GISL_BASE_URL=https://env-base.example');

        self::assertSame(
            'https://env-base.example',
            Credentials::resolveEndpoint(),
        );
    }

    public function testEndpointFallsBackToEnvEnvironmentName(): void
    {
        putenv('GISL_ENVIRONMENT=staging');

        self::assertSame(
            Credentials::ENVIRONMENT_ENDPOINTS['staging'],
            Credentials::resolveEndpoint(),
        );
    }

    public function testEndpointUnknownEnvVarNameFallsThroughToDefault(): void
    {
        // Mirrors TS env-var lenience — explicit-arg unknowns are
        // structurally impossible (backed enum), but env-var path
        // accepts arbitrary strings and silently falls through.
        putenv('GISL_ENVIRONMENT=quux-stage');

        self::assertSame(
            Credentials::DEFAULT_ENDPOINT,
            Credentials::resolveEndpoint(),
        );
    }

    public function testEndpointDefaultsToProdWhenNothingSet(): void
    {
        self::assertSame(
            Credentials::DEFAULT_ENDPOINT,
            Credentials::resolveEndpoint(),
        );
        // Pin the literal value so an accidental constant rewrite is
        // caught by the parity tests too.
        self::assertSame(
            'https://api.giveitsmaller.com',
            Credentials::resolveEndpoint(),
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function writeProfile(string $contents): string
    {
        $path = $this->tmpDir . '/credentials';
        file_put_contents($path, $contents);
        return $path;
    }

    private function rmDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rmDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * OxqseYwd — a present-but-blank base URL is a configuration ERROR.
     *
     * Deliberate mirror of the TypeScript tests: the two languages, and the two
     * resolvers within each, must agree about what "unconfigured" means, because
     * the disagreement is what sent credentialed traffic to production.
     */
    public function test_a_blank_base_url_is_rejected_by_both_resolvers(): void
    {
        foreach (['', '   ', "\t"] as $blank) {
            try {
                Credentials::resolveEndpoint($blank);
                $this->fail("resolveEndpoint accepted a blank baseUrl: " . \var_export($blank, true));
            } catch (GislConfigError $error) {
                $this->assertStringContainsString('GISL_BASE_URL', $error->getMessage());
            }

            try {
                Credentials::resolveStreamEndpoint(null, null, $blank);
                $this->fail("resolveStreamEndpoint accepted a blank baseUrl: " . \var_export($blank, true));
            } catch (GislConfigError $error) {
                $this->assertStringContainsString('blank', $error->getMessage());
            }
        }
    }

    public function test_an_absent_base_url_still_falls_through(): void
    {
        // The case the production fallback exists for — pinned separately so the
        // rejection above cannot quietly widen into it.
        $this->assertSame(
            Credentials::ENVIRONMENT_ENDPOINTS[Environment::Staging->value],
            Credentials::resolveEndpoint(null, Environment::Staging),
        );
    }

    /**
     * OxqseYwd round 2 — the env path, mirroring TypeScript.
     */
    public function test_a_set_but_blank_url_env_var_is_rejected(): void
    {
        \putenv('GISL_BASE_URL=');
        try {
            $this->expectException(GislConfigError::class);
            Credentials::resolveEndpoint();
        } finally {
            \putenv('GISL_BASE_URL');
        }
    }
}
