<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Credentials;
use Gisl\Sdk\Environment;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislFeatureRequiresAuthError;
use Gisl\Sdk\Errors\GislMissingCredentialsError;
use Gisl\Sdk\Gisl;
use Gisl\Sdk\GislAnonymousClient;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\Http\CurlMultiPartUploader;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(Gisl::class)]
final class GislTest extends TestCase
{
    /** @var array<string, false|string> */
    private array $envSnapshot = [];

    private string $tmpDir;

    private HttpFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
        $this->envSnapshot = [
            'GISL_API_KEY' => getenv('GISL_API_KEY'),
            'GISL_BASE_URL' => getenv('GISL_BASE_URL'),
            'GISL_ENVIRONMENT' => getenv('GISL_ENVIRONMENT'),
        ];
        foreach (array_keys($this->envSnapshot) as $name) {
            putenv($name);
        }
        $this->tmpDir = sys_get_temp_dir() . '/gisl-factory-test-' . bin2hex(random_bytes(6));
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
    // Resolution paths (4: explicit / env / profile / cookie-mode-null)
    // -----------------------------------------------------------------

    public function testCreateWithExplicitApiKeyTargetsProdByDefault(): void
    {
        $client = Gisl::create(
            apiKey: 'explicit-key',
            httpClient: $this->stubClient(),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );

        self::assertInstanceOf(GislClient::class, $client);
        self::assertSame('explicit-key', $client->config->apiKey);
        self::assertSame('https://api.giveitsmaller.com', $client->config->baseUrl);
    }

    public function testCreateForwardsLocaleToConfig(): void
    {
        $client = Gisl::create(
            apiKey: 'explicit-key',
            locale: 'fr-FR',
            httpClient: $this->stubClient(),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );

        // Regression guard: the ergonomic factory must thread `locale` into
        // GislClientConfig — without it the option is only reachable via the
        // low-level `new GislClientConfig(...)` constructor (codex 0883a91c).
        self::assertSame('fr-FR', $client->config->locale);
    }

    public function testCreateLocaleDefaultsToNull(): void
    {
        $client = Gisl::create(
            apiKey: 'explicit-key',
            httpClient: $this->stubClient(),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );

        self::assertNull($client->config->locale);
    }

    public function testCreateWiresConcurrentUploaderByDefault(): void
    {
        $client = Gisl::create(
            apiKey: 'explicit-key',
            httpClient: $this->stubClient(),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );

        // Default multipartConcurrency (4) + ext-curl present -> concurrent path.
        // (Skips if the test image somehow lacks ext-curl; CI always has it.)
        if (!CurlMultiPartUploader::isSupported()) {
            self::markTestSkipped('ext-curl not loaded');
        }
        self::assertInstanceOf(CurlMultiPartUploader::class, $this->readPartUploader($client));
    }

    public function testCreateWithConcurrencyOneStaysSequential(): void
    {
        $client = Gisl::create(
            apiKey: 'explicit-key',
            multipartConcurrency: 1,
            httpClient: $this->stubClient(),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );

        // concurrency == 1 -> no concurrent uploader injected; GislClient uses
        // its sequential PSR-18 loop.
        self::assertNull($this->readPartUploader($client));
    }

    private function readPartUploader(GislClient $client): mixed
    {
        $prop = new \ReflectionProperty(GislClient::class, 'partUploader');

        return $prop->getValue($client);
    }

    public function testCreateResolvesApiKeyFromEnvironment(): void
    {
        putenv('GISL_API_KEY=from-env');

        $client = Gisl::create(
            httpClient: $this->stubClient(),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );

        self::assertSame('from-env', $client->config->apiKey);
    }

    public function testExplicitArgOverridesEnvironmentVariable(): void
    {
        putenv('GISL_API_KEY=env-loses');

        $client = Gisl::create(
            apiKey: 'arg-wins',
            httpClient: $this->stubClient(),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );

        self::assertSame('arg-wins', $client->config->apiKey);
    }

    public function testCreateResolvesFromSharedProfile(): void
    {
        $profilePath = $this->writeProfile("[default]\napi_key = profile-key\n");

        $client = Gisl::create(
            profilePath: $profilePath,
            httpClient: $this->stubClient(),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );

        self::assertSame('profile-key', $client->config->apiKey);
    }

    public function testCreateWithCookieModeAndNoKeyDoesNotThrow(): void
    {
        // No env, no profile, no explicit key — cookie-mode is a
        // legitimate browser-SPA flow that authenticates via login()
        // later. Resolved apiKey is null.
        $client = Gisl::create(
            useSessionCookie: true,
            httpClient: $this->stubClient(),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );

        self::assertNull($client->config->apiKey);
        self::assertTrue($client->config->useSessionCookie);
    }

    // -----------------------------------------------------------------
    // Missing-credentials throw — fail-early, before any I/O
    // -----------------------------------------------------------------

    public function testThrowsMissingCredentialsBeforeAnyIo(): void
    {
        // The HTTP client throws if ever invoked — the resolution
        // throw must happen synchronously, before the constructor
        // ever calls the transport.
        $detonator = new class () implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('client must not be reached') extends \RuntimeException implements ClientExceptionInterface {};
            }
        };

        $this->expectException(GislMissingCredentialsError::class);

        Gisl::create(
            profilePath: $this->tmpDir . '/missing',
            httpClient: $detonator,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    public function testMissingCredentialsMessageMentionsAllSources(): void
    {
        try {
            Gisl::create(
                profilePath: $this->tmpDir . '/missing',
                httpClient: $this->stubClient(),
                requestFactory: $this->factory,
                streamFactory: $this->factory,
            );
            self::fail('Expected GislMissingCredentialsError');
        } catch (GislMissingCredentialsError $e) {
            $msg = $e->getMessage();
            self::assertStringContainsString('explicit', $msg);
            self::assertStringContainsString('GISL_API_KEY', $msg);
            self::assertStringContainsString('~/.gisl/credentials', $msg);
            self::assertStringContainsString('useSessionCookie', $msg);
        }
    }

    // -----------------------------------------------------------------
    // Cookie-mode behaviour (both legs — codex r2 913e4d8073f5)
    // -----------------------------------------------------------------

    public function testCookieModeAloneBypassesEnvKey(): void
    {
        // Env key set, but cookie-mode caller has no apiKey arg.
        // Without the cookie-only bypass, the env key would silently
        // attach to a cookie-authenticated SPA flow.
        putenv('GISL_API_KEY=env-key-should-be-ignored');

        $client = Gisl::create(
            useSessionCookie: true,
            httpClient: $this->stubClient(),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );

        self::assertNull($client->config->apiKey);
        self::assertTrue($client->config->useSessionCookie);
    }

    public function testCookieModeWithExplicitKeyPassesKeyThrough(): void
    {
        // Mixed case: cookie auth + server-issued API key. Both
        // legitimate; the explicit key must NOT be suppressed.
        putenv('GISL_API_KEY=env-key-should-be-ignored');

        $client = Gisl::create(
            apiKey: 'server-issued-key',
            useSessionCookie: true,
            httpClient: $this->stubClient(),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );

        self::assertSame('server-issued-key', $client->config->apiKey);
        self::assertTrue($client->config->useSessionCookie);
    }

    // -----------------------------------------------------------------
    // Anonymous — Gisl::anonymous() (OuegCUtq). Behaviour and the contract
    // pin live in GislAnonymousClientTest / AnonymousAllowlistConformanceTest;
    // these cover the factory.
    // -----------------------------------------------------------------

    public function testAnonymousBypassesTheCredentialChainEntirely(): void
    {
        // Load-bearing TS-port r1 e9e1c1182d56: env AND profile both populated,
        // and the anonymous client must still carry no key.
        putenv('GISL_API_KEY=env-key-must-never-reach-anon-client');
        $client = Gisl::anonymous(
            httpClient: $this->stubClient(),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );

        self::assertInstanceOf(GislAnonymousClient::class, $client);
        self::assertNull($client->config->apiKey);
        self::assertFalse($client->config->useSessionCookie);
    }

    public function testCreateInternalAnonymousBranchIgnoresAProfileKey(): void
    {
        putenv('GISL_API_KEY=env-key-must-not-leak-through-bypass');
        $profilePath = $this->writeProfile(
            "[default]\napi_key = profile-key-also-no-leak\n",
        );

        $reflection = new \ReflectionClass(Gisl::class);
        $createInternal = $reflection->getMethod('createInternal');

        $client = $createInternal->invoke(
            null,
            null, // apiKey
            null, // environment
            null, // baseUrl
            null, // profile
            $profilePath,
            false, // useSessionCookie
            [],    // headers
            null,  // timeoutMs
            null, null, null, null,
            $this->stubClient(),
            $this->factory,
            $this->factory,
            true, // allowAnonymous
        );

        self::assertInstanceOf(GislAnonymousClient::class, $client);
        self::assertNull($client->config->apiKey);
    }

    public function testCreateStillRefusesAMissingKeyAndNeverFallsBackToAnonymous(): void
    {
        $this->expectException(GislMissingCredentialsError::class);
        Gisl::create(
            profilePath: $this->tmpDir . '/no-such-credentials',
            httpClient: $this->stubClient(),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    public function testAnonymousRefusalIsAConfigErrorSubtype(): void
    {
        // Mirrors TS errors.ts — `GislFeatureRequiresAuthError extends
        // GislConfigError`, so a `catch (GislConfigError $e)` catches the gate.
        $client = Gisl::anonymous(
            httpClient: $this->stubClient(),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
        try {
            $client->getCreditsBalance();
            self::fail('Expected GislFeatureRequiresAuthError');
        } catch (GislConfigError $e) {
            self::assertInstanceOf(GislFeatureRequiresAuthError::class, $e);
        }
    }

    public function testTheParkedPlaceholderIsGone(): void
    {
        // internalAnonymous() was a `never`-returning placeholder while the
        // allowlist was parked; Gisl::anonymous() replaces it.
        self::assertFalse((new \ReflectionClass(Gisl::class))->hasMethod('internalAnonymous'));
        self::assertNotSame([], Gisl::ANONYMOUS_ALLOWLIST);
    }

    // -----------------------------------------------------------------
    // Endpoint plumbing
    // -----------------------------------------------------------------

    public function testEnvironmentEnumPicksStagingEndpoint(): void
    {
        $client = Gisl::create(
            apiKey: 'key',
            environment: Environment::Staging,
            httpClient: $this->stubClient(),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );

        self::assertSame(
            Credentials::ENVIRONMENT_ENDPOINTS['staging'],
            $client->config->baseUrl,
        );
    }

    public function testExplicitBaseUrlBeatsEnvironment(): void
    {
        $client = Gisl::create(
            apiKey: 'key',
            environment: Environment::Staging,
            baseUrl: 'https://custom.example',
            httpClient: $this->stubClient(),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );

        self::assertSame('https://custom.example', $client->config->baseUrl);
    }

    public function testExistingGislClientCallersUnchanged(): void
    {
        // The smoke-test: constructing GislClient directly with a
        // GislClientConfig (the pre-P1 entrypoint) still works
        // identically. P1 is purely additive.
        $config = new \Gisl\Sdk\GislClientConfig(
            baseUrl: 'https://existing.example',
            apiKey: 'legacy-key',
        );
        $client = new GislClient(
            $config,
            $this->stubClient(),
            $this->factory,
            $this->factory,
        );

        self::assertSame('https://existing.example', $client->config->baseUrl);
        self::assertSame('legacy-key', $client->config->apiKey);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function stubClient(): ClientInterface
    {
        // Used for construction smoke-tests — never has sendRequest
        // called. The detonator pattern is used directly in the
        // missing-credentials test instead.
        return new class () implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('stub PSR-18 client: sendRequest unexpectedly invoked') extends \RuntimeException implements ClientExceptionInterface {};
            }
        };
    }

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
}
