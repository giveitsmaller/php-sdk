<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use Http\Discovery\ClassDiscovery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * LwYmMZpR (hub prod smoke, 2026-09-26): with no PSR-18/PSR-17 implementation
 * installed, constructing the client died with php-http's raw
 * "No PSR-18 clients found". It must say what to install.
 */
#[CoversClass(GislClient::class)]
final class HttpClientDiscoveryTest extends TestCase
{
    /** @var list<class-string> */
    private array $savedStrategies = [];

    protected function setUp(): void
    {
        $strategies = ClassDiscovery::getStrategies();
        $this->savedStrategies = \is_array($strategies) ? \array_values($strategies) : \iterator_to_array($strategies, false);
        // No strategies = discovery finds nothing, exactly as in a project with
        // no HTTP client installed.
        ClassDiscovery::setStrategies([]);
        ClassDiscovery::clearCache();
    }

    protected function tearDown(): void
    {
        ClassDiscovery::setStrategies($this->savedStrategies);
        ClassDiscovery::clearCache();
    }

    #[Test]
    public function no_installed_http_client_is_a_config_error_that_says_what_to_install(): void
    {
        try {
            new GislClient(new GislClientConfig(baseUrl: 'https://api.example.com', apiKey: 'sk_test'));
            self::fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertSame('http_client_not_found', $e->reason);
            // Hub ruling 2026-09-26: the error names the README's two install lines verbatim.
            self::assertStringContainsString("  composer require giveitsmaller/sdk\n", $e->getMessage());
            self::assertStringContainsString("  composer require guzzlehttp/guzzle http-interop/http-factory-guzzle\n", $e->getMessage());
            self::assertInstanceOf(\Http\Discovery\Exception\NotFoundException::class, $e->getPrevious());
        }
    }

    #[Test]
    public function an_installed_client_that_fails_to_construct_is_not_reported_as_missing(): void
    {
        ClassDiscovery::setStrategies([BrokenClientStrategy::class]);
        ClassDiscovery::clearCache();

        try {
            new GislClient(new GislClientConfig(baseUrl: 'https://api.example.com', apiKey: 'sk_test'));
            self::fail('expected the discovery instantiation failure');
        } catch (GislConfigError $e) {
            self::fail('a broken installed client was reported as absent: ' . $e->getMessage());
        } catch (\Http\Discovery\Exception\ClassInstantiationFailedException $e) {
            self::assertSame('broken client', $e->getPrevious()?->getMessage());
        }
    }

    #[Test]
    public function an_injected_client_needs_no_discovery(): void
    {
        $factory = new \GuzzleHttp\Psr7\HttpFactory();
        $client = new GislClient(
            new GislClientConfig(baseUrl: 'https://api.example.com', apiKey: 'sk_test'),
            new \GuzzleHttp\Client(),
            $factory,
            $factory,
        );
        self::assertInstanceOf(GislClient::class, $client);
    }
}

/** A discovery strategy whose only candidate throws while being constructed. */
final class BrokenClientStrategy implements \Http\Discovery\Strategy\DiscoveryStrategy
{
    public static function getCandidates($type)
    {
        return [[
            'class' => static function (): never {
                throw new \RuntimeException('broken client');
            },
            'condition' => true,
        ]];
    }
}
