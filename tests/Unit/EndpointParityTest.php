<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Generated\OpenApi\Model\AudioWatermarkDecodeRequest;
use Gisl\Generated\OpenApi\Model\BillingCheckoutRequest;
use Gisl\Generated\OpenApi\Model\ContactRequest;
use Gisl\Generated\OpenApi\Model\ExternalImportRequest;
use Gisl\Generated\OpenApi\Model\LoginUserRequest;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use Gisl\Sdk\WorkflowCreatePayload;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * UEygYT2E (DX A1a-PHP) — the `php` column of docs/parity/endpoints.yaml,
 * proven on the real PHP surface rather than read from the code by hand.
 *
 * For every row whose `php` value is a method name or `{internal: m}`:
 *  - the method exists on {@see GislClient} and is public, and
 *    {@see GislErgonomicClient} inherits it unchanged (a future override fails);
 *  - invoking it through a Guzzle MockHandler records exactly one request
 *    whose HTTP method, path and host match the row's spec `location`
 *    (internal rows: their request appears inside one `uploadFile` run).
 *
 * For every `{gap: card}` row and every path-bearing `excluded` row, no
 * request recorded by the exercised methods matches its method and path.
 *
 * ⚠️ BOUND OF THE ABSENCE CHECK: only the methods in {@see self::invokers()}
 * plus one multipart `uploadFile` run are exercised. The unmapped public
 * helpers (`workflows`, `waitForWorkflow`, `waitForProbe`,
 * `createWorkflowAwaitingProbe`, `maybeWaitForVideoProbe`, `preflightClips`)
 * are NOT invoked, so this is not a whole-surface proof that no public method
 * issues a gap row's request.
 *
 * {@see \Gisl\Sdk\GislAnonymousClient} is out of scope: it overrides most rows
 * to throw before sending, by design.
 */
#[CoversClass(GislClient::class)]
final class EndpointParityTest extends TestCase
{
    private const API_BASE_URL = 'https://api.example.com';
    private const API_HOST = 'api.example.com';
    private const STREAM_BASE_URL = 'https://stream.example.com';
    private const STREAM_HOST = 'stream.example.com';

    /** The one method that targets the declared stream host, not baseUrl. */
    private const STREAM_METHOD = 'streamEvents';

    // Multipart run, copied from GislClientMultipartTest: one 8 MiB first
    // chunk sent with initiate, one 16 MiB remaining part via a presigned PUT.
    private const MULTIPART_THRESHOLD = 8_388_608;
    private const MULTIPART_CHUNK_SIZE = 16_777_216;
    private const MULTIPART_TOTAL_SIZE = 25_165_824;
    private const MULTIPART_UPLOAD_ID = '01936fb2-0000-7000-8000-000000000aaa';

    /** Enough queued responses that an unexpected extra request is RECORDED, not thrown before history. */
    private const QUEUED_RESPONSES = 5;

    /** @var list<array<string, mixed>>|null */
    private static ?array $rows = null;

    /** @var list<RequestInterface>|null */
    private static ?array $multipartRequests = null;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (\file_exists($path)) {
                @\unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    // ------------------------------------------------------------------
    // YAML and partition
    // ------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(): array
    {
        if (self::$rows === null) {
            $path = \dirname(__DIR__, 4) . '/docs/parity/endpoints.yaml';
            $parsed = Yaml::parseFile($path);
            if (!\is_array($parsed) || !\array_is_list($parsed)) {
                throw new \RuntimeException("{$path}: top level must be a list of rows");
            }
            $rows = [];
            foreach ($parsed as $row) {
                if (!\is_array($row)) {
                    throw new \RuntimeException("{$path}: every row must be a mapping");
                }
                /** @var array<string, mixed> $row */
                $rows[] = $row;
            }
            self::$rows = $rows;
        }

        return self::$rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(string $operationId): array
    {
        foreach (self::rows() as $row) {
            if (($row['operationId'] ?? null) === $operationId) {
                return $row;
            }
        }
        throw new \RuntimeException("No row for operationId {$operationId}");
    }

    /**
     * The row's PHP classification: exactly one of method / internal / gap / excluded.
     *
     * @param array<string, mixed> $row
     * @return array{kind: 'method'|'internal'|'gap'|'excluded', value: string}
     */
    private static function classify(array $row): array
    {
        $id = \is_string($row['operationId'] ?? null) ? $row['operationId'] : '?';
        $hasExcluded = \array_key_exists('excluded', $row);
        $hasPhp = \array_key_exists('php', $row);
        if ($hasExcluded === $hasPhp) {
            throw new \RuntimeException("{$id}: a row is either excluded or has a php value, exactly one");
        }
        if ($hasExcluded) {
            if (!\is_string($row['excluded']) || $row['excluded'] === '') {
                throw new \RuntimeException("{$id}: excluded needs a non-empty reason");
            }

            return ['kind' => 'excluded', 'value' => $row['excluded']];
        }
        $php = $row['php'];
        if (\is_string($php) && $php !== '') {
            return ['kind' => 'method', 'value' => $php];
        }
        if (\is_array($php) && \count($php) === 1) {
            $kind = \array_key_first($php);
            $value = $php[$kind];
            if (($kind === 'internal' || $kind === 'gap') && \is_string($value) && $value !== '') {
                return ['kind' => $kind, 'value' => $value];
            }
        }
        throw new \RuntimeException("{$id}: php must be a method name, {internal: m} or {gap: card}");
    }

    /**
     * @param array<string, mixed> $row
     * @return array{method: string, path: ?string}
     */
    private static function location(array $row): array
    {
        $location = $row['location'] ?? null;
        if (!\is_array($location) || !\is_string($location['method'] ?? null)) {
            throw new \RuntimeException('row location must carry a method');
        }
        $path = $location['path'] ?? null;

        return ['method' => $location['method'], 'path' => \is_string($path) ? $path : null];
    }

    /**
     * @param 'method'|'internal'|'gap'|'excluded' ...$kinds
     * @return list<array<string, mixed>>
     */
    private static function rowsOfKind(string ...$kinds): array
    {
        return \array_values(\array_filter(
            self::rows(),
            static fn (array $row): bool => \in_array(self::classify($row)['kind'], $kinds, true),
        ));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function operationId(array $row): string
    {
        $id = $row['operationId'] ?? null;
        if (!\is_string($id)) {
            throw new \RuntimeException('row without a string operationId');
        }

        return $id;
    }

    public function testEveryRowIsExactlyOneKindAndTheBoundSetIsTheMethodAndInternalRows(): void
    {
        $rows = self::rows();
        self::assertNotEmpty($rows);

        $byKind = ['method' => [], 'internal' => [], 'gap' => [], 'excluded' => []];
        $expectedBound = [];
        foreach ($rows as $row) {
            $kind = self::classify($row)['kind'];
            $byKind[$kind][] = self::operationId($row);
            if ($kind === 'method' || $kind === 'internal') {
                $expectedBound[] = self::operationId($row);
            }
        }

        self::assertSame(
            \count($rows),
            \count($byKind['method']) + \count($byKind['internal']) + \count($byKind['gap']) + \count($byKind['excluded']),
        );
        self::assertNotEmpty(self::rowsOfKind('method'));
        self::assertNotEmpty(self::rowsOfKind('internal'));
        self::assertSame($expectedBound, \array_keys([...self::boundRows()]));
    }

    // ------------------------------------------------------------------
    // (1) Existence
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function boundRows(): iterable
    {
        $cases = [];
        foreach (self::rowsOfKind('method', 'internal') as $row) {
            $cases[self::operationId($row)] = [self::operationId($row)];
        }

        return $cases;
    }

    /**
     * Null when $class has a public method $method, else the reason it does not.
     *
     * @param class-string $class
     */
    private static function existenceFailure(string $class, string $method): ?string
    {
        $reflection = new \ReflectionClass($class);
        if (!$reflection->hasMethod($method)) {
            return "{$class} has no method {$method}";
        }
        if (!$reflection->getMethod($method)->isPublic()) {
            return "{$class}::{$method} is not public";
        }

        return null;
    }

    #[DataProvider('boundRows')]
    public function testBoundMethodExistsPublicOnGislClientAndIsInheritedUnchanged(string $operationId): void
    {
        $method = self::classify(self::row($operationId))['value'];

        self::assertNull(self::existenceFailure(GislClient::class, $method));
        self::assertSame(
            GislClient::class,
            (new \ReflectionMethod(GislErgonomicClient::class, $method))->getDeclaringClass()->getName(),
            "GislErgonomicClient overrides {$method}; the row's binding is proven on GislClient only",
        );
    }

    // ------------------------------------------------------------------
    // (2) Binding, direct rows
    // ------------------------------------------------------------------

    /**
     * Minimal valid calls, keyed by PHP method name. Every argument passes the
     * method's own pre-send validation, so the request is actually issued.
     *
     * @return array<string, \Closure(GislClient): mixed>
     */
    private function invokers(): array
    {
        return [
            'uploadFile' => fn (GislClient $c): mixed => $c->uploadFile($this->writeFile(16)),
            'getUploadStatus' => static fn (GislClient $c): mixed => $c->getUploadStatus('upl_x'),
            // Part numbers must lie in [2, totalParts]: part 1 is sealed at initiate.
            'presignParts' => static fn (GislClient $c): mixed => $c->presignParts('upl_x', [2], 2),
            'keepaliveUpload' => static fn (GislClient $c): mixed => $c->keepaliveUpload('upl_x'),
            'getMetadata' => static fn (GislClient $c): mixed => $c->getMetadata('file_x'),
            'probeUpload' => static fn (GislClient $c): mixed => $c->probeUpload('file_x'),
            'listWorkflows' => static fn (GislClient $c): mixed => $c->listWorkflows(),
            'createWorkflow' => static fn (GislClient $c): mixed => $c->createWorkflow(new WorkflowCreatePayload(jobs: [])),
            'getWorkflowStatus' => static fn (GislClient $c): mixed => $c->getWorkflowStatus('wf_x'),
            'getWorkflowDownloads' => static fn (GislClient $c): mixed => $c->getWorkflowDownloads('wf_x'),
            // Returns a Generator, but the request is sent by the call itself:
            // the method body has no yield and returns parseSseStream(...).
            'streamEvents' => static function (GislClient $c): mixed {
                $c->streamEvents('wf_x');

                return null;
            },
            'cancelWorkflow' => static fn (GislClient $c): mixed => $c->cancelWorkflow('wf_x'),
            'resumeWorkflow' => static fn (GislClient $c): mixed => $c->resumeWorkflow('wf_x'),
            'archiveWorkflow' => static fn (GislClient $c): mixed => $c->archiveWorkflow('wf_x'),
            'restoreWorkflow' => static fn (GislClient $c): mixed => $c->restoreWorkflow('wf_x'),
            'getSchema' => static fn (GislClient $c): mixed => $c->getSchema(),
            'retryOperation' => static fn (GislClient $c): mixed => $c->retryOperation('op_x'),
            'getHealth' => static fn (GislClient $c): mixed => $c->getHealth(),
            'login' => static fn (GislClient $c): mixed => $c->login(new LoginUserRequest()),
            'logout' => static function (GislClient $c): mixed {
                $c->logout();

                return null;
            },
            'getProfile' => static fn (GislClient $c): mixed => $c->getProfile(),
            'createExternalImport' => static fn (GislClient $c): mixed => $c->createExternalImport(new ExternalImportRequest()),
            'decodeAudioWatermark' => static fn (GislClient $c): mixed => $c->decodeAudioWatermark(new AudioWatermarkDecodeRequest()),
            'getCreditsBalance' => static fn (GislClient $c): mixed => $c->getCreditsBalance(),
            'getCreditsUsage' => static fn (GislClient $c): mixed => $c->getCreditsUsage(),
            'getAccountLimits' => static fn (GislClient $c): mixed => $c->getAccountLimits(),
            'createCheckoutSession' => static fn (GislClient $c): mixed => $c->createCheckoutSession(new BillingCheckoutRequest()),
            'getCheckoutSessionStatus' => static fn (GislClient $c): mixed => $c->getCheckoutSessionStatus('cs_x'),
            'submitContact' => static function (GislClient $c): mixed {
                $c->submitContact(new ContactRequest());

                return null;
            },
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function directRows(): iterable
    {
        $cases = [];
        foreach (self::rowsOfKind('method') as $row) {
            $cases[self::operationId($row)] = [self::operationId($row)];
        }

        return $cases;
    }

    /**
     * @param list<ResponseInterface>        $queue
     * @param \ArrayObject<int, mixed> $history filled by Middleware::history
     */
    private static function client(array $queue, \ArrayObject $history, ?int $multipartThresholdBytes = null): GislClient
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));
        $factory = new HttpFactory();

        return new GislClient(
            config: new GislClientConfig(
                baseUrl: self::API_BASE_URL,
                apiKey: 'sk_test',
                multipartThresholdBytes: $multipartThresholdBytes,
                multipartRetryBaseMs: 0,
                streamBaseUrl: self::STREAM_BASE_URL,
            ),
            httpClient: new Client(['handler' => $stack]),
            requestFactory: $factory,
            streamFactory: $factory,
        );
    }

    /**
     * @param \ArrayObject<int, mixed> $history
     * @return list<RequestInterface>
     */
    private static function requestsOf(\ArrayObject $history): array
    {
        $requests = [];
        foreach ($history as $entry) {
            if (!\is_array($entry) || !($entry['request'] ?? null) instanceof RequestInterface) {
                throw new \RuntimeException('history entry without a request');
            }
            $requests[] = $entry['request'];
        }

        return $requests;
    }

    /**
     * Invoke one mapped method and return every request it recorded.
     * Exceptions are swallowed: response hydration is not the subject, the
     * request is, and an empty history still fails the exactly-one check.
     *
     * @return list<RequestInterface>
     */
    private function record(string $method): array
    {
        $invokers = $this->invokers();
        self::assertArrayHasKey($method, $invokers, "No invoker for {$method}: a mapped method must be exercised, never skipped");

        $queue = [];
        for ($i = 0; $i < self::QUEUED_RESPONSES; $i++) {
            $queue[] = new Response(200, ['Content-Type' => 'application/json'], '{"success":true,"data":{}}');
        }
        $history = new \ArrayObject();
        $client = self::client($queue, $history);
        try {
            $invokers[$method]($client);
        } catch (\Throwable) {
            // See docblock.
        }

        return self::requestsOf($history);
    }

    /**
     * Anchored regex for a spec path: literal segments preg_quote'd, each
     * `{param}` one non-empty path segment.
     */
    private static function pathRegex(string $specPath): string
    {
        $parts = \preg_split('/(\{[^}\/]+\})/', $specPath, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            throw new \RuntimeException("Cannot split {$specPath}");
        }
        $regex = '';
        foreach ($parts as $part) {
            $regex .= \preg_match('/^\{[^}\/]+\}$/', $part) === 1 ? '[^\/]+' : \preg_quote($part, '#');
        }

        return '#^' . $regex . '$#D';
    }

    /**
     * Mismatches between a recorded request and a spec location, keyed by
     * what differs ('method', 'path', 'host'); empty when it matches.
     *
     * @param array{method: string, path: ?string} $location
     * @return array<'method'|'path'|'host', string>
     */
    private static function locationMismatches(RequestInterface $request, array $location, string $expectedHost): array
    {
        $mismatches = [];
        if ($request->getMethod() !== \strtoupper($location['method'])) {
            $mismatches['method'] = "{$request->getMethod()} != " . \strtoupper($location['method']);
        }
        if ($location['path'] === null || \preg_match(self::pathRegex($location['path']), $request->getUri()->getPath()) !== 1) {
            $mismatches['path'] = $request->getUri()->getPath() . ' !~ ' . ($location['path'] ?? '(no path)');
        }
        if ($request->getUri()->getHost() !== $expectedHost) {
            $mismatches['host'] = "{$request->getUri()->getHost()} != {$expectedHost}";
        }

        return $mismatches;
    }

    /**
     * @param list<RequestInterface> $requests
     */
    private static function exactlyOneFailure(array $requests): ?string
    {
        return \count($requests) === 1 ? null : 'expected exactly one request, recorded ' . \count($requests);
    }

    #[DataProvider('directRows')]
    public function testDirectRowIssuesExactlyOneRequestToItsSpecLocation(string $operationId): void
    {
        $row = self::row($operationId);
        $method = self::classify($row)['value'];
        $requests = $this->record($method);

        self::assertNull(self::exactlyOneFailure($requests));
        $expectedHost = $method === self::STREAM_METHOD ? self::STREAM_HOST : self::API_HOST;
        self::assertSame([], self::locationMismatches($requests[0], self::location($row), $expectedHost));
    }

    // ------------------------------------------------------------------
    // (3) Binding, internal rows
    // ------------------------------------------------------------------

    /**
     * One multipart uploadFile run (initiate, one S3 part PUT, complete),
     * shared by the internal-row and absence checks.
     *
     * @return list<RequestInterface>
     */
    private function multipartRequests(): array
    {
        if (self::$multipartRequests === null) {
            $path = $this->writeFile(self::MULTIPART_TOTAL_SIZE);
            $queue = [
                self::jsonResponse(self::initiateEnvelope()),
                new Response(200, ['ETag' => '"etag-part-2"'], ''),
                self::jsonResponse([
                    'success' => true,
                    'data' => ['upload_id' => self::MULTIPART_UPLOAD_ID, 'status' => 'completed'],
                ]),
            ];
            $history = new \ArrayObject();
            // Threshold set explicitly, as GislClientMultipartTest does, so the
            // run is multipart whatever the default becomes.
            self::client($queue, $history, self::MULTIPART_THRESHOLD)->uploadFile($path);
            self::$multipartRequests = self::requestsOf($history);
        }

        return self::$multipartRequests;
    }

    public function testInternalRowsAreIssuedInsideOneMultipartUploadInitiateBeforeComplete(): void
    {
        $requests = $this->multipartRequests();
        $internalRows = self::rowsOfKind('internal');
        self::assertNotEmpty($internalRows);

        $positions = [];
        foreach ($internalRows as $row) {
            $operationId = self::operationId($row);
            $method = self::classify($row)['value'];
            self::assertArrayHasKey($method, $this->invokers(), "{$operationId}: internal host method {$method} has no invoker");
            self::assertSame('uploadFile', $method, "{$operationId}: only uploadFile's internal requests are driven here");

            $matches = [];
            foreach ($requests as $index => $request) {
                if (self::locationMismatches($request, self::location($row), self::API_HOST) === []) {
                    $matches[] = $index;
                }
            }
            self::assertCount(1, $matches, "{$operationId}: expected its request exactly once in the uploadFile run");
            $positions[$operationId] = $matches[0];
        }

        self::assertArrayHasKey('multipartInitiate', $positions);
        self::assertArrayHasKey('multipartComplete', $positions);
        self::assertLessThan($positions['multipartComplete'], $positions['multipartInitiate']);
    }

    // ------------------------------------------------------------------
    // (4) Absence, gap and excluded rows
    // ------------------------------------------------------------------

    public function testNoExercisedMethodIssuesAGapOrExcludedRowsRequest(): void
    {
        $recorded = $this->multipartRequests();
        foreach (\array_keys($this->invokers()) as $method) {
            \array_push($recorded, ...$this->record($method));
        }
        self::assertNotEmpty($recorded);

        [$violations, $checked] = self::absenceViolations($recorded, self::rowsOfKind('gap', 'excluded'));

        self::assertSame([], $violations);
        self::assertGreaterThan(0, $checked);
    }

    /**
     * Requests that hit a gap or excluded row's method + path (any host), and
     * how many path-bearing rows were checked. The outbound webhook row has
     * {webhook, method} and no path the client could call, so it is skipped.
     *
     * @param list<RequestInterface> $requests
     * @param list<array<string, mixed>> $rows
     * @return array{0: list<string>, 1: int}
     */
    private static function absenceViolations(array $requests, array $rows): array
    {
        $violations = [];
        $checked = 0;
        foreach ($rows as $row) {
            $operationId = self::operationId($row);
            $location = self::location($row);
            if ($location['path'] === null) {
                if (self::classify($row)['kind'] !== 'excluded') {
                    $violations[] = "{$operationId}: only an excluded row may lack a path";
                }
                continue;
            }
            foreach ($requests as $request) {
                if (self::locationMismatches($request, $location, $request->getUri()->getHost()) === []) {
                    $violations[] = "{$operationId}: {$request->getMethod()} {$request->getUri()->getPath()} was issued by an exercised method";
                }
            }
            $checked++;
        }

        return [$violations, $checked];
    }

    // ------------------------------------------------------------------
    // (5) Invoker map completeness
    // ------------------------------------------------------------------

    public function testInvokerMapAndYamlNameTheSameMethods(): void
    {
        $named = [];
        foreach (self::rowsOfKind('method', 'internal') as $row) {
            $named[self::classify($row)['value']] = true;
        }
        $named = \array_keys($named);
        $invokerKeys = \array_keys($this->invokers());
        \sort($named);
        \sort($invokerKeys);

        self::assertSame([], \array_values(\array_diff($named, $invokerKeys)), 'methods named in the YAML with no invoker');
        self::assertSame([], \array_values(\array_diff($invokerKeys, $named)), 'invokers no row names');
    }

    // ------------------------------------------------------------------
    // (6) Negative controls through the same pure helpers
    // ------------------------------------------------------------------

    public function testNegativeControlAMisspeltMethodFailsExistence(): void
    {
        self::assertSame(
            GislClient::class . ' has no method getHelth',
            self::existenceFailure(GislClient::class, 'getHelth'),
        );
    }

    public function testNegativeControlAMethodBoundToAnotherRowsEndpointFailsTheMatch(): void
    {
        $requests = $this->record('getHealth');
        self::assertNull(self::exactlyOneFailure($requests));

        $mismatches = self::locationMismatches($requests[0], self::location(self::row('logoutUser')), self::API_HOST);

        self::assertSame(['method', 'path'], \array_keys($mismatches));
        self::assertSame('GET != POST', $mismatches['method']);
        self::assertSame('/healthz !~ /api/auth/logout', $mismatches['path']);
    }

    public function testNegativeControlAParamPathDoesNotMatchADifferentLiteralSegment(): void
    {
        $location = ['method' => 'get', 'path' => '/api/workflows/{id}/status'];

        self::assertSame(
            [],
            self::locationMismatches(new Request('GET', 'https://api.example.com/api/workflows/wf_x/status'), $location, self::API_HOST),
        );
        $mismatches = self::locationMismatches(
            new Request('GET', 'https://api.example.com/api/workflows/wf_x/downloads'),
            $location,
            self::API_HOST,
        );
        self::assertSame(['path'], \array_keys($mismatches));
        // {param} is one segment: it cannot swallow a slash.
        self::assertSame(
            ['path'],
            \array_keys(self::locationMismatches(new Request('GET', 'https://api.example.com/api/workflows/a/b/status'), $location, self::API_HOST)),
        );
    }

    public function testNegativeControlAPathWithAPrefixOrSuffixDoesNotMatch(): void
    {
        $location = ['method' => 'get', 'path' => '/api/workflows/{id}/status'];

        foreach (['/api/workflows/wf_x/status/extra', '/prefix/api/workflows/wf_x/status'] as $path) {
            self::assertSame(
                ['path'],
                \array_keys(self::locationMismatches(new Request('GET', 'https://api.example.com' . $path), $location, self::API_HOST)),
                $path,
            );
        }
    }

    public function testNegativeControlARequestOnTheWrongHostFailsTheMatch(): void
    {
        $location = ['method' => 'get', 'path' => '/healthz'];
        $mismatches = self::locationMismatches(new Request('GET', 'https://' . self::STREAM_HOST . '/healthz'), $location, self::API_HOST);

        self::assertSame(['host'], \array_keys($mismatches));
        self::assertSame(self::STREAM_HOST . ' != ' . self::API_HOST, $mismatches['host']);
    }

    public function testNegativeControlARequestToAGapRowIsAnAbsenceViolation(): void
    {
        $readiness = self::row('readiness');
        $location = self::location($readiness);
        self::assertNotNull($location['path']);
        $request = new Request(\strtoupper($location['method']), 'https://' . self::API_HOST . $location['path']);

        [$violations, $checked] = self::absenceViolations([$request], [$readiness]);

        self::assertSame(1, $checked);
        self::assertSame(["readiness: {$request->getMethod()} {$location['path']} was issued by an exercised method"], $violations);
    }

    public function testNegativeControlATwoRequestHistoryFailsExactlyOne(): void
    {
        $two = [new Request('GET', 'https://api.example.com/healthz'), new Request('GET', 'https://api.example.com/healthz')];

        self::assertSame('expected exactly one request, recorded 2', self::exactlyOneFailure($two));
        self::assertSame('expected exactly one request, recorded 0', self::exactlyOneFailure([]));
    }

    // ------------------------------------------------------------------
    // Fixtures (minimal copies of GislClientMultipartTest's private helpers)
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     */
    private static function jsonResponse(array $body): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], \json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private static function initiateEnvelope(): array
    {
        return [
            'success' => true,
            'data' => [
                'upload_id' => self::MULTIPART_UPLOAD_ID,
                'mime_type' => 'image/jpeg',
                'first_chunk_etag' => '"etag-part-1"',
                'first_chunk_size_bytes' => self::MULTIPART_THRESHOLD,
                'total_parts' => 2,
                'recommended_chunk_size' => self::MULTIPART_CHUNK_SIZE,
                'presigned_urls' => [[
                    'part_number' => 2,
                    'url' => 'https://s3.example.com/upload/2',
                    'expires_at' => '2026-12-31T00:00:00Z',
                ]],
                'constraints_applied' => [
                    'max_size_bytes' => 100_000_000,
                    'max_duration_seconds' => 600,
                    'processing_class_pre_assignment' => 'short_form',
                ],
            ],
        ];
    }

    private function writeFile(int $size): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'gisl-endpoint-parity-');
        if ($path === false) {
            self::fail('tempnam failed');
        }
        $this->tempFiles[] = $path;

        $fh = \fopen($path, 'wb');
        if ($fh === false) {
            self::fail("Could not open {$path} for writing");
        }
        try {
            $block = \str_repeat('A', 65_536);
            $remaining = $size;
            while ($remaining > 0) {
                $written = \fwrite($fh, \substr($block, 0, \min($remaining, 65_536)));
                if ($written === false || $written === 0) {
                    self::fail("Short write to {$path}");
                }
                $remaining -= $written;
            }
        } finally {
            \fclose($fh);
        }

        return $path;
    }
}
