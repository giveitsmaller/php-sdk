<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Generated\OpenApi\Model\WorkflowStatusResponse;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\WaitOptions;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(GislClient::class)]
final class GislClientWaitForWorkflowTest extends TestCase
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

    private function statusEnvelope(string $status, string $workflowId = '01936fb2-0000-7000-8000-000000000001'): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => [
                'workflow_id' => $workflowId,
                'status' => $status,
                'jobs' => [],
            ],
        ]);
    }

    public function testWaitForWorkflowReturnsImmediatelyOnTerminalStatus(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->statusEnvelope('completed'),
        ], $captured);

        $client = $this->makeClient($http);

        /** @var list<string> $observed */
        $observed = [];
        $start = \hrtime(true);
        $response = $client->waitForWorkflow(
            '01936fb2-0000-7000-8000-000000000001',
            new WaitOptions(
                intervalMs: 1_000,    // would sleep if we polled again
                timeoutMs: 60_000,
                onPoll: function (string $s) use (&$observed): void {
                    $observed[] = $s;
                },
            ),
        );
        $elapsedMs = (\hrtime(true) - $start) / 1_000_000;

        self::assertInstanceOf(WorkflowStatusResponse::class, $response);
        self::assertSame('completed', $response->getStatus());
        self::assertSame(['completed'], $observed);
        // Confirm no sleep happened — completed is terminal so we never
        // entered the usleep branch.
        self::assertLessThan(500.0, $elapsedMs, 'Terminal-first should not sleep.');
        self::assertCount(1, $captured);
    }

    public function testWaitForWorkflowPollsUntilTerminal(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->statusEnvelope('in_progress'),
            $this->statusEnvelope('in_progress'),
            $this->statusEnvelope('completed'),
        ], $captured);

        $client = $this->makeClient($http);

        /** @var list<string> $observed */
        $observed = [];
        $response = $client->waitForWorkflow(
            '01936fb2-0000-7000-8000-000000000001',
            new WaitOptions(
                intervalMs: 0,        // tight loop — keep test fast
                timeoutMs: 5_000,
                onPoll: function (string $s) use (&$observed): void {
                    $observed[] = $s;
                },
            ),
        );

        self::assertSame('completed', $response->getStatus());
        self::assertSame(['in_progress', 'in_progress', 'completed'], $observed);
        self::assertCount(3, $captured);
    }

    public function testWaitForWorkflowThrowsTimeoutErrorOnDeadlineMiss(): void
    {
        // Every response is in_progress; with timeoutMs: 0, even a single
        // nanosecond elapsed after the first status fetch will trip the
        // deadline check (`nowNs + 0 > startNs + 0` is true the moment any
        // time has passed). The implementation uses `hrtime(true)` so this
        // resolves cleanly without any real-time sleeps. We queue a handful
        // of responses just so the test cannot loop forever if the deadline
        // logic ever regresses.
        $http = $this->stubClient([
            $this->statusEnvelope('in_progress'),
            $this->statusEnvelope('in_progress'),
            $this->statusEnvelope('in_progress'),
        ]);

        $client = $this->makeClient($http);

        $this->expectException(GislTimeoutError::class);
        $this->expectExceptionMessage('did not complete within 0ms');

        $client->waitForWorkflow(
            '01936fb2-0000-7000-8000-000000000001',
            new WaitOptions(intervalMs: 0, timeoutMs: 0),
        );
    }

    public function testWaitForWorkflowTimeoutCarriesWorkflowIdForRecovery(): void
    {
        // oYumKo6y: the workflow exists server-side; the timeout carries its id
        // so the caller can poll it to recover the result instead of re-running
        // (a re-run re-uploads and, for authenticated callers, charges again).
        $http = $this->stubClient([
            $this->statusEnvelope('in_progress'),
            $this->statusEnvelope('in_progress'),
        ]);
        $client = $this->makeClient($http);

        try {
            $client->waitForWorkflow(
                '01936fb2-0000-7000-8000-000000000001',
                new WaitOptions(intervalMs: 0, timeoutMs: 0),
            );
            self::fail('expected GislTimeoutError');
        } catch (GislTimeoutError $e) {
            self::assertSame('01936fb2-0000-7000-8000-000000000001', $e->workflowId);
        }
    }

    public function testWaitForWorkflowOnPollFiresWithStatusString(): void
    {
        $http = $this->stubClient([
            $this->statusEnvelope('pending'),
            $this->statusEnvelope('in_progress'),
            $this->statusEnvelope('partially_failed'),
        ]);

        $client = $this->makeClient($http);

        /** @var list<string> $observed */
        $observed = [];
        $client->waitForWorkflow(
            '01936fb2-0000-7000-8000-000000000001',
            new WaitOptions(
                intervalMs: 0,
                timeoutMs: 5_000,
                onPoll: function (string $s) use (&$observed): void {
                    $observed[] = $s;
                },
            ),
        );

        self::assertSame(['pending', 'in_progress', 'partially_failed'], $observed);
    }

    public function testWaitForWorkflowDefaultsAreUsedWhenOptionsNull(): void
    {
        // First-poll completed — confirms passing no options at all just
        // works (defaults are read; we don't assert specific ms values).
        $http = $this->stubClient([
            $this->statusEnvelope('completed'),
        ]);

        $client = $this->makeClient($http);
        $response = $client->waitForWorkflow('01936fb2-0000-7000-8000-000000000001');

        self::assertSame('completed', $response->getStatus());
    }
}
