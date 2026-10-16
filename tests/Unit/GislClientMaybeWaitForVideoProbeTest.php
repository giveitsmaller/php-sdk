<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\Http\Sleeper;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Unit coverage for {@see GislClient::maybeWaitForVideoProbe()} (YOA6FpFr PR2) —
 * the best-effort probe-before-create gate the ergonomic upload→create seams
 * call right before createWorkflow. NO-OP unless
 *   $enabled && $isVideo && $sizeBytes !== null && $sizeBytes > multipartThresholdBytes
 * otherwise it delegates to the never-bounce waitForProbe (which POSTs to
 * /api/uploads/{id}/probe). Mirrors the TS maybe-wait-for-video-probe.test.ts.
 *
 * Default multipart threshold == 10_000_000 bytes, so a sub-10MB upload never
 * probes; a >10MB video upload does.
 */
#[CoversClass(GislClient::class)]
final class GislClientMaybeWaitForVideoProbeTest extends TestCase
{
    private const FID = '019539ab-1111-7000-8000-000000000001';
    private const SMALL = 5_000_000;  // <= 10MB threshold → no probe
    private const LARGE = 20_000_000; // > 10MB threshold → probe

    private HttpFactory $factory;

    /** @var list<RequestInterface> Captured requests for endpoint assertions. */
    private array $captured = [];

    /** The fake monotonic clock {@see useFakeClock()} installs, in ns. */
    private int $nowNs = 0;

    /** @var list<int> Elapsed ms at each probe request, on the fake clock. */
    private array $pollMs = [];

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
        $this->captured = [];
        $this->pollMs = [];
    }

    protected function tearDown(): void
    {
        Sleeper::setForTesting(null, null);
    }

    /**
     * Time stands still except when the wait sleeps, so the patient schedule runs
     * on exact numbers with no real sleeps.
     */
    private function useFakeClock(): void
    {
        $this->nowNs = 1_000_000_000_000;
        Sleeper::setForTesting(
            fn (): int => $this->nowNs,
            function (int $us): void {
                $this->nowNs += $us * 1_000;
            },
        );
    }

    /**
     * @param list<ResponseInterface|\Throwable> $queue
     */
    private function makeClient(array $queue): GislClient
    {
        $captured = &$this->captured;
        $onRequest = function (): void {
            $this->pollMs[] = \intdiv($this->nowNs - 1_000_000_000_000, 1_000_000);
        };
        $http = new class ($queue, $captured, $onRequest) implements ClientInterface {
            /** @var list<ResponseInterface|\Throwable> */
            private array $queue;

            /** @var list<RequestInterface> */
            private array $captured;

            /**
             * @param list<ResponseInterface|\Throwable> $queue
             * @param list<RequestInterface>             $captured
             */
            public function __construct(array $queue, array &$captured, private readonly \Closure $onRequest)
            {
                $this->queue = $queue;
                $this->captured = &$captured;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                ($this->onRequest)();
                $this->captured[] = $request;
                $next = \array_shift($this->queue);
                if ($next === null) {
                    throw new \RuntimeException('Stub PSR-18 client: response queue exhausted');
                }
                if ($next instanceof \Throwable) {
                    throw $next;
                }
                return $next;
            }
        };

        return new GislClient(
            config: new GislClientConfig(baseUrl: 'https://api.example.com', apiKey: 'sk_test'),
            httpClient: $http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    private function probeOk(): ResponseInterface
    {
        $body = \json_encode([
            'success' => true,
            'data' => [
                'file_id' => self::FID,
                'probe_status' => 'ok',
                'media_metadata' => ['duration_seconds' => 600, 'codec' => 'h264', 'container' => 'mp4', 'probed_at' => '2026-06-16T10:00:00Z'],
                'processing_class_pre_assignment' => 'long_form',
            ],
        ], JSON_THROW_ON_ERROR);
        return new Response(200, ['Content-Type' => 'application/json'], $body);
    }

    private function notLanded(): ResponseInterface
    {
        $body = \json_encode([
            'success' => false,
            'error' => 'no probe result cached for this upload',
            'error_type' => 'feature_not_available',
            'violations' => [['feature' => 'upload.probe', 'availability' => 'planned']],
        ], JSON_THROW_ON_ERROR);
        return new Response(422, ['Content-Type' => 'application/json'], $body);
    }

    private function probeWasHit(): bool
    {
        foreach ($this->captured as $request) {
            if (\str_contains((string) $request->getUri(), '/api/uploads/' . self::FID . '/probe')) {
                return true;
            }
        }
        return false;
    }

    // --- no-op short-circuits (never touch the probe endpoint) ---------------

    public function test_disabled_does_not_probe(): void
    {
        $client = $this->makeClient([$this->probeOk()]);
        $client->maybeWaitForVideoProbe(self::FID, enabled: false, isVideo: true, sizeBytes: self::LARGE);
        self::assertSame([], $this->captured);
    }

    public function test_not_video_does_not_probe(): void
    {
        $client = $this->makeClient([$this->probeOk()]);
        $client->maybeWaitForVideoProbe(self::FID, enabled: true, isVideo: false, sizeBytes: self::LARGE);
        self::assertSame([], $this->captured);
    }

    public function test_below_threshold_does_not_probe(): void
    {
        $client = $this->makeClient([$this->probeOk()]);
        $client->maybeWaitForVideoProbe(self::FID, enabled: true, isVideo: true, sizeBytes: self::SMALL);
        self::assertSame([], $this->captured);
    }

    public function test_at_threshold_does_not_probe(): void
    {
        // Boundary is <= threshold → no probe at exactly 10_000_000.
        $client = $this->makeClient([$this->probeOk()]);
        $client->maybeWaitForVideoProbe(self::FID, enabled: true, isVideo: true, sizeBytes: 10_000_000);
        self::assertSame([], $this->captured);
    }

    public function test_null_size_does_not_probe(): void
    {
        $client = $this->makeClient([$this->probeOk()]);
        $client->maybeWaitForVideoProbe(self::FID, enabled: true, isVideo: true, sizeBytes: null);
        self::assertSame([], $this->captured);
    }

    // --- the gate fires: enabled + video + over-threshold --------------------

    public function test_video_multipart_does_probe_and_completes_on_200(): void
    {
        $client = $this->makeClient([$this->probeOk()]);
        $client->maybeWaitForVideoProbe(self::FID, enabled: true, isVideo: true, sizeBytes: self::LARGE);
        self::assertTrue($this->probeWasHit());
        self::assertCount(1, $this->captured);
        self::assertSame('POST', $this->captured[0]->getMethod());
    }

    public function test_video_multipart_polls_past_422_then_completes_a_patient_3s_later(): void
    {
        $this->useFakeClock();
        $client = $this->makeClient([$this->notLanded(), $this->probeOk()]);
        $client->maybeWaitForVideoProbe(self::FID, enabled: true, isVideo: true, sizeBytes: self::LARGE, timeoutMs: 5000);
        self::assertTrue($this->probeWasHit());
        self::assertCount(2, $this->captured);
        self::assertSame([0, 3_000], $this->pollMs);
    }

    public function test_never_bounce_gives_up_silently_on_timeout(): void
    {
        // Plenty of 422s queued; a tiny timeout trips give-up. The method
        // returns void without throwing so the caller creates the workflow anyway.
        $client = $this->makeClient(\array_fill(0, 50, $this->notLanded()));
        $client->maybeWaitForVideoProbe(self::FID, enabled: true, isVideo: true, sizeBytes: self::LARGE, timeoutMs: 20);
        self::assertTrue($this->probeWasHit());
        // No exception is the assertion — assert reaching here.
        $this->addToAssertionCount(1);
    }

    public function test_probe_not_applicable_returns_after_one_request_without_throwing(): void
    {
        // 8L4JJMx6: terminal arm — never-bounce, and no second poll (the 200 stays queued).
        $body = \json_encode([
            'success' => false,
            'error' => 'UNPROCESSABLE_ENTITY',
            'error_type' => 'probe_not_applicable',
        ], JSON_THROW_ON_ERROR);
        $client = $this->makeClient([
            new Response(422, ['Content-Type' => 'application/json'], $body),
            $this->probeOk(),
        ]);
        $client->maybeWaitForVideoProbe(self::FID, enabled: true, isVideo: true, sizeBytes: self::LARGE, timeoutMs: 5000);
        self::assertCount(1, $this->captured);
    }

    // --- patient polling (QDhKs5xv) -------------------------------------------
    // The probe endpoint allows a Free account 10 polls/min. Without `patient`
    // this wait polled ~20/min and a probe 429 failed the run before create.

    public function test_a_probe_that_never_lands_stays_within_10_polls_in_any_60s_window(): void
    {
        $this->useFakeClock();
        $client = $this->makeClient(\array_fill(0, 50, $this->notLanded()));
        $client->maybeWaitForVideoProbe(self::FID, enabled: true, isVideo: true, sizeBytes: self::LARGE, timeoutMs: 120_000);
        // Gaps 3, 6, 12, 24, then 30, 30 s.
        self::assertSame([0, 3_000, 9_000, 21_000, 45_000, 75_000, 105_000], $this->pollMs);
        foreach ($this->pollMs as $from) {
            $inWindow = \array_filter($this->pollMs, static fn (int $t): bool => $t >= $from && $t < $from + 60_000);
            self::assertLessThanOrEqual(10, \count($inWindow), "window from {$from} ms");
        }
    }

    public function test_the_live_not_ready_answer_with_retry_after_3_no_longer_sets_a_3s_cadence(): void
    {
        // The api answers a not-yet-probed upload with probe_not_ready and
        // Retry-After: 3. Honoured as the whole gap, that was 20 polls a minute.
        $this->useFakeClock();
        $body = \json_encode(['success' => false, 'error' => 'UNPROCESSABLE_ENTITY', 'error_type' => 'probe_not_ready'], JSON_THROW_ON_ERROR);
        $client = $this->makeClient(\array_fill(0, 50, new Response(422, ['Content-Type' => 'application/json', 'Retry-After' => '3'], $body)));
        $client->maybeWaitForVideoProbe(self::FID, enabled: true, isVideo: true, sizeBytes: self::LARGE, timeoutMs: 120_000);
        self::assertSame([0, 3_000, 9_000, 21_000, 45_000, 75_000, 105_000], $this->pollMs);
    }

    public function test_a_probe_429_is_waited_out_for_its_retry_after_and_does_not_fail_the_call(): void
    {
        $this->useFakeClock();
        $body = \json_encode(['success' => false, 'error' => 'TOO_MANY_REQUESTS', 'message' => 'Too many requests'], JSON_THROW_ON_ERROR);
        $client = $this->makeClient([
            new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '20'], $body),
            $this->probeOk(),
        ]);
        $client->maybeWaitForVideoProbe(self::FID, enabled: true, isVideo: true, sizeBytes: self::LARGE, timeoutMs: 60_000);
        self::assertSame([0, 20_000], $this->pollMs);
    }
}
