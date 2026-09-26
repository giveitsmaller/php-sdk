<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Errors;

use Gisl\Sdk\Errors\GislApiError;
use Gisl\Sdk\Errors\GislError;
use Gisl\Sdk\Errors\GislNetworkError;
use Gisl\Sdk\Errors\GislResponseContractError;
use Gisl\Sdk\GetSchemaHitResult;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * u6Q9oxuI — a 2xx whose body violates the contract must reach the caller as a
 * typed GislResponseContractError: catchable as GislError, and distinguishable
 * from an API error (the exchange succeeded) and a network error (the body
 * arrived). Before this, the generated deserialiser's own throwable escaped raw.
 *
 * Mirrors `packages/typescript/tests/unit/response-contract-error.test.ts`.
 */
#[CoversClass(GislResponseContractError::class)]
#[CoversClass(GislClient::class)]
final class GislResponseContractErrorTest extends TestCase
{
    private const WORKFLOW_ID = '01936fb2-0000-7000-8000-000000000001';

    private function clientReturning(ResponseInterface $response): GislClient
    {
        $http = new class ($response) implements ClientInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
        $factory = new HttpFactory();

        return new GislClient(
            config: new GislClientConfig(baseUrl: 'https://api.example.com', apiKey: 'sk_test'),
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
        );
    }

    /** @param array<string, mixed>|string $body */
    private static function json(array|string $body, int $status = 200): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            \is_string($body) ? $body : \json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private static function validStatus(): array
    {
        return [
            'workflow_id' => self::WORKFLOW_ID,
            'status' => 'completed',
            'created_at' => '2026-09-25T00:00:00Z',
            'updated_at' => '2026-09-25T00:00:00Z',
            'jobs' => [],
        ];
    }

    private static function assertContractError(
        \Throwable $e,
        string $operation,
        ?string $path,
    ): GislResponseContractError {
        self::assertInstanceOf(GislResponseContractError::class, $e);
        self::assertInstanceOf(GislError::class, $e);
        self::assertNotInstanceOf(GislApiError::class, $e);
        self::assertNotInstanceOf(GislNetworkError::class, $e);
        /** @var GislResponseContractError $e */
        self::assertSame($operation, $e->operation);
        self::assertSame($path, $e->path);
        self::assertFalse($e->retryable());
        return $e;
    }

    private static function thrownBy(callable $call): \Throwable
    {
        try {
            $call();
        } catch (\Throwable $e) {
            return $e;
        }
        self::fail('expected the call to throw');
    }

    #[Test]
    public function the_class_carries_operation_path_and_cause(): void
    {
        $cause = new \InvalidArgumentException('boom');
        $e = new GislResponseContractError('m', '/api/x', 'jobs', $cause);
        self::assertSame('/api/x', $e->operation);
        self::assertSame('jobs', $e->path);
        self::assertSame($cause, $e->getPrevious());
        self::assertFalse($e->retryable());
        self::assertNull((new GislResponseContractError('m', '/api/x'))->path);
    }

    /**
     * DELIBERATE SCOPE, pinned so a change to it is a visible decision: an
     * ABSENT required field hydrates as null (TS leaves it `undefined`). New
     * required response fields ship contract-first, so failing the whole call
     * on one would break every call against a producer not yet deployed — the
     * co-land window. See GislClient::hydrate().
     */
    #[Test]
    public function an_absent_required_field_still_hydrates_as_null(): void
    {
        $data = self::validStatus();
        unset($data['created_at']);
        $client = $this->clientReturning(self::json(['success' => true, 'data' => $data]));

        $status = $client->getWorkflowStatus(self::WORKFLOW_ID);

        self::assertNull($status->getCreatedAt());
    }

    #[Test]
    public function get_schema_with_a_field_the_generated_model_rejects_throws_typed(): void
    {
        $client = $this->clientReturning(self::json([
            'schema_version' => '2.6.0',
            'capabilities_version' => 25,
            'generated_at' => '2026-04-29T12:00:00Z',
            'source_commit' => 'not-a-commit-sha!',
            'operations' => new \stdClass(),
        ]));

        $e = self::thrownBy(static fn () => $client->getSchema());

        self::assertContractError($e, '/api/operations/schema', 'source_commit');
        // Before u6Q9oxuI this escaped raw, outside the GislError hierarchy.
        self::assertInstanceOf(\InvalidArgumentException::class, $e->getPrevious());
    }

    #[Test]
    public function get_schema_non_json_2xx_throws_typed(): void
    {
        $client = $this->clientReturning(self::json('<html>not json</html>'));

        $e = self::thrownBy(static fn () => $client->getSchema());

        self::assertContractError($e, '/api/operations/schema', null);
        self::assertInstanceOf(\JsonException::class, $e->getPrevious());
    }

    #[Test]
    public function get_workflow_status_with_a_field_the_generated_model_rejects_throws_typed(): void
    {
        $data = self::validStatus();
        $data['workflow_id'] = 'wf-not-a-uuid';
        $client = $this->clientReturning(self::json(['success' => true, 'data' => $data]));

        $e = self::thrownBy(static fn () => $client->getWorkflowStatus(self::WORKFLOW_ID));

        self::assertContractError($e, '/api/workflows/' . self::WORKFLOW_ID . '/status', 'workflow_id');
        self::assertInstanceOf(\InvalidArgumentException::class, $e->getPrevious());
    }

    #[Test]
    public function a_wrong_typed_list_throws_typed_with_the_cause(): void
    {
        $data = self::validStatus();
        $data['jobs'] = 'not-a-list';
        $client = $this->clientReturning(self::json(['success' => true, 'data' => $data]));

        $e = self::thrownBy(static fn () => $client->getWorkflowStatus(self::WORKFLOW_ID));

        // "Invalid array" does not name the field, so path is unknown.
        self::assertContractError($e, '/api/workflows/' . self::WORKFLOW_ID . '/status', null);
        self::assertNotNull($e->getPrevious());
    }

    #[Test]
    public function a_scalar_data_payload_throws_typed_not_a_type_error(): void
    {
        $client = $this->clientReturning(self::json(['success' => true, 'data' => 'oops']));

        $e = self::thrownBy(static fn () => $client->getWorkflowStatus(self::WORKFLOW_ID));

        self::assertContractError($e, '/api/workflows/' . self::WORKFLOW_ID . '/status', null);
    }

    #[Test]
    public function a_list_data_payload_throws_typed_not_a_hollow_dto(): void
    {
        // A JSON list would otherwise be cast to an object with numeric keys.
        $client = $this->clientReturning(self::json(['success' => true, 'data' => [1, 2, 3]]));

        $e = self::thrownBy(static fn () => $client->getWorkflowStatus(self::WORKFLOW_ID));

        self::assertContractError($e, '/api/workflows/' . self::WORKFLOW_ID . '/status', null);
    }

    #[Test]
    public function get_schema_empty_2xx_body_throws_typed(): void
    {
        $client = $this->clientReturning(new Response(200, ['Content-Type' => 'application/json'], ''));

        $e = self::thrownBy(static fn () => $client->getSchema());

        self::assertContractError($e, '/api/operations/schema', null);
    }

    #[Test]
    public function the_operation_drops_the_query_string(): void
    {
        $client = $this->clientReturning(self::json(['success' => true, 'data' => ['workflows' => 'x', 'is_truncated' => false]]));

        $e = self::thrownBy(static fn () => $client->listWorkflows(limit: 5));

        self::assertInstanceOf(GislResponseContractError::class, $e);
        /** @var GislResponseContractError $e */
        self::assertSame('/api/workflows', $e->operation);
    }

    // Positive controls: the check must not fail a valid body.

    #[Test]
    public function a_valid_status_body_hydrates(): void
    {
        $client = $this->clientReturning(self::json(['success' => true, 'data' => self::validStatus()]));

        self::assertSame(self::WORKFLOW_ID, $client->getWorkflowStatus(self::WORKFLOW_ID)->getWorkflowId());
    }

    #[Test]
    public function a_valid_schema_body_hydrates(): void
    {
        $client = $this->clientReturning(self::json([
            'schema_version' => '2.6.0',
            'capabilities_version' => 25,
            'generated_at' => '2026-04-29T12:00:00Z',
            'operations' => new \stdClass(),
        ]));

        self::assertInstanceOf(GetSchemaHitResult::class, $client->getSchema());
    }

    #[Test]
    public function a_non_2xx_is_still_an_api_error(): void
    {
        $client = $this->clientReturning(
            self::json(['success' => false, 'error' => 'not_found', 'message' => 'nope'], 404),
        );

        $e = self::thrownBy(static fn () => $client->getWorkflowStatus(self::WORKFLOW_ID));

        self::assertInstanceOf(GislApiError::class, $e);
        self::assertNotInstanceOf(GislResponseContractError::class, $e);
    }
}
