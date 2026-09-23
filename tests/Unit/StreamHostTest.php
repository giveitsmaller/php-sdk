<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Credentials;
use Gisl\Sdk\Environment;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislStreamHostNotDeclaredError;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Second-host routing, proved FROM THE PUBLIC ENTRY POINT (card `VUozk5Bc`).
 *
 * The SSE stream lives on its own host. Everything here drives
 * {@see GislClient} and asserts on the URI that actually reached the PSR-18
 * client — not on the resolver in isolation, which could be perfectly correct
 * while the client still sends the stream to the API host.
 *
 * The load-bearing assertions are the NEGATIVE ones: that a non-stream call
 * does NOT move, and that an undeclared stream host does NOT quietly become
 * the API base URL. A test that only checked "the stream goes to the stream
 * host" would pass just as happily on an implementation that sent EVERYTHING
 * there.
 *
 * Mirrors `packages/typescript/tests/unit/stream-host.test.ts`.
 */
#[CoversClass(GislClient::class)]
#[CoversClass(Credentials::class)]
final class StreamHostTest extends TestCase
{
    private const API_HOST = 'https://api.staging.giveitsmaller.com';
    private const STREAM_HOST = 'https://stream.staging.giveitsmaller.com';
    private const PROD_STREAM_HOST = 'https://stream.giveitsmaller.com';
    private const WORKFLOW_ID = '01936fb2-0000-7000-8000-0000000000ff';

    private HttpFactory $factory;

    /** @var array<string, string|false> Pre-test values of the endpoint env vars. */
    private array $savedEnv = [];

    /** @return list<string> */
    private static function endpointEnvVars(): array
    {
        return [
            Credentials::GISL_STREAM_BASE_URL_ENV,
            Credentials::GISL_ENVIRONMENT_ENV,
            Credentials::GISL_BASE_URL_ENV,
        ];
    }

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
        // SNAPSHOT then clear. The env-var arm of the resolver must not read
        // the developer's shell — but clearing without restoring leaks into
        // whatever runs next in this process, and the last tests here
        // deliberately set GISL_ENVIRONMENT. A test that contaminates its
        // neighbours is a worse instrument than the ambient value it was
        // avoiding.
        $this->savedEnv = [];
        foreach (self::endpointEnvVars() as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::endpointEnvVars() as $name) {
            $original = $this->savedEnv[$name] ?? false;
            if ($original === false) {
                putenv($name);
            } else {
                putenv("{$name}={$original}");
            }
        }
    }

    /**
     * @param list<ResponseInterface> $queue
     * @param-out list<RequestInterface> $captured
     */
    private function stubClient(array $queue, array &$captured = []): ClientInterface
    {
        $captured = [];
        return new class ($queue, $captured) implements ClientInterface {
            /** @var list<ResponseInterface> */
            private array $queue;
            /** @var list<RequestInterface> */
            private array $captured;

            /**
             * @param list<ResponseInterface> $queue
             * @param list<RequestInterface>  $captured
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
                return $next;
            }
        };
    }

    private function sseResponse(string $body = ''): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/event-stream'], Utils::streamFor($body));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(array $data): ResponseInterface
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            \json_encode(['success' => true, 'data' => $data], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param list<ResponseInterface> $queue
     * @param-out list<RequestInterface> $captured
     */
    private function makeClient(
        array $queue,
        ?string $streamBaseUrl,
        array &$captured = [],
        string $baseUrl = self::API_HOST,
    ): GislClient {
        return new GislClient(
            config: new GislClientConfig(
                baseUrl: $baseUrl,
                apiKey: 'sk_test',
                streamBaseUrl: $streamBaseUrl,
            ),
            httpClient: $this->stubClient($queue, $captured),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    /**
     * @param list<RequestInterface> $captured
     * @return list<string>
     */
    private function requestedUris(array $captured): array
    {
        return \array_map(
            static fn (RequestInterface $r): string => (string) $r->getUri(),
            $captured,
        );
    }

    // -----------------------------------------------------------------------
    // The stream moves, and ONLY the stream moves
    // -----------------------------------------------------------------------

    #[Test]
    public function stream_events_goes_to_the_declared_stream_host(): void
    {
        $captured = [];
        $client = $this->makeClient([$this->sseResponse()], self::STREAM_HOST, $captured);

        // The generator is lazy — iterate so the request is actually issued.
        $events = \iterator_to_array($client->streamEvents(self::WORKFLOW_ID));
        self::assertSame([], $events);

        self::assertSame(
            [self::STREAM_HOST . '/api/workflows/' . self::WORKFLOW_ID . '/events'],
            $this->requestedUris($captured),
        );
    }

    #[Test]
    public function every_non_stream_call_stays_on_the_api_host(): void
    {
        $captured = [];
        $client = $this->makeClient(
            [$this->jsonResponse(['workflow_id' => self::WORKFLOW_ID, 'status' => 'completed'])],
            self::STREAM_HOST,
            $captured,
        );

        $client->getWorkflowStatus(self::WORKFLOW_ID);

        // The whole point of a second SLOT rather than a second baseUrl: this
        // call must be untouched. If it moved, a caller overriding the stream
        // would have silently redirected their uploads too.
        $uris = $this->requestedUris($captured);
        self::assertCount(1, $uris);
        self::assertStringStartsWith(self::API_HOST, $uris[0]);
        self::assertStringNotContainsString('stream.', $uris[0]);
    }

    #[Test]
    public function the_stream_and_a_status_call_reach_different_hosts(): void
    {
        $captured = [];
        $client = $this->makeClient(
            [
                $this->sseResponse(),
                $this->jsonResponse(['workflow_id' => self::WORKFLOW_ID, 'status' => 'completed']),
            ],
            self::STREAM_HOST,
            $captured,
        );

        \iterator_to_array($client->streamEvents(self::WORKFLOW_ID));
        $client->getWorkflowStatus(self::WORKFLOW_ID);

        self::assertCount(2, $captured);
        self::assertSame('stream.staging.giveitsmaller.com', $captured[0]->getUri()->getHost());
        self::assertSame('api.staging.giveitsmaller.com', $captured[1]->getUri()->getHost());
    }

    // -----------------------------------------------------------------------
    // Fail closed — the control this card exists for
    // -----------------------------------------------------------------------

    #[Test]
    public function throws_instead_of_falling_back_to_base_url_when_no_stream_host_is_declared(): void
    {
        $captured = [];
        $client = $this->makeClient([], null, $captured);

        $this->expectException(GislStreamHostNotDeclaredError::class);
        try {
            $client->streamEvents(self::WORKFLOW_ID);
        } finally {
            // No I/O at all — the guard runs before the request is built.
            self::assertSame([], $captured);
        }
    }

    #[Test]
    public function the_error_names_what_the_caller_can_do_about_it(): void
    {
        $unusedCaptured = [];
        $client = $this->makeClient([], null, $unusedCaptured);

        try {
            $client->streamEvents(self::WORKFLOW_ID);
            self::fail('Expected GislStreamHostNotDeclaredError');
        } catch (GislStreamHostNotDeclaredError $e) {
            self::assertStringContainsString('stream base URL', $e->getMessage());
            self::assertStringContainsString(Credentials::GISL_STREAM_BASE_URL_ENV, $e->getMessage());
            // Says which environments DO work, not merely that this one does not.
            self::assertStringContainsString('staging', $e->getMessage());
        }
    }

    #[Test]
    public function the_error_is_catchable_as_a_config_error(): void
    {
        $unusedCaptured = [];
        $client = $this->makeClient([], null, $unusedCaptured);

        // Subclassing matters: a consumer with an existing
        // `catch (GislConfigError)` should not need to learn a new type.
        $this->expectException(GislConfigError::class);
        $client->streamEvents(self::WORKFLOW_ID);
    }

    #[Test]
    public function does_not_derive_a_stream_host_from_an_api_base_url(): void
    {
        // The banned behaviour, stated as a test. `baseUrl` alone gives the SDK
        // everything it would need to guess `stream.staging…` by string
        // surgery — and it must still refuse.
        $captured = [];
        $client = $this->makeClient([], null, $captured, self::API_HOST);

        $this->expectException(GislStreamHostNotDeclaredError::class);
        $client->streamEvents(self::WORKFLOW_ID);
    }

    #[Test]
    public function rejects_a_present_but_malformed_stream_base_url(): void
    {
        // codex 5114556a46a2: '/' passed the old non-empty check and then
        // rtrim'd to '', so the stream URI became RELATIVE — neither the host
        // the caller asked for nor a fail-closed refusal. A value the caller
        // supplied and got wrong must not be quietly reclassified as "nobody
        // declared one", which would hand them a poll they never asked for.
        foreach (['/', '//', 'stream.example.com', '/api', 'ftp://stream.example.com'] as $bad) {
            try {
                new GislClientConfig(
                    baseUrl: self::API_HOST,
                    apiKey: 'sk_test',
                    streamBaseUrl: $bad,
                );
                self::fail("Expected GislConfigError for streamBaseUrl '{$bad}'");
            } catch (GislConfigError $e) {
                self::assertStringContainsString('streamBaseUrl', $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_whitespace_only_stream_base_url_is_unset_not_malformed(): void
    {
        // '' and '   ' are indistinguishable in intent, so they take the SAME
        // path: unset. That path is still fail-closed — streamEvents refuses —
        // it just refuses at the stream call rather than at construction.
        $captured = [];
        $client = $this->makeClient([], '   ', $captured);

        $this->expectException(GislStreamHostNotDeclaredError::class);
        $client->streamEvents(self::WORKFLOW_ID);
    }

    #[Test]
    public function rejects_a_stream_host_carrying_a_query_or_fragment(): void
    {
        // codex 5793a3be0f7b: the events path is appended as a STRING, so
        // 'https://host?token=x' would request '/' with the whole events path
        // buried inside the query value — a misroute that looks like a valid URL.
        foreach (['https://stream.example.test?token=x', 'https://stream.example.test#frag'] as $bad) {
            try {
                new GislClientConfig(
                    baseUrl: self::API_HOST,
                    apiKey: 'sk_test',
                    streamBaseUrl: $bad,
                );
                self::fail("Expected GislConfigError for streamBaseUrl '{$bad}'");
            } catch (GislConfigError $e) {
                self::assertStringContainsString('query or fragment', $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_whitespace_only_override_does_not_suppress_the_environment_host(): void
    {
        // codex a7f5ec9f0d32: the resolver used to count '   ' as "supplied",
        // which SHADOWED staging's declared host and then normalised to
        // nothing — silently disabling a stream that was perfectly well
        // declared.
        self::assertSame(
            self::STREAM_HOST,
            Credentials::resolveStreamEndpoint(
                streamBaseUrl: '   ',
                environment: Environment::Staging,
            ),
        );
    }

    #[Test]
    public function accepts_an_absolute_http_or_https_stream_host(): void
    {
        foreach (['https://stream.example.test', 'http://localhost:8080'] as $good) {
            $config = new GislClientConfig(
                baseUrl: self::API_HOST,
                apiKey: 'sk_test',
                streamBaseUrl: $good,
            );
            self::assertSame($good, $config->streamBaseUrl);
        }
    }

    #[Test]
    public function an_empty_stream_base_url_is_absent_not_a_declared_empty_host(): void
    {
        // An empty string must not produce a relative URI against the API host —
        // that is a silent fallback wearing different clothes.
        $captured = [];
        $client = $this->makeClient([], '', $captured);

        $this->expectException(GislStreamHostNotDeclaredError::class);
        $client->streamEvents(self::WORKFLOW_ID);
    }

    #[Test]
    public function a_trailing_slash_is_stripped_so_the_path_is_not_double_separated(): void
    {
        $captured = [];
        $client = $this->makeClient([$this->sseResponse()], 'https://stream.example.test/', $captured);

        \iterator_to_array($client->streamEvents(self::WORKFLOW_ID));

        self::assertSame(
            ['https://stream.example.test/api/workflows/' . self::WORKFLOW_ID . '/events'],
            $this->requestedUris($captured),
        );
    }

    // -----------------------------------------------------------------------
    // Resolver precedence, asserted through the same public surface
    // -----------------------------------------------------------------------

    #[Test]
    public function resolves_the_stream_host_from_the_environment(): void
    {
        self::assertSame(
            self::STREAM_HOST,
            Credentials::resolveStreamEndpoint(environment: Environment::Staging),
        );
    }

    #[Test]
    public function production_resolves_to_the_declared_production_stream_host(): void
    {
        // Landed with contracts v2.195.0 (#410). Until then this asserted null,
        // and the conformance tripwire that guarded the gap is deleted rather
        // than weakened.
        self::assertSame(
            self::PROD_STREAM_HOST,
            Credentials::resolveStreamEndpoint(environment: Environment::Prod),
        );
    }

    #[Test]
    public function an_unconfigured_client_resolves_the_production_stream_host(): void
    {
        // codex 480e8b865b90: resolveEndpoint() falls through to the production
        // API host when nothing is configured, so an unconfigured client
        // already talks to production. Its stream must default with it, or the
        // DEFAULT configuration is the one that cannot stream.
        self::assertSame(self::PROD_STREAM_HOST, Credentials::resolveStreamEndpoint());
        self::assertSame(Credentials::DEFAULT_ENDPOINT, Credentials::resolveEndpoint());
    }

    #[Test]
    public function an_explicit_base_url_does_not_get_productions_stream_host(): void
    {
        // The load-bearing half. An explicit base URL names a host we were told
        // about and cannot reason about, so defaulting its stream to production
        // would be deriving one host from another.
        self::assertNull(
            Credentials::resolveStreamEndpoint(baseUrl: 'https://api.internal.test'),
        );
    }

    #[Test]
    public function a_gisl_base_url_env_does_not_get_productions_stream_host(): void
    {
        putenv(Credentials::GISL_BASE_URL_ENV . '=https://api.internal.test');

        self::assertNull(Credentials::resolveStreamEndpoint());
    }

    #[Test]
    public function a_configuration_pointed_at_an_unknown_host_still_resolves_to_null(): void
    {
        // The fail-closed path, reached the only way it still can: a host we
        // were told about and cannot reason about. `null` is never the API base
        // URL — the rule did not soften when prod landed.
        $resolved = Credentials::resolveStreamEndpoint(baseUrl: 'https://api.internal.test');

        self::assertNull($resolved);
        self::assertNotSame('https://api.internal.test', $resolved);
    }

    #[Test]
    public function stream_events_reaches_the_production_stream_host(): void
    {
        // Public entry point, not the resolver: the resolver can be right while
        // the client still sends the stream to the API host.
        $captured = [];
        $client = $this->makeClient(
            [$this->sseResponse()],
            self::PROD_STREAM_HOST,
            $captured,
            'https://api.giveitsmaller.com',
        );

        \iterator_to_array($client->streamEvents(self::WORKFLOW_ID));

        self::assertSame(
            [self::PROD_STREAM_HOST . '/api/workflows/' . self::WORKFLOW_ID . '/events'],
            $this->requestedUris($captured),
        );
    }

    #[Test]
    public function an_explicit_stream_base_url_wins_over_the_environment(): void
    {
        self::assertSame(
            'https://stream.example.test',
            Credentials::resolveStreamEndpoint(
                streamBaseUrl: 'https://stream.example.test',
                environment: Environment::Staging,
            ),
        );
    }

    #[Test]
    public function resolves_the_stream_host_from_the_env_var(): void
    {
        putenv(Credentials::GISL_STREAM_BASE_URL_ENV . '=https://stream.env.test');

        self::assertSame('https://stream.env.test', Credentials::resolveStreamEndpoint());
    }

    #[Test]
    public function an_explicit_environment_wins_over_the_env_var(): void
    {
        putenv(Credentials::GISL_STREAM_BASE_URL_ENV . '=https://stream.env.test');

        // Same precedence shape as resolveEndpoint: a code-level argument
        // outranks ambient environment configuration.
        self::assertSame(
            self::STREAM_HOST,
            Credentials::resolveStreamEndpoint(environment: Environment::Staging),
        );
    }

    #[Test]
    public function resolves_the_stream_host_from_a_gisl_environment_name(): void
    {
        putenv(Credentials::GISL_ENVIRONMENT_ENV . '=staging');

        self::assertSame(self::STREAM_HOST, Credentials::resolveStreamEndpoint());
    }

    #[Test]
    public function a_gisl_environment_naming_prod_resolves_the_production_host(): void
    {
        putenv(Credentials::GISL_ENVIRONMENT_ENV . '=prod');

        self::assertSame(self::PROD_STREAM_HOST, Credentials::resolveStreamEndpoint());
    }

    // vzVIw4ZZ — a PRESENT-but-blank streamBaseUrl option: never suppresses a
    // declared host, and THROWS instead of defaulting to production when
    // nothing declares one. Mirrors credentials.test.ts.

    #[Test]
    public function a_blank_stream_base_url_with_nothing_else_declared_throws(): void
    {
        // "\u{00A0}" (NBSP) and "\u{3000}" (ideographic space): blank in TS's
        // trim(), and now in PHP too (codex 448553c3ac21).
        foreach (['', '   ', "\u{00A0}", "\u{3000}\u{00A0}"] as $blank) {
            try {
                Credentials::resolveStreamEndpoint(streamBaseUrl: $blank);
                self::fail('blank streamBaseUrl must not resolve to the production stream host');
            } catch (GislConfigError $e) {
                self::assertSame('blank_value', $e->reason);
                self::assertSame(['streamBaseUrl'], $e->conflictingFields);
            }
        }
        $this->expectException(GislConfigError::class);
        Credentials::resolveStreamEndpoint(streamBaseUrl: '', baseUrl: 'https://api.self-hosted.example');
    }

    #[Test]
    public function a_blank_stream_base_url_does_not_suppress_a_declared_host(): void
    {
        self::assertSame(
            Credentials::resolveStreamEndpoint(environment: Environment::Staging),
            Credentials::resolveStreamEndpoint(streamBaseUrl: '', environment: Environment::Staging),
        );
        putenv(Credentials::GISL_STREAM_BASE_URL_ENV . '=https://stream.self-hosted.example');
        self::assertSame('https://stream.self-hosted.example', Credentials::resolveStreamEndpoint(streamBaseUrl: ''));
    }

    #[Test]
    public function the_unconfigured_case_is_unchanged(): void
    {
        self::assertSame(
            Credentials::resolveStreamEndpoint(environment: Environment::Prod),
            Credentials::resolveStreamEndpoint(),
        );
    }
}
