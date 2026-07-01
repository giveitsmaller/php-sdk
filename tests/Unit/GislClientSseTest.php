<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Errors\GislAuthError;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislSseEvent;
use Gisl\Sdk\GislSseParseFailure;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Unit coverage for ticket B2.2 (`DI4x9bjG`) — PHP SDK SSE consumer.
 * Mirrors the TS reference parser at `packages/typescript/src/sse.ts`
 * + the `streamEvents()` entry point at
 * `packages/typescript/src/client.ts:1104`.
 *
 * The parser surface MUST:
 *   - yield one {@see GislSseEvent} per frame on blank-line boundaries
 *   - join multiple `data:` lines per frame with `\n` before JSON-decode
 *   - skip `:` comment lines (keep-alives)
 *   - IGNORE `id:` and `retry:` fields (NOT surface them on the event)
 *   - drop malformed-JSON frames silently rather than throw mid-stream
 *   - rawurlencode workflowId path segments
 *   - route non-2xx responses through the typed-error dispatch
 */
#[CoversClass(GislClient::class)]
final class GislClientSseTest extends TestCase
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

    private function sseResponse(string $body): ResponseInterface
    {
        // Wrap the body in a PSR-7 stream backed by an in-memory string —
        // Guzzle's Utils::streamFor exposes the same read()/eof() surface
        // that a real network response would, including empty-string
        // returns at EOF.
        return new Response(
            200,
            ['Content-Type' => 'text/event-stream'],
            Utils::streamFor($body),
        );
    }

    private function makeClient(ClientInterface $http, string $baseUrl = 'https://api.example.com'): GislClient
    {
        return new GislClient(
            config: new GislClientConfig(baseUrl: $baseUrl, apiKey: 'sk_test'),
            httpClient: $http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    private const HARNESS_WORKFLOW_ID = '01936fb2-0000-7000-8000-0000000000ff';

    /**
     * @return list<GislSseEvent>
     */
    private function collect(\Generator $stream, ?int $stopAfter = null): array
    {
        /** @var list<GislSseEvent> $events */
        $events = [];
        foreach ($stream as $event) {
            $events[] = $event;
            if ($stopAfter !== null && \count($events) >= $stopAfter) {
                break;
            }
        }
        return $events;
    }

    public function testHappyPathYieldsTypedEvents(): void
    {
        $body = "event: progress\ndata: {\"percent\":50}\n\n"
              . "event: complete\ndata: {\"output\":\"x\"}\n\n";
        $http = $this->stubClient([$this->sseResponse($body)]);
        $client = $this->makeClient($http);

        $events = $this->collect($client->streamEvents(self::HARNESS_WORKFLOW_ID));

        self::assertCount(2, $events);
        self::assertInstanceOf(GislSseEvent::class, $events[0]);
        self::assertSame('progress', $events[0]->event);
        self::assertSame(['percent' => 50], $events[0]->data);
        self::assertSame('complete', $events[1]->event);
        self::assertSame(['output' => 'x'], $events[1]->data);
    }

    public function testStreamEventsSendsCapabilityHeaderWhenProvided(): void
    {
        // Anonymous-read capability: a session-less caller passes the `cap`
        // from the anonymous workflow-create response so the server authorizes
        // the SSE read. Sent as the X-Workflow-Capability header.
        $body = "event: progress\ndata: {\"percent\":50}\n\n";
        $captured = [];
        $http = $this->stubClient([$this->sseResponse($body)], $captured);
        $client = $this->makeClient($http);

        $this->collect($client->streamEvents(self::HARNESS_WORKFLOW_ID, 'wcap_sse'));

        self::assertCount(1, $captured);
        self::assertSame('wcap_sse', $captured[0]->getHeaderLine('X-Workflow-Capability'));
    }

    public function testStreamEventsOmitsCapabilityHeaderWhenAbsent(): void
    {
        $body = "event: progress\ndata: {\"percent\":50}\n\n";
        $captured = [];
        $http = $this->stubClient([$this->sseResponse($body)], $captured);
        $client = $this->makeClient($http);

        $this->collect($client->streamEvents(self::HARNESS_WORKFLOW_ID));

        self::assertCount(1, $captured);
        self::assertFalse($captured[0]->hasHeader('X-Workflow-Capability'));
    }

    public function testMultiLineDataIsJoinedWithNewline(): void
    {
        // Multi-line JSON object split across three data: lines. Joined
        // with \n produces:
        //   {
        //   "k": "v"
        //   }
        // — valid JSON. Demonstrates the parser concatenates with \n
        // (not space, not no-separator) before JSON-decode.
        $body = "event: x\ndata: {\ndata: \"k\": \"v\"\ndata: }\n\n";
        $http = $this->stubClient([$this->sseResponse($body)]);
        $client = $this->makeClient($http);

        $events = $this->collect($client->streamEvents(self::HARNESS_WORKFLOW_ID));

        self::assertCount(1, $events);
        self::assertSame('x', $events[0]->event);
        self::assertSame(['k' => 'v'], $events[0]->data);
    }

    public function testCommentLinesAreSkipped(): void
    {
        // SSE keep-alives surface as `:` comment lines. They MUST NOT
        // terminate the previous frame nor seed a new one.
        $body = ": keepalive\n"
              . "event: progress\ndata: {\"percent\":10}\n\n"
              . ": another keepalive\n"
              . ": yet another\n"
              . "event: progress\ndata: {\"percent\":20}\n\n";
        $http = $this->stubClient([$this->sseResponse($body)]);
        $client = $this->makeClient($http);

        $events = $this->collect($client->streamEvents(self::HARNESS_WORKFLOW_ID));

        self::assertCount(2, $events);
        self::assertSame(['percent' => 10], $events[0]->data);
        self::assertSame(['percent' => 20], $events[1]->data);
    }

    public function testIdAndRetryFieldsAreIgnored(): void
    {
        // `id:` and `retry:` are explicitly NOT surfaced on
        // GislSseEvent — the SDK does not do Last-Event-ID
        // reconnection nor honour server-suggested retry intervals.
        // This test pins the type contract: the event must have NO
        // `id` and NO `retry` properties (only `event` and `data`).
        $body = "id: 42\nretry: 5000\nevent: progress\ndata: {\"percent\":50}\n\n";
        $http = $this->stubClient([$this->sseResponse($body)]);
        $client = $this->makeClient($http);

        $events = $this->collect($client->streamEvents(self::HARNESS_WORKFLOW_ID));

        self::assertCount(1, $events);
        self::assertSame('progress', $events[0]->event);
        self::assertSame(['percent' => 50], $events[0]->data);

        // GislSseEvent has exactly two readonly props: `event` + `data`.
        // Reflection asserts no `id` / `retry` got smuggled on as
        // dynamic properties (PHP 8.2+ deprecates those, but a future
        // refactor adding them as #[\AllowDynamicProperties] would
        // silently regress without this).
        $reflection = new \ReflectionClass(GislSseEvent::class);
        $propNames = \array_map(
            static fn(\ReflectionProperty $p): string => $p->getName(),
            $reflection->getProperties(),
        );
        \sort($propNames);
        self::assertSame(['data', 'event'], $propNames);
    }

    public function testMalformedJsonFrameIsSkippedNotThrown(): void
    {
        // Long-running SSE consumers MUST stay up across the
        // occasional garbled frame. Parser drops the bad frame and
        // proceeds to the next; the caller never sees an exception.
        $body = "event: bad\ndata: {bad json}\n\n"
              . "event: good\ndata: {\"ok\":true}\n\n";
        $http = $this->stubClient([$this->sseResponse($body)]);
        $client = $this->makeClient($http);

        $events = $this->collect($client->streamEvents(self::HARNESS_WORKFLOW_ID));

        self::assertCount(1, $events);
        self::assertSame('good', $events[0]->event);
        self::assertSame(['ok' => true], $events[0]->data);
    }

    public function testOnParseErrorReceivesTypedDiagnosticAndMalformedFrameIsSkipped(): void
    {
        // TYNjcjpo: a malformed frame between two valid ones is dropped and
        // surfaced to $onParseError as a typed GislSseParseFailure, while the
        // valid frames keep yielding. Mirrors the TS `onParseError` seam.
        $body = "event: good1\ndata: {\"n\":1}\n\n"
              . "event: bad\ndata: {not json}\n\n"
              . "event: good2\ndata: {\"n\":2}\n\n";
        $http = $this->stubClient([$this->sseResponse($body)]);
        $client = $this->makeClient($http);

        /** @var list<GislSseParseFailure> $failures */
        $failures = [];
        $events = $this->collect(
            $client->streamEvents(
                self::HARNESS_WORKFLOW_ID,
                null,
                static function (GislSseParseFailure $failure) use (&$failures): void {
                    $failures[] = $failure;
                },
            ),
        );

        self::assertCount(2, $events);
        self::assertSame('good1', $events[0]->event);
        self::assertSame('good2', $events[1]->event);

        self::assertCount(1, $failures);
        self::assertInstanceOf(GislSseParseFailure::class, $failures[0]);
        self::assertSame('{not json}', $failures[0]->raw);
        self::assertSame('bad', $failures[0]->event);
        self::assertNotSame('', $failures[0]->error);
    }

    public function testOnParseErrorReportsMessageEventWhenFrameHasNoEventField(): void
    {
        // A malformed frame with no `event:` field reports the SSE-spec
        // default event name ("message").
        $body = "data: {still bad}\n\n";
        $http = $this->stubClient([$this->sseResponse($body)]);
        $client = $this->makeClient($http);

        /** @var list<GislSseParseFailure> $failures */
        $failures = [];
        $events = $this->collect(
            $client->streamEvents(
                self::HARNESS_WORKFLOW_ID,
                null,
                static function (GislSseParseFailure $failure) use (&$failures): void {
                    $failures[] = $failure;
                },
            ),
        );

        self::assertCount(0, $events);
        self::assertCount(1, $failures);
        self::assertSame('message', $failures[0]->event);
    }

    public function testOnParseErrorCallbackThrowPropagates(): void
    {
        // A throw from $onParseError is NOT swallowed — it aborts the stream,
        // matching the onProgress-throw convention (a caller that throws is
        // signalling it wants to stop consuming).
        $body = "event: bad\ndata: {nope}\n\n";
        $http = $this->stubClient([$this->sseResponse($body)]);
        $client = $this->makeClient($http);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $this->collect(
            $client->streamEvents(
                self::HARNESS_WORKFLOW_ID,
                null,
                static function (GislSseParseFailure $failure): void {
                    throw new \RuntimeException('boom');
                },
            ),
        );
    }

    public function testEarlyBreakClosesGracefully(): void
    {
        $body = "event: progress\ndata: {\"percent\":10}\n\n"
              . "event: progress\ndata: {\"percent\":20}\n\n"
              . "event: progress\ndata: {\"percent\":30}\n\n";
        $http = $this->stubClient([$this->sseResponse($body)]);
        $client = $this->makeClient($http);

        $stream = $client->streamEvents(self::HARNESS_WORKFLOW_ID);
        $first = null;
        foreach ($stream as $event) {
            $first = $event;
            break;
        }

        self::assertInstanceOf(GislSseEvent::class, $first);
        self::assertSame(['percent' => 10], $first->data);

        // Allow PHP to GC the generator. No exception, no resource
        // leak. Explicitly null the reference to nudge destruction.
        unset($stream);
        $this->addToAssertionCount(1);
    }

    public function testRawurlencodesWorkflowId(): void
    {
        $captured = [];
        $http = $this->stubClient([$this->sseResponse("")], $captured);
        $client = $this->makeClient($http);

        // Workflow ID containing slash + space + question-mark — all
        // must be rawurlencoded so the path segment is unambiguous.
        // Drain the (empty) generator so the request is actually sent.
        \iterator_to_array($client->streamEvents('weird/id with?bits'));

        self::assertCount(1, $captured);
        $url = (string) $captured[0]->getUri();
        self::assertStringContainsString('/api/workflows/weird%2Fid%20with%3Fbits/events', $url);
    }

    public function testNon2xxResponseRoutesThroughUnwrapEnvelope(): void
    {
        // 401 with a recognised auth envelope -> GislAuthError, the
        // same way as JSON endpoints. Pins that SSE shares the
        // typed-error dispatch tree shipped in B2.1.
        $http = $this->stubClient([
            $this->jsonResponse(401, [
                'success' => false,
                'error' => 'invalid_api_key',
                'message' => 'Bad key',
            ]),
        ]);
        $client = $this->makeClient($http);

        $this->expectException(GislAuthError::class);
        // streamEvents is lazy in spirit but the non-2xx branch throws
        // synchronously at call time (before the generator returns).
        $client->streamEvents(self::HARNESS_WORKFLOW_ID);
    }

    public function testCancelledStreamMidwayIsCleanedUp(): void
    {
        // Body has 5 frames; caller breaks after 2. Use a custom
        // PSR-7 stream that counts read() calls so we can assert the
        // remaining 3 frames are NOT pulled off the wire after the
        // foreach exits. Confirms the generator's lazy semantics —
        // chunks beyond the cancellation point are never read.
        $body = "event: e1\ndata: {\"i\":1}\n\n"
              . "event: e2\ndata: {\"i\":2}\n\n"
              . "event: e3\ndata: {\"i\":3}\n\n"
              . "event: e4\ndata: {\"i\":4}\n\n"
              . "event: e5\ndata: {\"i\":5}\n\n";

        $stream = new class ($body) implements StreamInterface {
            private int $position = 0;
            public int $readCalls = 0;

            public function __construct(private readonly string $body)
            {
            }

            public function __toString(): string
            {
                return $this->body;
            }

            public function close(): void
            {
            }

            public function detach()
            {
                return null;
            }

            public function getSize(): ?int
            {
                return \strlen($this->body);
            }

            public function tell(): int
            {
                return $this->position;
            }

            public function eof(): bool
            {
                return $this->position >= \strlen($this->body);
            }

            public function isSeekable(): bool
            {
                return false;
            }

            /**
             * @param int $offset
             * @param int $whence
             */
            public function seek(int $offset, int $whence = SEEK_SET): void
            {
                throw new \RuntimeException('not seekable');
            }

            public function rewind(): void
            {
                throw new \RuntimeException('not seekable');
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function write(string $string): int
            {
                throw new \RuntimeException('not writable');
            }

            public function isReadable(): bool
            {
                return true;
            }

            public function read(int $length): string
            {
                $this->readCalls++;
                if ($this->position >= \strlen($this->body)) {
                    return '';
                }
                // Hand back EXACTLY one frame at a time so the lazy
                // generator gets a chance to break BEFORE we drain
                // everything in a single read.
                $end = \strpos($this->body, "\n\n", $this->position);
                if ($end === false) {
                    $end = \strlen($this->body);
                } else {
                    $end += 2;
                }
                $slice = \substr($this->body, $this->position, $end - $this->position);
                $this->position = $end;
                return $slice;
            }

            public function getContents(): string
            {
                $rest = \substr($this->body, $this->position);
                $this->position = \strlen($this->body);
                return $rest;
            }

            public function getMetadata(?string $key = null)
            {
                return $key === null ? [] : null;
            }
        };

        $response = new Response(
            200,
            ['Content-Type' => 'text/event-stream'],
            $stream,
        );
        $http = $this->stubClient([$response]);
        $client = $this->makeClient($http);

        $collected = [];
        foreach ($client->streamEvents(self::HARNESS_WORKFLOW_ID) as $event) {
            $collected[] = $event;
            if (\count($collected) >= 2) {
                break;
            }
        }
        $readCallsOnBreak = $stream->readCalls;

        self::assertCount(2, $collected);
        self::assertSame('e1', $collected[0]->event);
        self::assertSame('e2', $collected[1]->event);

        // After the break the generator goes out of scope. We assert
        // the read count did NOT advance to consume frames 3-5: a
        // body-draining implementation would have called read() at
        // least 5 times. Two reads (one per yielded frame) is the
        // floor; allow a small slack for buffer-boundary nuances.
        self::assertLessThanOrEqual(
            3,
            $readCallsOnBreak,
            "Generator should not pre-read frames 3-5 after caller breaks; saw {$readCallsOnBreak} reads.",
        );
    }
}
