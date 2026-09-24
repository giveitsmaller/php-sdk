<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Generated\OpenApi\Model\MetadataResponse;
use Gisl\Generated\OpenApi\Model\RetryResponse;
use Gisl\Generated\OpenApi\Model\WorkflowCancelResponse;
use Gisl\Generated\OpenApi\Model\WorkflowResumeResponse;
use Gisl\Sdk\Errors\GislApiError;
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

#[CoversClass(GislClient::class)]
final class GislClientWorkflowLifecycleTest extends TestCase
{
    private HttpFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    /**
     * @param list<ResponseInterface|\Throwable> $queue
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

    /**
     * @param array<string, mixed> $body
     */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        $encoded = \json_encode($body, JSON_THROW_ON_ERROR);
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            $encoded,
        );
    }

    private function makeClient(ClientInterface $http): GislClient
    {
        return new GislClient(
            config: new GislClientConfig(baseUrl: 'https://api.example.com', apiKey: 'sk_test'),
            httpClient: $http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    // ---------------------------------------------------------------
    // cancelWorkflow
    // ---------------------------------------------------------------

    // ---------------------------------------------------------------
    // archiveWorkflow / restoreWorkflow (mWQsiUun)
    // ---------------------------------------------------------------

    public function testArchiveWorkflowPostsAndHydrates(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([
            $this->jsonResponse(200, ['success' => true, 'data' => [
                'workflow_id' => '01936fb2-0000-7000-8000-000000000001',
                'status' => 'completed',
                'archived' => true,
                'archived_at' => '2026-09-23T20:00:00Z',
            ]]),
        ], $captured));

        $response = $client->archiveWorkflow('01936fb2-0000-7000-8000-000000000001');

        self::assertInstanceOf(\Gisl\Generated\OpenApi\Model\WorkflowArchiveResponse::class, $response);
        self::assertTrue($response->getArchived());
        self::assertSame('POST', $captured[0]->getMethod());
        self::assertSame('/api/workflows/01936fb2-0000-7000-8000-000000000001/archive', $captured[0]->getUri()->getPath());
    }

    public function testRestoreWorkflowPostsAndHydrates(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([
            $this->jsonResponse(200, ['success' => true, 'data' => [
                'workflow_id' => '01936fb2-0000-7000-8000-000000000001',
                'status' => 'completed',
                'archived' => false,
            ]]),
        ], $captured));

        $response = $client->restoreWorkflow('01936fb2-0000-7000-8000-000000000001');

        self::assertInstanceOf(\Gisl\Generated\OpenApi\Model\WorkflowRestoreResponse::class, $response);
        self::assertFalse($response->getArchived());
        self::assertSame('/api/workflows/01936fb2-0000-7000-8000-000000000001/restore', $captured[0]->getUri()->getPath());
    }

    public function testArchiveWorkflow409SurfacesAsApiError(): void
    {
        $client = $this->makeClient($this->stubClient([
            $this->jsonResponse(409, ['success' => false, 'error' => 'CONFLICT', 'message' => 'not terminal']),
        ]));

        try {
            $client->archiveWorkflow('01936fb2-0000-7000-8000-000000000001');
            self::fail('a non-terminal archive must surface the 409');
        } catch (\Gisl\Sdk\Errors\GislApiError $e) {
            self::assertSame(409, $e->statusCode);
        }
    }

    // ---------------------------------------------------------------
    // drJpKvXS: a RESPONSE enum value this SDK predates hydrates verbatim
    // (measured: the published 0.23.0 threw InvalidArgumentException here).
    // ---------------------------------------------------------------

    public function testUnknownInlineAndRefEnumValuesInAResponseHydrate(): void
    {
        $client = $this->makeClient($this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'workflow_id' => '01936fb2-0000-7000-8000-000000000001',
                    'status' => 'cancelled_by_a_future_rule',          // inline enum on WorkflowCancelResponse
                    'cancelled_at' => '2026-04-29T12:00:00Z',
                    'billing_effect' => 'a_future_billing_effect',     // $ref enum WorkflowCancelBillingEffect
                ],
            ]),
        ]));

        $response = $client->cancelWorkflow('01936fb2-0000-7000-8000-000000000001');

        self::assertSame('cancelled_by_a_future_rule', $response->getStatus());
        self::assertSame('a_future_billing_effect', $response->getBillingEffect());
        self::assertSame('01936fb2-0000-7000-8000-000000000001', $response->getWorkflowId());
    }

    public function testAPausedDetailWithRequiredActionResumeHydrates(): void
    {
        $client = $this->makeClient($this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'workflow_id' => '01936fb2-0000-7000-8000-000000000001',
                    'status' => 'paused_insufficient_credits',
                    'created_at' => '2026-09-24T08:00:00Z',
                    'updated_at' => '2026-09-24T08:00:00Z',
                    'jobs' => [],
                    'paused_detail' => [
                        'paused_at' => '2026-09-24T08:00:00Z',
                        'expires_at' => '2026-10-01T08:00:00Z',
                        'required_action' => 'resume',   // contracts ruling on api #747; not in this SDK's enum
                        'links' => ['resume' => '/api/workflows/01936fb2-0000-7000-8000-000000000001/resume'],
                    ],
                ],
            ]),
        ]));

        $status = $client->getWorkflowStatus('01936fb2-0000-7000-8000-000000000001');

        self::assertSame('resume', $status->getPausedDetail()?->getRequiredAction());
    }

    public function testAKnownDiscriminatorAndOtherSetterValidationSurviveHydration(): void
    {
        // codex 2e371b9ff15d: the discriminated source model keeps its wire `type`.
        $source = \Gisl\Generated\OpenApi\ObjectSerializer::deserialize(
            (object) ['type' => 'upload', 'file_id' => '01936fb1-7bb3-7000-8000-000000000010'],
            \Gisl\Generated\OpenApi\Model\WorkflowSource::class,
        );
        self::assertSame('upload', $source->getType());

        // codex c35c37c60bff: non-enum setter validation (the UUIDv7 pattern on
        // workflow_id) still runs on hydration.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/workflow_id .*must conform to the pattern/');
        \Gisl\Generated\OpenApi\ObjectSerializer::deserialize(
            (object) ['workflow_id' => 'not-a-uuid', 'status' => 'cancelled', 'cancelled_at' => '2026-04-29T12:00:00Z', 'billing_effect' => 'none'],
            WorkflowCancelResponse::class,
        );
    }

    public function testANumericEnumStaysStrictOnHydration(): void
    {
        // codex ac616ebad85d: only unknown STRING vocabulary is tolerated. A const-
        // pinned numeric enum that also carries a minimum keeps every setter check.
        $this->expectException(\InvalidArgumentException::class);
        \Gisl\Generated\OpenApi\ObjectSerializer::deserialize(
            (object) ['multipart_concurrency_default' => 0],
            \Gisl\Generated\OpenApi\Model\UploadThresholds::class,
        );
    }

    public function testANonStringRefEnumPayloadIsStillRejected(): void
    {
        // codex 9853ff74e58f: tolerance is for unknown STRING vocabulary only.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid value for enum .*WorkflowCancelBillingEffect/');
        \Gisl\Generated\OpenApi\ObjectSerializer::deserialize(123, \Gisl\Generated\OpenApi\Model\WorkflowCancelBillingEffect::class);
    }

    public function testTheRequestSideStillRejectsAnUnknownInlineEnumValue(): void
    {
        // Only DEserialisation is tolerant: a caller building a model still
        // gets the generated validation.
        $this->expectException(\InvalidArgumentException::class);
        (new WorkflowCancelResponse())->setStatus('not_a_status');
    }

    public function testCancelWorkflowHappyPath(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'workflow_id' => '01936fb2-0000-7000-8000-000000000001',
                    'status' => 'cancelled',
                    'cancelled_at' => '2026-04-29T12:00:00Z',
                    'billing_effect' => 'unspent_reservation_released',
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $response = $client->cancelWorkflow('01936fb2-0000-7000-8000-000000000001');

        self::assertInstanceOf(WorkflowCancelResponse::class, $response);
        self::assertSame('01936fb2-0000-7000-8000-000000000001', $response->getWorkflowId());
        self::assertSame('cancelled', $response->getStatus());

        self::assertCount(1, $captured);
        self::assertSame('POST', $captured[0]->getMethod());
        self::assertSame(
            '/api/workflows/01936fb2-0000-7000-8000-000000000001/cancel',
            $captured[0]->getUri()->getPath(),
        );
    }

    public function testCancelWorkflowOnTerminalStateThrows409AsApiError(): void
    {
        $http = $this->stubClient([
            $this->jsonResponse(409, [
                'success' => false,
                'error' => 'workflow_not_cancellable',
                'message' => 'Workflow is already completed and cannot be cancelled.',
            ]),
        ]);

        $client = $this->makeClient($http);

        try {
            $client->cancelWorkflow('01936fb2-0000-7000-8000-000000000002');
            self::fail('Expected GislApiError on 409.');
        } catch (GislApiError $e) {
            // Surface as the generic GislApiError, not a special class.
            self::assertSame(GislApiError::class, \get_class($e));
            self::assertSame(409, $e->statusCode);
            self::assertSame('workflow_not_cancellable', $e->errorCode);
        }
    }

    public function testCancelRawurlencodesWorkflowId(): void
    {
        // The URL ID has a `/`; the response body keeps a valid UUID v7
        // because the generated DTO pattern-validates `workflow_id`.
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'workflow_id' => '01936fb2-0000-7000-8000-000000000001',
                    'status' => 'cancelled',
                    'cancelled_at' => '2026-04-29T12:00:00Z',
                    'billing_effect' => 'none',
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $client->cancelWorkflow('a/b');

        self::assertSame(
            '/api/workflows/a%2Fb/cancel',
            $captured[0]->getUri()->getPath(),
        );
    }

    // ---------------------------------------------------------------
    // resumeWorkflow
    // ---------------------------------------------------------------

    public function testResumeWorkflowHappyPath(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'workflow_id' => '01936fb2-0000-7000-8000-000000000003',
                    'status' => 'in_progress',
                    'resumed_at' => '2026-04-29T12:01:00Z',
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $response = $client->resumeWorkflow('01936fb2-0000-7000-8000-000000000003');

        self::assertInstanceOf(WorkflowResumeResponse::class, $response);
        self::assertSame('01936fb2-0000-7000-8000-000000000003', $response->getWorkflowId());
        self::assertSame('in_progress', $response->getStatus());

        self::assertSame('POST', $captured[0]->getMethod());
        self::assertSame(
            '/api/workflows/01936fb2-0000-7000-8000-000000000003/resume',
            $captured[0]->getUri()->getPath(),
        );
    }

    public function testResumeWorkflowRawurlencodesWorkflowId(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'workflow_id' => '01936fb2-0000-7000-8000-000000000003',
                    'status' => 'in_progress',
                    'resumed_at' => '2026-04-29T12:01:00Z',
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $client->resumeWorkflow('a/b');

        self::assertSame(
            '/api/workflows/a%2Fb/resume',
            $captured[0]->getUri()->getPath(),
        );
    }

    // ---------------------------------------------------------------
    // retryOperation
    // ---------------------------------------------------------------

    public function testRetryOperationHappyPath(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'operation_id' => '01936fb2-0000-7000-8000-000000000005',
                    'original_operation_id' => '01936fb2-0000-7000-8000-000000000004',
                    'status' => 'pending',
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $response = $client->retryOperation('01936fb2-0000-7000-8000-000000000004');

        self::assertInstanceOf(RetryResponse::class, $response);
        self::assertSame('01936fb2-0000-7000-8000-000000000005', $response->getOperationId());
        self::assertSame('01936fb2-0000-7000-8000-000000000004', $response->getOriginalOperationId());

        self::assertSame('POST', $captured[0]->getMethod());
        // Per-operation, NOT per-workflow.
        self::assertSame(
            '/api/operations/01936fb2-0000-7000-8000-000000000004/retry',
            $captured[0]->getUri()->getPath(),
        );
    }

    public function testRetryOperationRawurlencodesOperationId(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'operation_id' => '01936fb2-0000-7000-8000-000000000007',
                    'original_operation_id' => '01936fb2-0000-7000-8000-000000000006',
                    'status' => 'pending',
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $client->retryOperation('a/b');

        self::assertSame(
            '/api/operations/a%2Fb/retry',
            $captured[0]->getUri()->getPath(),
        );
    }

    // ---------------------------------------------------------------
    // getMetadata
    // ---------------------------------------------------------------

    public function testGetMetadataHappyPath(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'file_id' => '01936fb2-0000-7000-8000-000000000008',
                    'original_name' => 'photo.jpg',
                    'mime_type' => 'image/jpeg',
                    'size_bytes' => 12345,
                    'created_at' => '2026-04-29T12:00:00Z',
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $response = $client->getMetadata('01936fb2-0000-7000-8000-000000000008');

        self::assertInstanceOf(MetadataResponse::class, $response);
        self::assertSame('01936fb2-0000-7000-8000-000000000008', $response->getFileId());
        self::assertSame('image/jpeg', $response->getMimeType());

        self::assertSame('GET', $captured[0]->getMethod());
        self::assertSame(
            '/api/uploads/01936fb2-0000-7000-8000-000000000008/metadata',
            $captured[0]->getUri()->getPath(),
        );
    }

    public function testGetMetadataRawurlencodesFileId(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'file_id' => '01936fb2-0000-7000-8000-000000000009',
                    'original_name' => 'photo.jpg',
                    'mime_type' => 'image/jpeg',
                    'size_bytes' => 12345,
                    'created_at' => '2026-04-29T12:00:00Z',
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $client->getMetadata('a/b');

        self::assertSame(
            '/api/uploads/a%2Fb/metadata',
            $captured[0]->getUri()->getPath(),
        );
    }
}
