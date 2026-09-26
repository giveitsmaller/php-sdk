<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Generated\OpenApi\Model\AuthenticatedIdentity;
use Gisl\Generated\OpenApi\Model\UserTier;
use Gisl\Sdk\Errors\GislApiError;
use Gisl\Sdk\Errors\GislAuthError;
use Gisl\Sdk\Errors\GislResponseContractError;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Unit coverage for 6zgxH2JI — `getProfile()`, the whoami read
 * (`GET /api/auth/profile`) a caller uses to check which account its key
 * resolves to before a destructive call.
 *
 * Mirrors {@see GislClientAccountLimitsTest}'s mock-transport style and
 * `packages/typescript/tests/unit/client.test.ts` `getProfile`.
 */
#[CoversClass(GislClient::class)]
final class GislClientProfileTest extends TestCase
{
    private HttpFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    /**
     * @param list<RequestInterface> $captured
     */
    private function stubClient(ResponseInterface $response, array &$captured): ClientInterface
    {
        return new class ($response, $captured) implements ClientInterface {
            /** @var list<RequestInterface> */
            private array $captured;

            /**
             * @param list<RequestInterface> $captured
             */
            public function __construct(private ResponseInterface $response, array &$captured)
            {
                $this->captured = &$captured;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;
                return $this->response;
            }
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], \json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<RequestInterface> $captured
     */
    private function clientReturning(ResponseInterface $response, array &$captured = []): GislErgonomicClient
    {
        return new GislErgonomicClient(
            config: new GislClientConfig(baseUrl: 'https://api.example.com', apiKey: 'sk_test'),
            httpClient: $this->stubClient($response, $captured),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    /** @return array<string, mixed> */
    private function identity(): array
    {
        return [
            'id' => 'usr-01936fb2',
            'email' => 'owner@example.com',
            'name' => null,
            'tier' => 'pro',
            'email_verified' => true,
            'pending_email' => null,
            'created_at' => '2026-08-22T10:00:00+00:00',
            'delete_requested_at' => null,
        ];
    }

    public function testGetProfileUnwrapsTheIdentityTheKeyResolvesTo(): void
    {
        $captured = [];
        $client = $this->clientReturning(
            $this->jsonResponse(200, ['success' => true, 'data' => ['user' => $this->identity()]]),
            $captured,
        );

        $me = $client->getProfile();

        self::assertInstanceOf(AuthenticatedIdentity::class, $me);
        self::assertSame('usr-01936fb2', $me->getId());
        self::assertSame('owner@example.com', $me->getEmail());
        self::assertSame(UserTier::PRO, $me->getTier());
        self::assertTrue($me->getEmailVerified());
        self::assertInstanceOf(\DateTimeInterface::class, $me->getCreatedAt());

        // Path-drift guard.
        self::assertCount(1, $captured);
        self::assertSame('GET', $captured[0]->getMethod());
        self::assertSame('/api/auth/profile', $captured[0]->getUri()->getPath());
        self::assertSame('', $captured[0]->getUri()->getQuery());
    }

    public function testUnauthorisedWithAuthErrorTypeIsGislAuthError(): void
    {
        $client = $this->clientReturning($this->jsonResponse(401, [
            'success' => false,
            'error' => 'API_KEY_INVALID',
            'error_type' => 'api_key_invalid',
            'message' => 'The provided API key is invalid or no longer recognised.',
        ]));

        try {
            $client->getProfile();
            self::fail('expected GislAuthError');
        } catch (GislAuthError $e) {
            self::assertSame(401, $e->statusCode);
        }
    }

    /**
     * A bare ErrorEnvelope 401 is still a GislAuthError in PHP — the shared
     * dispatcher's 401-always-GislAuthError fallback, the same as every other
     * call. (TS raises its base GislApiError here; that divergence is
     * documented in `GislClient::unwrapEnvelope`, not specific to this call.)
     */
    public function testBareUnauthorisedEnvelopeIsGislAuthError(): void
    {
        $client = $this->clientReturning($this->jsonResponse(401, [
            'success' => false,
            'error' => 'AUTHENTICATION_REQUIRED',
            'message' => 'Authentication required.',
        ]));

        try {
            $client->getProfile();
            self::fail('expected GislAuthError');
        } catch (GislAuthError $e) {
            self::assertSame(401, $e->statusCode);
            self::assertSame('AUTHENTICATION_REQUIRED', $e->errorCode);
        }
    }

    public function testUserNotFoundIsGislApiError(): void
    {
        $client = $this->clientReturning($this->jsonResponse(404, [
            'success' => false,
            'error' => 'USER_NOT_FOUND',
            'message' => 'User not found.',
        ]));

        try {
            $client->getProfile();
            self::fail('expected GislApiError');
        } catch (GislApiError $e) {
            self::assertNotInstanceOf(GislAuthError::class, $e);
            self::assertSame(404, $e->statusCode);
        }
    }

    /**
     * @return iterable<string, array{mixed, ?string}>
     */
    public static function malformedData(): iterable
    {
        yield 'missing user' => [[], 'user'];
        yield 'user is null' => [['user' => null], 'user'];
        yield 'user without id' => [['user' => ['email' => 'a@example.com']], 'id'];
        yield 'data is a list' => [[1, 2], null];
        yield 'data is a scalar' => ['nope', null];
    }

    #[DataProvider('malformedData')]
    public function testMalformedSuccessBodyIsResponseContractError(mixed $data, ?string $expectedPath): void
    {
        $client = $this->clientReturning($this->jsonResponse(200, ['success' => true, 'data' => $data]));

        try {
            $client->getProfile();
            self::fail('expected GislResponseContractError');
        } catch (GislResponseContractError $e) {
            self::assertSame('/api/auth/profile', $e->operation);
            self::assertSame($expectedPath, $e->path);
            self::assertStringContainsString('/api/auth/profile', $e->getMessage());
        }
    }
}
