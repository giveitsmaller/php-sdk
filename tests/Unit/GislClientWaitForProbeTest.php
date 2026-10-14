<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Cancellation;
use Gisl\Sdk\Errors\GislAbortError;
use Gisl\Sdk\Errors\GislApiError;
use Gisl\Sdk\Errors\GislLongFormConcurrencyError;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\Http\Sleeper;
use Gisl\Sdk\ProbeWaitOptions;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Unit coverage for {@see GislClient::waitForProbe()} (YOA6FpFr) — the bounded,
 * never-bounce upload-probe poll. Mirrors the TS `wait-for-probe.test.ts`.
 *
 * Wire contract (API-verified): 422 feature_not_available = not landed → poll;
 * any 200 = stop (any probe_status); 5xx = retry-then-give-up; timeout =
 * give-up. Never throws on those paths. Genuine errors (404) + cancellation throw.
 */
#[CoversClass(GislClient::class)]
final class GislClientWaitForProbeTest extends TestCase
{
    private const FID = '019539ab-1111-7000-8000-000000000001';

    private HttpFactory $factory;

    /** The fake monotonic clock {@see useFakeClock()} installs, in ns. */
    private int $nowNs = 0;

    /** @var list<int> Every primitive sleep the fake clock was asked for, in µs. */
    private array $sleptUs = [];

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    protected function tearDown(): void
    {
        Sleeper::setForTesting(null, null);
    }

    /**
     * Replace ONLY Sleeper's two primitives: time stands still except when the
     * loop sleeps (or a request's `$onRequest` advances it), so the production
     * deadline, clamp and slice logic run on exact numbers. `$onSlice` runs after
     * each slice, e.g. to cancel mid-wait.
     */
    private function useFakeClock(?\Closure $onSlice = null): void
    {
        $this->nowNs = 1_000_000_000_000;
        $this->sleptUs = [];
        Sleeper::setForTesting(
            fn (): int => $this->nowNs,
            function (int $us) use ($onSlice): void {
                $this->sleptUs[] = $us;
                $this->nowNs += $us * 1_000;
                if ($onSlice !== null) {
                    $onSlice();
                }
            },
        );
    }

    /**
     * @param list<ResponseInterface|\Throwable> $queue
     * @param (\Closure(): void)|null           $onRequest runs before every response
     */
    private function makeClient(array $queue, ?\Closure $onRequest = null, ?int &$requests = null): GislClient
    {
        $requests = 0;
        $http = new class ($queue, $onRequest, $requests) implements ClientInterface {
            /** @var list<ResponseInterface|\Throwable> */
            private array $queue;
            private int $requests;

            /** @param list<ResponseInterface|\Throwable> $queue */
            public function __construct(array $queue, private readonly ?\Closure $onRequest, int &$requests)
            {
                $this->queue = $queue;
                $this->requests = &$requests;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                ++$this->requests;
                if ($this->onRequest !== null) {
                    ($this->onRequest)();
                }
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

    private function probeOk(string $probeStatus = 'ok'): ResponseInterface
    {
        $body = \json_encode([
            'success' => true,
            'data' => [
                'file_id' => self::FID,
                'probe_status' => $probeStatus,
                'media_metadata' => ['duration_seconds' => 600, 'codec' => 'h264', 'container' => 'mp4', 'probed_at' => '2026-06-16T10:00:00Z'],
                'processing_class_pre_assignment' => 'long_form',
            ],
        ], JSON_THROW_ON_ERROR);
        return new Response(200, ['Content-Type' => 'application/json'], $body);
    }

    /** @param array<string, string> $extraHeaders */
    private function notLanded(array $extraHeaders = []): ResponseInterface
    {
        $body = \json_encode([
            'success' => false,
            'error' => 'no probe result cached for this upload',
            'error_type' => 'feature_not_available',
            'violations' => [['feature' => 'upload.probe', 'availability' => 'planned']],
        ], JSON_THROW_ON_ERROR);
        return new Response(422, ['Content-Type' => 'application/json'] + $extraHeaders, $body);
    }

    /**
     * The probe 422's other two arms (contracts tRVvMOvy): a bare ErrorEnvelope + `error_type`.
     *
     * @param array<string, string> $extraHeaders
     */
    private function probe422(string $errorType, array $extraHeaders = []): ResponseInterface
    {
        $body = \json_encode([
            'success' => false,
            'error' => 'UNPROCESSABLE_ENTITY',
            'message' => 'no probe result',
            'error_type' => $errorType,
        ], JSON_THROW_ON_ERROR);
        return new Response(422, ['Content-Type' => 'application/json'] + $extraHeaders, $body);
    }

    private function proberCrash(): ResponseInterface
    {
        $body = \json_encode(['success' => false, 'error' => 'please retry', 'error_type' => 'internal_error'], JSON_THROW_ON_ERROR);
        return new Response(500, ['Content-Type' => 'application/json'], $body);
    }

    private function uploadNotFound(): ResponseInterface
    {
        $body = \json_encode(['success' => false, 'error' => 'upload not found', 'error_type' => 'upload_not_found'], JSON_THROW_ON_ERROR);
        return new Response(404, ['Content-Type' => 'application/json'], $body);
    }

    public function test_polls_past_422_then_stops_on_first_200(): void
    {
        $client = $this->makeClient([$this->notLanded(), $this->notLanded(), $this->probeOk()]);
        /** @var list<array{attempt:int, elapsedMs:int}> $polls */
        $polls = [];
        $result = $client->waitForProbe(self::FID, new ProbeWaitOptions(
            timeoutMs: 5000,
            onPoll: function (array $info) use (&$polls): void {
                $polls[] = $info;
            },
        ));

        self::assertTrue($result->landed);
        self::assertNotNull($result->probe);
        self::assertSame('ok', $result->probe->getProbeStatus());
        self::assertNull($result->reason);
        self::assertSame([1, 2, 3], \array_column($polls, 'attempt'));
    }

    public function test_stops_on_any_200_without_interpreting_probe_status(): void
    {
        $client = $this->makeClient([$this->probeOk('corrupt')]);
        $result = $client->waitForProbe(self::FID, new ProbeWaitOptions(timeoutMs: 5000));
        self::assertTrue($result->landed);
        self::assertNotNull($result->probe);
        self::assertSame('corrupt', $result->probe->getProbeStatus());
    }

    public function test_gives_up_with_timeout_when_probe_never_lands(): void
    {
        // Plenty of 422s queued; the tiny timeout trips give-up first. Never throws.
        $client = $this->makeClient(\array_fill(0, 50, $this->notLanded()));
        $result = $client->waitForProbe(self::FID, new ProbeWaitOptions(timeoutMs: 20));
        self::assertFalse($result->landed);
        self::assertSame('timeout', $result->reason);
        self::assertNull($result->probe);
    }

    public function test_retries_5xx_then_gives_up_with_prober_error(): void
    {
        $client = $this->makeClient([$this->proberCrash(), $this->proberCrash(), $this->proberCrash()]);
        $result = $client->waitForProbe(self::FID, new ProbeWaitOptions(timeoutMs: 5000));
        self::assertFalse($result->landed);
        self::assertSame('prober_error', $result->reason);
    }

    public function test_5xx_then_200_still_lands(): void
    {
        $client = $this->makeClient([$this->proberCrash(), $this->probeOk()]);
        $result = $client->waitForProbe(self::FID, new ProbeWaitOptions(timeoutMs: 5000));
        self::assertTrue($result->landed);
    }

    public function test_gives_up_on_repeated_transport_failures(): void
    {
        // A PSR-18 transport failure (ClientExceptionInterface) normalises to
        // GislNetworkError inside the client → transient → retry-then-give-up.
        $boom = static fn (): \Throwable => new class ('network down') extends \RuntimeException implements ClientExceptionInterface {
        };
        $client = $this->makeClient([$boom(), $boom(), $boom()]);
        $result = $client->waitForProbe(self::FID, new ProbeWaitOptions(timeoutMs: 5000));
        self::assertFalse($result->landed);
        self::assertSame('prober_error', $result->reason);
    }

    public function test_transport_failure_then_200_still_lands(): void
    {
        $boom = new class ('blip') extends \RuntimeException implements ClientExceptionInterface {
        };
        $client = $this->makeClient([$boom, $this->probeOk()]);
        $result = $client->waitForProbe(self::FID, new ProbeWaitOptions(timeoutMs: 5000));
        self::assertTrue($result->landed);
    }

    public function test_propagates_genuine_404_does_not_swallow(): void
    {
        $client = $this->makeClient([$this->uploadNotFound()]);
        $this->expectException(GislApiError::class);
        $client->waitForProbe(self::FID, new ProbeWaitOptions(timeoutMs: 5000));
    }

    public function test_honours_retry_after_but_clamps_to_remaining_budget(): void
    {
        // Retry-After 3600s but timeoutMs 30ms → must clamp + give up fast.
        $client = $this->makeClient(\array_fill(0, 5, $this->notLanded(['Retry-After' => '3600'])));
        $start = \microtime(true);
        $result = $client->waitForProbe(self::FID, new ProbeWaitOptions(timeoutMs: 30));
        self::assertFalse($result->landed);
        self::assertSame('timeout', $result->reason);
        self::assertLessThan(2.0, \microtime(true) - $start); // nowhere near 3600s
    }

    public function test_throws_abort_when_cancelled_before_start(): void
    {
        $cancellation = new Cancellation();
        $cancellation->cancel();
        $client = $this->makeClient([$this->probeOk()]);
        $this->expectException(GislAbortError::class);
        $client->waitForProbe(self::FID, new ProbeWaitOptions(cancellation: $cancellation));
    }

    public function test_throws_abort_when_cancelled_mid_wait(): void
    {
        $cancellation = new Cancellation();
        $client = $this->makeClient(\array_fill(0, 50, $this->notLanded()));
        $this->expectException(GislAbortError::class);
        // Cancel during the first poll's onPoll → the next loop-top check throws.
        $client->waitForProbe(self::FID, new ProbeWaitOptions(
            timeoutMs: 5000,
            onPoll: function (array $info) use ($cancellation): void {
                if ($info['attempt'] === 1) {
                    $cancellation->cancel();
                }
            },
            cancellation: $cancellation,
        ));
    }

    // --- the probe 422 oneOf (8L4JJMx6, contracts tRVvMOvy) -------------------

    public function test_probe_not_ready_keeps_polling_and_lands(): void
    {
        $client = $this->makeClient([
            $this->probe422('probe_not_ready'),
            $this->probe422('probe_not_ready'),
            $this->probeOk(),
        ]);
        $attempts = [];
        $result = $client->waitForProbe(self::FID, new ProbeWaitOptions(
            timeoutMs: 5000,
            onPoll: function (array $info) use (&$attempts): void {
                $attempts[] = $info['attempt'];
            },
        ));
        self::assertTrue($result->landed);
        self::assertSame([1, 2, 3], $attempts);
    }

    public function test_probe_not_ready_honours_retry_after(): void
    {
        // Retry-After: 1 → the second poll waits ~1 s. The jitter backoff on
        // attempt 1 is at most 250 ms, so >= 950 ms can only be the header.
        $client = $this->makeClient([
            $this->probe422('probe_not_ready', ['Retry-After' => '1']),
            $this->probeOk(),
        ]);
        $start = \hrtime(true);
        $result = $client->waitForProbe(self::FID, new ProbeWaitOptions(timeoutMs: 5000));
        self::assertTrue($result->landed);
        self::assertGreaterThanOrEqual(950, (\hrtime(true) - $start) / 1_000_000);
    }

    public function test_a_retry_after_that_runs_to_the_deadline_makes_no_further_probe_call(): void
    {
        // EgK0XJg7: the Retry-After (3600 s) is clamped to the remaining budget,
        // so the wait ends exactly at the deadline and nothing may be sent after
        // it. A 200 is queued second: an edge probe would LAND instead of timing
        // out. The edge is sub-millisecond and set by how long the first probe
        // took, so one run cannot hit it on demand; 30 short runs make the old
        // ms-floored sleep fail near-certainly, and the fixed code never does.
        for ($run = 1; $run <= 30; ++$run) {
            $client = $this->makeClient([
                $this->probe422('probe_not_ready', ['Retry-After' => '3600']),
                $this->probeOk(),
            ]);
            $attempts = 0;
            $result = $client->waitForProbe(self::FID, new ProbeWaitOptions(
                timeoutMs: 5,
                onPoll: function () use (&$attempts): void {
                    ++$attempts;
                },
            ));
            self::assertSame(1, $attempts, "run {$run}: a probe was sent at the deadline edge");
            self::assertFalse($result->landed);
            self::assertSame('timeout', $result->reason);
        }
    }

    public function test_probe_not_ready_never_answering_gives_up_with_timeout(): void
    {
        $client = $this->makeClient(\array_fill(0, 50, $this->probe422('probe_not_ready')));
        $result = $client->waitForProbe(self::FID, new ProbeWaitOptions(timeoutMs: 20));
        self::assertFalse($result->landed);
        self::assertSame('timeout', $result->reason);
    }

    public function test_probe_not_applicable_is_terminal_after_one_request(): void
    {
        // A 200 queued behind it proves no second poll is made.
        $client = $this->makeClient([$this->probe422('probe_not_applicable'), $this->probeOk()]);
        $attempts = [];
        $result = $client->waitForProbe(self::FID, new ProbeWaitOptions(
            timeoutMs: 5000,
            onPoll: function (array $info) use (&$attempts): void {
                $attempts[] = $info['attempt'];
            },
        ));
        self::assertFalse($result->landed);
        self::assertSame('not_applicable', $result->reason);
        self::assertNull($result->probe);
        self::assertSame([1], $attempts);
    }

    public function test_probe_not_applicable_after_not_ready_polls_ignores_a_stray_retry_after(): void
    {
        $client = $this->makeClient([
            $this->probe422('probe_not_ready'),
            $this->probe422('probe_not_applicable', ['Retry-After' => '3600']),
        ]);
        $start = \microtime(true);
        $result = $client->waitForProbe(self::FID, new ProbeWaitOptions(timeoutMs: 5000));
        self::assertSame('not_applicable', $result->reason);
        self::assertLessThan(2.0, \microtime(true) - $start);
    }

    public function test_feature_not_available_transitional_retries_until_timeout_ms(): void
    {
        $attempts = 0;
        $client = $this->makeClient(\array_fill(0, 200, $this->notLanded()));
        $start = \microtime(true);
        $result = $client->waitForProbe(self::FID, new ProbeWaitOptions(
            // 1.5 s: the attempt-1 jitter backoff is at most 250 ms, so a second
            // poll always fits (a 120 ms budget let one long jitter sleep consume it).
            timeoutMs: 1500,
            onPoll: function () use (&$attempts): void {
                ++$attempts;
            },
        ));
        self::assertFalse($result->landed);
        self::assertSame('timeout', $result->reason);
        // More than one poll: it RETRIED, it did not treat the arm as terminal.
        self::assertGreaterThan(1, $attempts);
        self::assertGreaterThanOrEqual(1.4, \microtime(true) - $start);
    }

    public function test_any_other_422_error_type_still_propagates(): void
    {
        $client = $this->makeClient([$this->probe422('something_else'), $this->probeOk()]);
        $this->expectException(GislApiError::class);
        $client->waitForProbe(self::FID, new ProbeWaitOptions(timeoutMs: 5000));
    }

    public function test_a_non_422_carrying_probe_not_applicable_is_not_the_terminal_arm(): void
    {
        $body = \json_encode(['success' => false, 'error' => 'BAD_REQUEST', 'error_type' => 'probe_not_applicable'], JSON_THROW_ON_ERROR);
        $client = $this->makeClient([new Response(400, ['Content-Type' => 'application/json'], $body)]);
        $this->expectException(GislApiError::class);
        $client->waitForProbe(self::FID, new ProbeWaitOptions(timeoutMs: 5000));
    }

    public function test_probe_upload_surfaces_the_new_arms_as_a_base_api_error(): void
    {
        $client = $this->makeClient([$this->probe422('probe_not_applicable')]);
        try {
            $client->probeUpload(self::FID);
            self::fail('expected a GislApiError');
        } catch (GislApiError $e) {
            self::assertSame(GislApiError::class, $e::class);
            self::assertSame(422, $e->statusCode);
            self::assertSame('probe_not_applicable', $e->payload['error_type'] ?? null);
            self::assertFalse($e->retryable());
        }
    }

    // --- patient polling (4v7Vwvcw, mirrors TS Hal5c41E) ----------------------
    // A signed-in probe_pending recovery may wait up to 900 s; at the default
    // 250 ms jitter cadence a Free account's 10/min probe bucket is spent in the
    // first seconds. `patient` spaces polls and waits a probe 429 out. Every
    // test below runs on the fake Sleeper clock: no real sleeps.

    private function rateLimited(?string $retryAfter = null, string $errorCode = 'TOO_MANY_REQUESTS'): ResponseInterface
    {
        $body = \json_encode(['success' => false, 'error' => $errorCode, 'message' => 'Too many requests'], JSON_THROW_ON_ERROR);
        $headers = ['Content-Type' => 'application/json'];
        if ($retryAfter !== null) {
            $headers['Retry-After'] = $retryAfter;
        }
        return new Response(429, $headers, $body);
    }

    /**
     * Runs one wait, recording each poll's elapsed ms.
     *
     * @param list<ResponseInterface|\Throwable> $queue
     * @return array{0: \Gisl\Sdk\ProbeWaitResult, 1: list<int>}
     */
    private function runPatient(array $queue, int $timeoutMs, bool $patient = true, ?\Closure $onRequest = null): array
    {
        $client = $this->makeClient($queue, $onRequest);
        $elapsed = [];
        $result = $client->waitForProbe(self::FID, new ProbeWaitOptions(
            timeoutMs: $timeoutMs,
            onPoll: function (array $info) use (&$elapsed): void {
                $elapsed[] = $info['elapsedMs'];
            },
            patient: $patient,
        ));
        return [$result, $elapsed];
    }

    public function test_patient_spaces_polls_3s_doubling_to_a_30s_cap_from_each_poll_start(): void
    {
        $this->useFakeClock();
        [$result, $elapsed] = $this->runPatient(\array_fill(0, 50, $this->probe422('probe_not_ready')), 120_000);
        self::assertFalse($result->landed);
        self::assertSame('timeout', $result->reason);
        // Gaps 3, 6, 12, 24, then 30, 30 s.
        self::assertSame([0, 3_000, 9_000, 21_000, 45_000, 75_000, 105_000], $elapsed);
        // The wait ends AT the budget, not a slice past it.
        self::assertSame(1_000_000_000_000 + 120_000 * 1_000_000, $this->nowNs);
    }

    public function test_patient_counts_a_slow_answer_toward_the_gap(): void
    {
        // Each probe request takes 2 s: the gap runs from the poll's START, so the
        // second poll is at 3 s, not 5 s.
        $this->useFakeClock();
        [$result, $elapsed] = $this->runPatient(
            [$this->probe422('probe_not_ready'), $this->probeOk()],
            60_000,
            onRequest: function (): void {
                $this->nowNs += 2_000_000_000;
            },
        );
        self::assertTrue($result->landed);
        self::assertSame([0, 3_000], $elapsed);
    }

    public function test_patient_stays_inside_the_10_per_minute_probe_bucket_over_900s(): void
    {
        $this->useFakeClock();
        [$result, $elapsed] = $this->runPatient(\array_fill(0, 200, $this->probe422('probe_not_ready')), 900_000);
        self::assertSame('timeout', $result->reason);
        foreach ($elapsed as $from) {
            $inWindow = \array_filter($elapsed, static fn (int $t): bool => $t >= $from && $t < $from + 60_000);
            self::assertLessThanOrEqual(10, \count($inWindow), "window from {$from} ms");
        }
        // ...and inside the 100/h bucket in total.
        self::assertLessThanOrEqual(100, \count($elapsed));
        // Every sleep was sliced to at most 1 s.
        self::assertLessThanOrEqual(1_000_000, \max($this->sleptUs));
    }

    public function test_patient_a_retry_after_longer_than_the_backoff_sets_the_gap(): void
    {
        $this->useFakeClock();
        [$result, $elapsed] = $this->runPatient([$this->probe422('probe_not_ready', ['Retry-After' => '10']), $this->probeOk()], 60_000);
        self::assertTrue($result->landed);
        self::assertSame([0, 10_000], $elapsed);
    }

    public function test_patient_a_retry_after_shorter_than_the_backoff_does_not_shorten_the_gap(): void
    {
        $this->useFakeClock();
        [$result, $elapsed] = $this->runPatient([$this->probe422('probe_not_ready', ['Retry-After' => '1']), $this->probeOk()], 60_000);
        self::assertTrue($result->landed);
        self::assertSame([0, 3_000], $elapsed);
    }

    public function test_patient_waits_a_probe_429_out_for_its_retry_after_then_lands(): void
    {
        $this->useFakeClock();
        [$result, $elapsed] = $this->runPatient([$this->rateLimited('20'), $this->probeOk()], 60_000);
        self::assertTrue($result->landed);
        self::assertSame([0, 20_000], $elapsed);
    }

    public function test_patient_waits_a_probe_429_with_no_retry_after_out_with_the_patient_backoff(): void
    {
        $this->useFakeClock();
        [$result, $elapsed] = $this->runPatient([$this->rateLimited(), $this->probeOk()], 60_000);
        self::assertTrue($result->landed);
        self::assertSame([0, 3_000], $elapsed);
    }

    public function test_patient_clamps_a_probe_429_wait_to_the_budget_and_makes_no_second_poll(): void
    {
        $this->useFakeClock();
        $start = $this->nowNs;
        // A 200 queued second: a poll at the edge would LAND instead of timing out.
        [$result, $elapsed] = $this->runPatient([$this->rateLimited('3600'), $this->probeOk()], 10_000);
        self::assertFalse($result->landed);
        self::assertSame('timeout', $result->reason);
        self::assertSame([0], $elapsed);
        self::assertSame(10_000 * 1_000_000, $this->nowNs - $start);
    }

    public function test_patient_cancel_during_a_long_retry_after_is_honoured_within_one_slice_and_does_not_re_poll(): void
    {
        $cancellation = new Cancellation();
        $slices = 0;
        $this->useFakeClock(function () use (&$slices, $cancellation): void {
            if (++$slices === 3) {
                $cancellation->cancel();
            }
        });
        $start = $this->nowNs;
        $client = $this->makeClient([$this->rateLimited('600'), $this->probeOk()], null, $requests);
        try {
            $client->waitForProbe(self::FID, new ProbeWaitOptions(timeoutMs: 900_000, cancellation: $cancellation, patient: true));
            self::fail('expected GislAbortError');
        } catch (GislAbortError) {
            // Cancelled during the 3rd 1 s slice; noticed before a 4th.
            self::assertSame(3, $slices);
            self::assertSame(3_000 * 1_000_000, $this->nowNs - $start);
            self::assertSame(1, $requests, 'the probe was not re-polled');
        }
    }

    public function test_without_patient_a_probe_429_still_propagates_as_a_base_api_error(): void
    {
        $this->useFakeClock();
        $client = $this->makeClient([$this->rateLimited('5'), $this->probeOk()], null, $requests);
        try {
            $client->waitForProbe(self::FID, new ProbeWaitOptions(timeoutMs: 60_000));
            self::fail('expected the 429');
        } catch (GislApiError $e) {
            self::assertSame(GislApiError::class, $e::class);
            self::assertSame(429, $e->statusCode);
            self::assertSame('TOO_MANY_REQUESTS', $e->errorCode);
            self::assertSame('5', $e->responseHeaders['retry-after'] ?? null);
            self::assertSame(1, $requests);
        }
    }

    public function test_without_patient_the_cadence_is_the_sub_second_jitter_backoff(): void
    {
        $this->useFakeClock();
        [$result, $elapsed] = $this->runPatient([$this->probe422('probe_not_ready'), $this->probeOk()], 60_000, patient: false);
        self::assertTrue($result->landed);
        self::assertCount(2, $elapsed);
        // The attempt-1 full-jitter backoff is at most 250 ms; a patient wait would be 3 s.
        self::assertLessThanOrEqual(250, $elapsed[1]);
    }

    public function test_without_patient_every_time_read_is_the_sleeper_clock(): void
    {
        // A 3600 s Retry-After clamped to a 30 s budget: on the fake clock the
        // wait ends at exactly 30 s, with no real sleep, so the deadline, the
        // remainder AND the sleep all went through Sleeper (F2, K3).
        $this->useFakeClock();
        $start = $this->nowNs;
        $realStart = \hrtime(true);
        [$result, $elapsed] = $this->runPatient([$this->probe422('probe_not_ready', ['Retry-After' => '3600']), $this->probeOk()], 30_000, patient: false);
        self::assertSame('timeout', $result->reason);
        self::assertSame([0], $elapsed);
        self::assertSame(30_000 * 1_000_000, $this->nowNs - $start);
        self::assertLessThan(1_000, (\hrtime(true) - $realStart) / 1_000_000, 'no real sleep');
        self::assertCount(30, $this->sleptUs, 'sliced to 1 s');
    }

    public function test_patient_still_propagates_the_long_form_concurrency_429(): void
    {
        $this->useFakeClock();
        $client = $this->makeClient([$this->rateLimited('5', 'LONG_FORM_CONCURRENCY_LIMIT_EXCEEDED'), $this->probeOk()]);
        $this->expectException(GislLongFormConcurrencyError::class);
        $client->waitForProbe(self::FID, new ProbeWaitOptions(timeoutMs: 60_000, patient: true));
    }

    public function test_patient_still_gives_up_on_repeated_5xx_and_still_propagates_a_404(): void
    {
        $this->useFakeClock();
        [$result] = $this->runPatient([$this->proberCrash(), $this->proberCrash(), $this->proberCrash()], 900_000);
        self::assertSame('prober_error', $result->reason);

        $client = $this->makeClient([$this->uploadNotFound()]);
        $this->expectException(GislApiError::class);
        $client->waitForProbe(self::FID, new ProbeWaitOptions(timeoutMs: 900_000, patient: true));
    }
}
