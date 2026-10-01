<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Generated\OpenApi\Model\LivenessResponse;
use Gisl\Sdk\Errors\GislApiError;
use Gisl\Sdk\Errors\GislError;
use Gisl\Sdk\Errors\GislResponseContractError;
use Gisl\Sdk\Errors\GislTransportError;
use Gisl\Sdk\Gisl;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * QB5Lrcjo — `getHealth()`: unauthenticated `GET /healthz` returning the
 * contract's flat `LivenessResponse { app: bool, build?: string }`.
 *
 * Driven through Guzzle's own handler stack with a history middleware, as
 * {@see RedirectCredentialTest} does, so the redirect cases exercise the real
 * PSR-18 client: a followed redirect would leave two requests in the history.
 */
#[CoversClass(GislClient::class)]
final class GislClientHealthTest extends TestCase
{
    /**
     * @param list<ResponseInterface|\Throwable>      $queue
     * @param list<array{request: RequestInterface}> $history
     * @param array<string, string>                   $headers
     */
    private function client(array $queue, array &$history, array $headers = [], bool $useSessionCookie = false): GislClient
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));
        $factory = new HttpFactory();

        return new GislClient(
            config: new GislClientConfig(
                baseUrl: 'https://api.example.com',
                apiKey: 'sk_live_secret',
                headers: $headers,
                useSessionCookie: $useSessionCookie,
            ),
            httpClient: new Client(['handler' => $stack]),
            requestFactory: $factory,
            streamFactory: $factory,
        );
    }

    private static function json(string $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], $body);
    }

    public function testGetsHealthzWithNoAuthorizationFromAKeyedClient(): void
    {
        $history = [];
        $this->client([self::json('{"app":true,"build":"1.17.0-rc.1"}')], $history)->getHealth();

        self::assertCount(1, $history);
        $request = $history[0]['request'];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://api.example.com/healthz', (string) $request->getUri());
        self::assertFalse($request->hasHeader('Authorization'));
        self::assertStringNotContainsString('sk_live_secret', \json_encode($request->getHeaders(), JSON_THROW_ON_ERROR));
    }

    public function testPositiveControlTheSameKeyedClientSendsAuthorizationOnAnAuthenticatedCall(): void
    {
        // Without this, the test above would pass on a client that never sends a key.
        $history = [];
        $this->client([self::json('{"success":true,"data":{"balance":1}}')], $history)->getCreditsBalance();

        self::assertSame('Bearer sk_live_secret', $history[0]['request']->getHeaderLine('Authorization'));
    }

    public function testStripsCredentialHeadersPassedThroughConfigHeadersAndKeepsTheRest(): void
    {
        $history = [];
        $this->client(
            [self::json('{"app":true}')],
            $history,
            [
                'authorization' => 'Bearer from-headers',
                'COOKIE' => 'gisl_session=abc',
                'x-workflow-capability' => 'cap_123',
                'X-Trace' => 'kept',
            ],
        )->getHealth();

        $request = $history[0]['request'];
        self::assertFalse($request->hasHeader('Authorization'));
        self::assertFalse($request->hasHeader('Cookie'));
        self::assertFalse($request->hasHeader('X-Workflow-Capability'));
        self::assertSame('kept', $request->getHeaderLine('X-Trace'));
    }

    public function testSendsNoSessionCookieAfterLogin(): void
    {
        $history = [];
        $client = $this->client(
            [
                new Response(
                    200,
                    ['Content-Type' => 'application/json', 'Set-Cookie' => 'gisl_session=sess123; Path=/; HttpOnly'],
                    '{"success":true,"data":{"user":{"id":"01936fb2-0000-7000-8000-000000000001","email":"a@example.com","tier":"free"}}}',
                ),
                self::json('{"success":true,"data":{"balance":1}}'),
                self::json('{"app":true}'),
            ],
            $history,
            [],
            true,
        );
        $client->login(new \Gisl\Generated\OpenApi\Model\LoginUserRequest([
            'email' => 'a@example.com',
            'password' => 'pw',
        ]));
        $client->getCreditsBalance();
        $client->getHealth();

        // Positive control: the captured cookie IS forwarded on an authenticated call.
        self::assertSame('gisl_session=sess123', $history[1]['request']->getHeaderLine('Cookie'));
        self::assertFalse($history[2]['request']->hasHeader('Cookie'));
    }

    public function testBuildPresentIsReturned(): void
    {
        $history = [];
        $health = $this->client([self::json('{"app":true,"build":"1.17.0-rc.1"}')], $history)->getHealth();

        self::assertInstanceOf(LivenessResponse::class, $health);
        self::assertTrue($health->getApp());
        self::assertSame('1.17.0-rc.1', $health->getBuild());
    }

    public function testBuildAbsentIsNull(): void
    {
        $history = [];
        $health = $this->client([self::json('{"app":true}')], $history)->getHealth();

        self::assertTrue($health->getApp());
        self::assertNull($health->getBuild());
    }

    public function testAppFalseIsAValidAnswer(): void
    {
        $history = [];
        $health = $this->client([self::json('{"app":false,"build":"dev"}')], $history)->getHealth();

        self::assertFalse($health->getApp());
        self::assertSame('dev', $health->getBuild());
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedBodies(): iterable
    {
        yield 'app missing' => ['{"build":"1.0.0"}', '`app` must be a boolean'];
        yield 'app a string' => ['{"app":"true"}', '`app` must be a boolean'];
        yield 'app a number' => ['{"app":1}', '`app` must be a boolean'];
        yield 'build a number' => ['{"app":true,"build":117}', '`build`, when present, must be a string'];
        yield 'build null' => ['{"app":true,"build":null}', '`build`, when present, must be a string'];
        yield 'a JSON list' => ['[{"app":true}]', 'expected a JSON object'];
        yield 'JSON null' => ['null', 'expected a JSON object'];
        yield 'empty object' => ['{}', '`app` must be a boolean'];
        yield 'an envelope' => ['{"success":true,"data":{"app":true}}', '`app` must be a boolean'];
        yield 'not JSON' => ['<html>ok</html>', 'body is not valid JSON'];
        yield 'empty body' => ['', 'body is not valid JSON'];
    }

    #[DataProvider('malformedBodies')]
    public function testAMalformed2xxIsAResponseContractError(string $body, string $detail): void
    {
        $history = [];
        try {
            $this->client([self::json($body)], $history)->getHealth();
            self::fail('a malformed body must not hydrate');
        } catch (GislResponseContractError $e) {
            self::assertSame('/healthz', $e->operation);
            self::assertStringContainsString($detail, $e->getMessage());
        }
    }

    /** @return iterable<string, array{int}> */
    public static function redirects(): iterable
    {
        yield '301' => [301];
        yield '302' => [302];
        yield '307' => [307];
        yield '308' => [308];
    }

    #[DataProvider('redirects')]
    public function testARedirectIsNotFollowedAndIsNamed(int $status): void
    {
        $history = [];
        // The follow-up 200 is QUEUED: if the client followed, the call would
        // succeed and the history would hold two requests.
        $client = $this->client(
            [
                new Response($status, ['Location' => 'https://elsewhere.example.net/healthz']),
                self::json('{"app":true}'),
            ],
            $history,
        );

        try {
            $client->getHealth();
            self::fail('a redirect must not be followed to a success');
        } catch (GislError $e) {
            self::assertNotInstanceOf(GislApiError::class, $e);
            self::assertStringContainsString("answered {$status} (a redirect to host elsewhere.example.net)", $e->getMessage());
            self::assertStringContainsString('does not follow redirects', $e->getMessage());
        }

        self::assertCount(1, $history, 'the redirect was followed');
    }

    public function testANon2xxEnvelopeTakesTheSharedErrorMapping(): void
    {
        $history = [];
        try {
            $this->client(
                [self::json('{"success":false,"error":"SERVICE_UNAVAILABLE","message":"Down for maintenance."}', 503)],
                $history,
            )->getHealth();
            self::fail('a 503 must throw');
        } catch (GislApiError $e) {
            self::assertSame(503, $e->statusCode);
            self::assertSame('SERVICE_UNAVAILABLE', $e->errorCode);
            self::assertSame('Down for maintenance.', $e->getMessage());
        }
    }

    public function testATransportFailureIsATransportError(): void
    {
        // The PHP SDK has no timeout of its own: the PSR-18 client's timeout
        // surfaces as a network failure, the same as on every other call.
        $history = [];
        $this->expectException(GislTransportError::class);
        $this->client(
            [new ConnectException('Connection timed out after 5000 ms', new Request('GET', 'https://api.example.com/healthz'))],
            $history,
        )->getHealth();
    }

    public function testIsReachableFromAnAnonymousClient(): void
    {
        self::assertContains('getHealth', Gisl::ANONYMOUS_ALLOWLIST);
    }
}
