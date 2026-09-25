<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Errors\GislError;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use GuzzleHttp\Client;
use Http\Discovery\ClassDiscovery;
use Http\Discovery\Strategy\DiscoveryStrategy;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * 385RWTsh: the SDK's bearer key must not reach a host a redirect points at.
 *
 * The SDK talks PSR-18 only, and Guzzle's PSR-18 `sendRequest()` forces
 * `allow_redirects => false`, so with the Guzzle line composer.json admits
 * (its `conflict` floor) a redirect is never followed at all. These tests
 * drive a real SDK call through Guzzle's own handler stack with the follow-up
 * 200 QUEUED: if Guzzle ever followed, the history would hold two requests and
 * the call would succeed, so the tests can fail.
 */
#[CoversClass(GislClient::class)]
final class RedirectCredentialTest extends TestCase
{
    /**
     * @param list<array{request: RequestInterface}> $history
     */
    private function client(int $status, string $location, array &$history): GislClient
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response($status, ['Location' => $location]),
            new Response(200, ['Content-Type' => 'application/json'], '{"success":true,"data":{"tier":"free"}}'),
        ]));
        $stack->push(Middleware::history($history));
        $factory = new HttpFactory();

        return new GislClient(
            config: new GislClientConfig(baseUrl: 'https://api.example.com', apiKey: 'sk_live_secret'),
            httpClient: new Client(['handler' => $stack]),
            requestFactory: $factory,
            streamFactory: $factory,
        );
    }

    /** @return iterable<string, array{int, string}> */
    public static function redirects(): iterable
    {
        yield '302 to another host' => [302, 'https://attacker.example.net/collect'];
        yield '307 to another port' => [307, 'https://api.example.com:8443/api/v2/credits/balance'];
        yield '308 downgrade to http' => [308, 'http://api.example.com/api/v2/credits/balance'];
        yield '301 same origin' => [301, 'https://api.example.com/api/v2/credits/balance'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('redirects')]
    public function testARedirectIsNeverFollowedAndIsNamed(int $status, string $location): void
    {
        $history = [];
        $client = $this->client($status, $location, $history);

        try {
            $client->getCreditsBalance();
            self::fail('a redirect must not be followed to a success');
        } catch (GislError $e) {
            self::assertStringContainsString("answered {$status} (a redirect to host ", $e->getMessage());
            self::assertStringContainsString('does not follow redirects', $e->getMessage());
        }

        self::assertCount(1, $history, 'the redirect was followed');
        self::assertSame('api.example.com', $history[0]['request']->getUri()->getHost());
        self::assertSame('Bearer sk_live_secret', $history[0]['request']->getHeaderLine('Authorization'));
    }

    /** @var list<resource> */
    private array $servers = [];
    private string $logFile = '';

    protected function tearDown(): void
    {
        foreach ($this->servers as $proc) {
            \proc_terminate($proc);
            \proc_close($proc);
        }
        $this->servers = [];
        if ($this->logFile !== '' && \is_file($this->logFile)) {
            \unlink($this->logFile);
        }
    }

    /**
     * Two REAL local servers on different ports: the origin answers 307 to the
     * other port, which logs the Authorization it received. A real transport is
     * the point - Symfony's MockHttpClient does not follow redirects, so a mock
     * control passes whether or not the rewrap works.
     *
     * @return array{int, int}
     */
    private function startServers(): array
    {
        $this->logFile = (string) \tempnam(\sys_get_temp_dir(), 'gisl-redirect-');
        $router = (string) \tempnam(\sys_get_temp_dir(), 'gisl-router-') . '.php';
        $ports = [];
        foreach ([0, 1] as $i) {
            $sock = \stream_socket_server('tcp://127.0.0.1:0');
            self::assertNotFalse($sock);
            $name = (string) \stream_socket_get_name($sock, false);
            $ports[$i] = (int) \substr($name, (int) \strrpos($name, ':') + 1);
            \fclose($sock);
        }
        \file_put_contents($router, \sprintf(
            '<?php if ((int) $_SERVER["SERVER_PORT"] === %d) { header("Location: http://127.0.0.1:%d/collect", true, 307); return; }'
            . ' file_put_contents(%s, ($_SERVER["HTTP_AUTHORIZATION"] ?? "<none>") . "\n", FILE_APPEND);'
            . ' header("Content-Type: application/json"); echo "{\"success\":true,\"data\":{\"tier\":\"free\"}}";',
            $ports[0],
            $ports[1],
            \var_export($this->logFile, true),
        ));
        foreach ($ports as $port) {
            $proc = \proc_open([\PHP_BINARY, '-S', "127.0.0.1:{$port}", $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            self::assertIsResource($proc);
            $this->servers[] = $proc;
            for ($try = 0; $try < 50; $try++) {
                $probe = @\fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if ($probe !== false) {
                    \fclose($probe);
                    continue 2;
                }
                \usleep(100_000);
            }
            self::fail("local server on {$port} did not start");
        }
        return [$ports[0], $ports[1]];
    }

    private function sdkOver(\Psr\Http\Client\ClientInterface $http, int $port): GislClient
    {
        $factory = new HttpFactory();
        return new GislClient(
            config: new GislClientConfig(baseUrl: "http://127.0.0.1:{$port}", apiKey: 'sk_live_secret'),
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
        );
    }

    /** @return list<string> */
    private function authSeenByTheOtherPort(): array
    {
        $raw = (string) \file_get_contents($this->logFile);
        return $raw === '' ? [] : \explode("\n", \trim($raw));
    }

    /** A discovered Symfony client is switched to max_redirects 0 (codex ea4c933ae2d8). */
    public function testADiscoveredSymfonyClientDoesNotFollowToAnotherPort(): void
    {
        [$origin] = $this->startServers();
        // Through the PUBLIC entry point: no client injected, discovery yields a
        // following Symfony client, and the constructor must switch it off
        // (codex 17b915c107f7 - a manual wrap here would stay green if the
        // constructor stopped wrapping).
        // getStrategies() returns an array; iterator_to_array() accepts one only
        // from PHP 8.2, and composer.json admits 8.1 (py7FB6jR).
        $strategies = ClassDiscovery::getStrategies();
        $saved = \is_array($strategies) ? \array_values($strategies) : \iterator_to_array($strategies, false);
        ClassDiscovery::prependStrategy(SymfonyNativeDiscoveryStrategy::class);
        ClassDiscovery::clearCache();
        try {
            $factory = new HttpFactory();
            $sdk = new GislClient(
                config: new GislClientConfig(baseUrl: "http://127.0.0.1:{$origin}", apiKey: 'sk_live_secret'),
                requestFactory: $factory,
                streamFactory: $factory,
            );
        } finally {
            ClassDiscovery::setStrategies($saved);
            ClassDiscovery::clearCache();
        }

        try {
            $sdk->getCreditsBalance();
            self::fail('a redirect must not be followed to a success');
        } catch (GislError $e) {
            self::assertStringContainsString('answered 307 (a redirect to host 127.0.0.1)', $e->getMessage());
        }
        self::assertSame([], $this->authSeenByTheOtherPort(), 'the redirect was followed');
    }

    /** Control: the same transport UNWRAPPED reaches the other port, so the test above can fail. */
    public function testAnUnwrappedSymfonyClientFollows(): void
    {
        [$origin] = $this->startServers();
        $this->sdkOver(new Psr18Client(new NativeHttpClient()), $origin)->getCreditsBalance();

        self::assertCount(1, $this->authSeenByTheOtherPort());
    }

    public function testNonSymfonyClientsAreReturnedUnchanged(): void
    {
        $guzzle = new Client();
        self::assertSame($guzzle, GislClient::withoutRedirects($guzzle));
    }
}

/** Test-only: makes php-http/discovery yield a redirect-FOLLOWING Symfony client. */
final class SymfonyNativeDiscoveryStrategy implements DiscoveryStrategy
{
    /** @return list<array{class: callable(): Psr18Client}> */
    public static function getCandidates($type): array
    {
        if ($type !== \Psr\Http\Client\ClientInterface::class) {
            return [];
        }
        return [['class' => static fn (): Psr18Client => new Psr18Client(new NativeHttpClient())]];
    }
}
