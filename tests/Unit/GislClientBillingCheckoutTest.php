<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Generated\OpenApi\Model\BillingCheckoutRequest;
use Gisl\Generated\OpenApi\Model\BillingCheckoutSession;
use Gisl\Sdk\Errors\GislApiError;
use Gisl\Sdk\Errors\GislFeatureNotAvailableError;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 2AkFcgxY: createCheckoutSession(). The 422 flag-off and the 503 Stripe-unconfigured
 * cases mean OPPOSITE things about a deploy, so each is pinned to its own catchable
 * shape and the two are asserted not to collapse.
 */
#[CoversClass(GislClient::class)]
final class GislClientBillingCheckoutTest extends TestCase
{
    private HttpFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    /**
     * @param list<ResponseInterface|\Throwable> $queue
     * @param list<RequestInterface>              $captured
     * @param-out list<RequestInterface>          $captured
     */
    private function stubClient(array $queue, array &$captured = []): ClientInterface
    {
        $captured = [];
        return new class ($queue, $captured) implements ClientInterface {
            /** @var list<ResponseInterface|\Throwable> */
            private array $queue;
            /** @var list<RequestInterface> */
            private array $captured;

            /**
             * @param list<ResponseInterface|\Throwable> $queue
             * @param list<RequestInterface>             $captured
             */
            public function __construct(array $queue, array &$captured)
            {
                $this->queue = $queue;
                $this->captured = &$captured;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;
                $next = \array_shift($this->queue);
                if ($next === null) {
                    throw new \RuntimeException('Stub PSR-18 client: response queue exhausted');
                }
                if ($next instanceof \Throwable) {
                    if (!$next instanceof ClientExceptionInterface) {
                        throw new \LogicException(
                            'Queued throwables must implement ClientExceptionInterface; got ' . \get_class($next),
                        );
                    }
                    throw $next;
                }
                return $next;
            }
        };
    }

    private function makeClient(ClientInterface $http): GislClient
    {
        return new GislClient(
            config: new GislClientConfig(baseUrl: 'https://api.example.com'),
            httpClient: $http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    /** @param array<string, mixed> $body */
    private function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], \json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function payload(): BillingCheckoutRequest
    {
        return new BillingCheckoutRequest(['type' => 'pack', 'key' => 'pack_25']);
    }

    public function testPostsTypeAndKeyAndHydratesTheSession(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([
            $this->json(200, ['success' => true, 'data' => [
                'checkout_url' => 'https://checkout.stripe.com/c/pay/cs_test_a1b2c3',
                'session_id' => 'cs_test_a1b2c3',
            ]]),
        ], $captured));

        $session = $client->createCheckoutSession($this->payload());

        self::assertInstanceOf(BillingCheckoutSession::class, $session);
        self::assertSame('https://checkout.stripe.com/c/pay/cs_test_a1b2c3', $session->getCheckoutUrl());
        self::assertSame('cs_test_a1b2c3', $session->getSessionId());
        self::assertCount(1, $captured);
        self::assertSame('POST', $captured[0]->getMethod());
        self::assertSame('/api/billing/checkout', $captured[0]->getUri()->getPath());
        self::assertSame(
            ['type' => 'pack', 'key' => 'pack_25'],
            \json_decode((string) $captured[0]->getBody(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testFlagOff422IsAFeatureNotAvailableError(): void
    {
        $client = $this->makeClient($this->stubClient([
            $this->json(422, [
                'success' => false,
                'error_type' => 'feature_not_available',
                'error' => 'UNPROCESSABLE_ENTITY',
                'message' => 'Checkout is not yet available.',
                'violations' => [[
                    'feature' => 'endpoint.billing.checkout',
                    'availability' => 'planned',
                    'message_key' => 'feature.not_available',
                ]],
            ]),
        ]));

        try {
            $client->createCheckoutSession($this->payload());
            self::fail('a flagged-off checkout must throw');
        } catch (GislFeatureNotAvailableError $e) {
            self::assertSame(422, $e->statusCode);
        }
    }

    public function testStripeUnconfigured503IsNotAFeatureNotAvailableError(): void
    {
        $client = $this->makeClient($this->stubClient([
            $this->json(503, [
                'success' => false,
                'error' => 'SERVICE_UNAVAILABLE',
                'message' => 'Checkout is temporarily unavailable.',
            ]),
        ]));

        try {
            $client->createCheckoutSession($this->payload());
            self::fail('a 503 must throw');
        } catch (GislApiError $e) {
            self::assertNotInstanceOf(GislFeatureNotAvailableError::class, $e);
            self::assertSame(503, $e->statusCode);
            self::assertSame('SERVICE_UNAVAILABLE', $e->errorCode);
        }
    }

    public function testUnresolvablePair422IsAPlainApiError(): void
    {
        $client = $this->makeClient($this->stubClient([
            $this->json(422, [
                'success' => false,
                'error' => 'UNPROCESSABLE_ENTITY',
                'message' => 'The requested plan or pack is not available.',
            ]),
        ]));

        try {
            $client->createCheckoutSession($this->payload());
            self::fail('an unresolvable pair must throw');
        } catch (GislApiError $e) {
            self::assertNotInstanceOf(GislFeatureNotAvailableError::class, $e);
            self::assertSame('UNPROCESSABLE_ENTITY', $e->errorCode);
        }
    }
}
