<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Generated\OpenApi\Model\CheckoutSessionStatusResponseData;
use Gisl\Sdk\Errors\GislApiError;
use Gisl\Sdk\Errors\GislAuthError;
use Gisl\Sdk\Errors\GislError;
use Gisl\Sdk\Errors\GislFeatureRequiresAuthError;
use Gisl\Sdk\Errors\GislResponseContractError;
use Gisl\Sdk\Gisl;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * NzdriXAK: getCheckoutSessionStatus(). `GET /api/billing/checkout/{sessionId}/status`
 * (beta, auth required). The three statuses are a CLOSED enum, and `unknown` is an
 * ordinary answer, not an error. Mirrors the TS `checkout-session-status.test.ts`.
 */
#[CoversClass(GislClient::class)]
final class GislClientCheckoutSessionStatusTest extends TestCase
{
    private HttpFactory $factory;

    /** @var list<RequestInterface> */
    private array $captured = [];

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
        $this->captured = [];
    }

    private function clientAnswering(ResponseInterface $response): GislClient
    {
        $captured = &$this->captured;
        $http = new class ($response, $captured) implements ClientInterface {
            /** @var list<RequestInterface> */
            private array $captured;

            /** @param list<RequestInterface> $captured */
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

        return new GislClient(
            config: new GislClientConfig(baseUrl: 'https://api.example.com', apiKey: 'sk_live_secret'),
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

    /** @return iterable<string, array{string}> */
    public static function statuses(): iterable
    {
        yield 'paid' => ['paid'];
        yield 'pending' => ['pending'];
        yield 'unknown' => ['unknown'];
    }

    #[DataProvider('statuses')]
    public function testReturnsEachStatusAsTheGeneratedModel(string $status): void
    {
        $client = $this->clientAnswering($this->json(200, [
            'success' => true,
            'data' => ['session_id' => 'cs_test_a1b2c3', 'status' => $status],
        ]));

        $result = $client->getCheckoutSessionStatus('cs_test_a1b2c3');

        self::assertInstanceOf(CheckoutSessionStatusResponseData::class, $result);
        self::assertSame('cs_test_a1b2c3', $result->getSessionId());
        self::assertSame($status, $result->getStatus());
        self::assertCount(1, $this->captured);
        self::assertSame('GET', $this->captured[0]->getMethod());
        self::assertSame('/api/billing/checkout/cs_test_a1b2c3/status', $this->captured[0]->getUri()->getPath());
        self::assertSame('Bearer sk_live_secret', $this->captured[0]->getHeaderLine('Authorization'));
    }

    public function testEncodesTheSessionIdAsOnePathSegment(): void
    {
        $client = $this->clientAnswering($this->json(200, [
            'success' => true,
            'data' => ['session_id' => 'cs/../x y?z#', 'status' => 'unknown'],
        ]));

        $client->getCheckoutSessionStatus('cs/../x y?z#');

        self::assertSame(
            '/api/billing/checkout/cs%2F..%2Fx%20y%3Fz%23/status',
            $this->captured[0]->getUri()->getPath(),
        );
    }

    public function testRefusesAnEmptySessionIdBeforeAnyRequest(): void
    {
        $client = $this->clientAnswering($this->json(200, []));

        try {
            $client->getCheckoutSessionStatus('');
            self::fail('an empty session id must throw');
        } catch (GislError $e) {
            self::assertStringContainsString('sessionId must be a non-empty string', $e->getMessage());
        }
        self::assertSame([], $this->captured);
    }

    public function testAStatusOutsideTheClosedEnumIsAContractError(): void
    {
        $client = $this->clientAnswering($this->json(200, [
            'success' => true,
            'data' => ['session_id' => 'cs_1', 'status' => 'refunded'],
        ]));

        try {
            $client->getCheckoutSessionStatus('cs_1');
            self::fail('an unknown status must not pass through');
        } catch (GislResponseContractError $e) {
            self::assertSame('status', $e->path);
            self::assertStringContainsString('"refunded"', $e->getMessage());
            self::assertStringContainsString('/api/billing/checkout/cs_1/status', $e->getMessage());
        }
    }

    /** @return iterable<string, array{array<string, mixed>|list<mixed>, string|null}> */
    public static function malformedData(): iterable
    {
        yield 'missing status' => [['session_id' => 'cs_1'], 'status'];
        yield 'non-string status' => [['session_id' => 'cs_1', 'status' => 1], 'status'];
        yield 'missing session_id' => [['status' => 'paid'], 'session_id'];
        yield 'non-string session_id' => [['session_id' => 7, 'status' => 'paid'], 'session_id'];
        yield 'data is a list' => [['cs_1', 'paid'], null];
    }

    /** @param array<string, mixed>|list<mixed> $data */
    #[DataProvider('malformedData')]
    public function testAMalformedBodyIsAContractError(array $data, ?string $field): void
    {
        $client = $this->clientAnswering($this->json(200, ['success' => true, 'data' => $data]));

        try {
            $client->getCheckoutSessionStatus('cs_1');
            self::fail('a malformed body must throw');
        } catch (GislResponseContractError $e) {
            self::assertSame($field, $e->path);
        }
    }

    /**
     * codex 217e07fe004f: json_decode(assoc) cannot tell `{}` from `[]`, and both
     * used to skip the checks and hydrate a model with null required fields.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function emptyData(): iterable
    {
        yield 'empty object' => [new \stdClass()];
        yield 'empty list' => [[]];
    }

    #[DataProvider('emptyData')]
    public function testAnEmptyDataIsAContractError(mixed $data): void
    {
        $client = $this->clientAnswering($this->json(200, ['success' => true, 'data' => $data]));

        try {
            $client->getCheckoutSessionStatus('cs_1');
            self::fail('an empty `data` must not hydrate a model with null required fields');
        } catch (GislResponseContractError $e) {
            self::assertSame('session_id', $e->path);
        }
    }

    /**
     * codex af81ae4ef038: a 2xx the SDK cannot read as the contracted JSON envelope is
     * the response violating the contract (as getHealth() treats it), not a bare GislError.
     *
     * @return iterable<string, array{string}>
     */
    public static function unreadable2xxBodies(): iterable
    {
        yield 'empty body' => [''];
        yield 'not JSON' => ['<html>gateway</html>'];
        yield 'JSON scalar' => ['"paid"'];
        yield 'envelope without data' => ['{"success":true}'];
        yield 'envelope without success' => ['{"data":{"session_id":"cs_1","status":"paid"}}'];
    }

    #[DataProvider('unreadable2xxBodies')]
    public function testAnUnreadable2xxIsAContractError(string $body): void
    {
        $client = $this->clientAnswering(new Response(200, ['Content-Type' => 'application/json'], $body));

        try {
            $client->getCheckoutSessionStatus('cs_1');
            self::fail('an unreadable 2xx must throw');
        } catch (GislResponseContractError $e) {
            self::assertStringContainsString('/api/billing/checkout/cs_1/status', $e->getMessage());
        }
    }

    /**
     * codex b7a99b89c985: the contract declares only 200 as success here.
     *
     * @return iterable<string, array{int}>
     */
    public static function non200Successes(): iterable
    {
        yield '201' => [201];
        yield '202' => [202];
        yield '206' => [206];
    }

    #[DataProvider('non200Successes')]
    public function testANon200SuccessWithAValidBodyIsAContractError(int $status): void
    {
        $client = $this->clientAnswering($this->json($status, [
            'success' => true,
            'data' => ['session_id' => 'cs_1', 'status' => 'paid'],
        ]));

        try {
            $client->getCheckoutSessionStatus('cs_1');
            self::fail("a {$status} must not be accepted");
        } catch (GislResponseContractError $e) {
            self::assertStringContainsString((string) $status, $e->getMessage());
        }
    }

    public function testA401GoesThroughTheSharedMapping(): void
    {
        $client = $this->clientAnswering($this->json(401, [
            'success' => false,
            'error' => 'AUTHENTICATION_REQUIRED',
            'message' => 'Authentication required.',
        ]));

        try {
            $client->getCheckoutSessionStatus('cs_1');
            self::fail('a 401 must throw');
        } catch (GislAuthError $e) {
            self::assertInstanceOf(GislApiError::class, $e);
            self::assertSame(401, $e->statusCode);
        }
    }

    public function testARouter404GoesThroughTheSharedMapping(): void
    {
        $client = $this->clientAnswering($this->json(404, [
            'success' => false,
            'error' => 'NOT_FOUND',
            'message' => 'Not found.',
        ]));

        try {
            $client->getCheckoutSessionStatus('cs_%FF');
            self::fail('a 404 must throw');
        } catch (GislApiError $e) {
            self::assertNotInstanceOf(GislResponseContractError::class, $e);
            self::assertSame(404, $e->statusCode);
        }
    }

    public function testAnAnonymousClientRefusesItBeforeAnyRequest(): void
    {
        $client = Gisl::anonymous(
            baseUrl: 'https://api.example.com',
            httpClient: new class () implements ClientInterface {
                public function sendRequest(RequestInterface $request): ResponseInterface
                {
                    throw new class ('an anonymous gate let a request through') extends \RuntimeException implements ClientExceptionInterface {};
                }
            },
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );

        try {
            $client->getCheckoutSessionStatus('cs_1');
            self::fail('an anonymous client must refuse');
        } catch (GislFeatureRequiresAuthError $e) {
            self::assertSame('getCheckoutSessionStatus', $e->operation);
        }
    }
}
