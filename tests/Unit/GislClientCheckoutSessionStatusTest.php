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

    public function testAMalformedBodyIsAContractError(): void
    {
        // label => [data, the path the error must name].
        $cases = [
            'missing status' => [['session_id' => 'cs_1'], 'status'],
            'non-string status' => [['session_id' => 'cs_1', 'status' => 1], 'status'],
            'missing session_id' => [['status' => 'paid'], 'session_id'],
            'non-string session_id' => [['session_id' => 7, 'status' => 'paid'], 'session_id'],
            'data is a list' => [['cs_1', 'paid'], null],
            // codex 217e07fe004f: json_decode(assoc) cannot tell `{}` from `[]`, and both
            // used to skip the checks and hydrate a model with null required fields.
            'empty object' => [new \stdClass(), 'session_id'],
            'empty list' => [[], 'session_id'],
        ];

        self::assertNotSame([], $cases);

        $failures = [];
        foreach ($cases as $label => [$data, $field]) {
            try {
                // A fresh client per row.
                $client = $this->clientAnswering($this->json(200, ['success' => true, 'data' => $data]));
                $client->getCheckoutSessionStatus('cs_1');
                $failures[] = "{$label}: a malformed body must throw";
            } catch (GislResponseContractError $e) {
                if ($e->path !== $field) {
                    $failures[] = "{$label}: path is " . \var_export($e->path, true) . ', expected ' . \var_export($field, true);
                }
            } catch (\Throwable $e) {
                $failures[] = "{$label}: threw " . $e::class . ": {$e->getMessage()}";
            }
        }

        self::assertSame([], $failures, \implode("\n", $failures));
    }

    /**
     * codex af81ae4ef038: a 2xx the SDK cannot read as the contracted JSON envelope is
     * the response violating the contract (as getHealth() treats it), not a bare GislError.
     */
    public function testAnUnreadable2xxIsAContractError(): void
    {
        $cases = [
            'empty body' => '',
            'not JSON' => '<html>gateway</html>',
            'JSON scalar' => '"paid"',
            'envelope without data' => '{"success":true}',
            'envelope without success' => '{"data":{"session_id":"cs_1","status":"paid"}}',
        ];

        self::assertNotSame([], $cases);

        $failures = [];
        foreach ($cases as $label => $body) {
            try {
                // A fresh client per row.
                $client = $this->clientAnswering(new Response(200, ['Content-Type' => 'application/json'], $body));
                $client->getCheckoutSessionStatus('cs_1');
                $failures[] = "{$label}: an unreadable 2xx must throw";
            } catch (GislResponseContractError $e) {
                if (!\str_contains($e->getMessage(), '/api/billing/checkout/cs_1/status')) {
                    $failures[] = "{$label}: message '{$e->getMessage()}' does not name the endpoint";
                }
            } catch (\Throwable $e) {
                $failures[] = "{$label}: threw " . $e::class . ": {$e->getMessage()}";
            }
        }

        self::assertSame([], $failures, \implode("\n", $failures));
    }

    /**
     * codex b7a99b89c985: the contract declares only 200 as success here.
     */
    public function testANon200SuccessWithAValidBodyIsAContractError(): void
    {
        $rows = [201, 202, 206];
        self::assertNotSame([], $rows);

        $failures = [];
        foreach ($rows as $status) {
            try {
                // A fresh client per row.
                $client = $this->clientAnswering($this->json($status, [
                    'success' => true,
                    'data' => ['session_id' => 'cs_1', 'status' => 'paid'],
                ]));
                $client->getCheckoutSessionStatus('cs_1');
                $failures[] = "{$status}: a {$status} must not be accepted";
            } catch (GislResponseContractError $e) {
                if (!\str_contains($e->getMessage(), (string) $status)) {
                    $failures[] = "{$status}: message '{$e->getMessage()}' does not name the status";
                }
            } catch (\Throwable $e) {
                $failures[] = "{$status}: threw " . $e::class . ": {$e->getMessage()}";
            }
        }

        self::assertSame([], $failures, \implode("\n", $failures));
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
