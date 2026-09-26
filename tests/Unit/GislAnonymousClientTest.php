<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Errors\GislApiError;
use Gisl\Sdk\Errors\GislFeatureRequiresAuthError;
use Gisl\Sdk\Errors\GislTierRestrictedError;
use Gisl\Sdk\Gisl;
use Gisl\Sdk\GislAnonymousClient;
use Gisl\Sdk\UploadOptions;
use Gisl\Sdk\WaitOptions;
use Gisl\Sdk\WorkflowCreatePayload;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * OuegCUtq — `Gisl::anonymous()` end to end against a routing PSR-18 stub:
 * no credential on any request, every request lands on an endpoint the
 * contract marks anonymous-capable, the `cap` from create is threaded into the
 * workflow's reads, and the API's guest refusals reach the caller typed. PHP
 * arm of the TS `anonymous.test.ts`.
 */
#[CoversClass(GislAnonymousClient::class)]
#[CoversClass(Gisl::class)]
final class GislAnonymousClientTest extends TestCase
{
    private const BASE = 'https://api.example.com';
    private const STREAM = 'https://stream.example.com';
    private const WORKFLOW_ID = '01936fb2-0000-7000-8000-0000000070e2';
    private const FILE_ID = '01936fb1-7bb3-7000-8000-0000000070e1';
    private const CAP = 'cap_plaintext_token_from_create';

    /** @var list<RequestInterface> */
    private array $seen = [];

    private int $createStatus = 201;

    /** @var array<string, mixed> */
    private array $createBody = [];

    /** @var array<string, false|string> */
    private array $envSnapshot = [];

    private string $tmpFile = '';

    protected function setUp(): void
    {
        $this->envSnapshot = ['GISL_API_KEY' => getenv('GISL_API_KEY')];
        putenv('GISL_API_KEY=env-key-must-never-reach-a-guest-request');
        $this->createBody = self::anonymousCreateBody(self::CAP);
        $this->tmpFile = sys_get_temp_dir() . '/gisl-anon-' . bin2hex(random_bytes(6)) . '.jpg';
        file_put_contents($this->tmpFile, "\xff\xd8\xff");
    }

    protected function tearDown(): void
    {
        foreach ($this->envSnapshot as $name => $value) {
            $value === false ? putenv($name) : putenv("{$name}={$value}");
        }
        if (is_file($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    public function testQuickStartRunsCredentialFreeOnAnonymousEndpointsWithCapThreaded(): void
    {
        $result = $this->client()->file($this->tmpFile)->compress()->run(maxWait: '30s', useSSE: false);

        self::assertSame(self::WORKFLOW_ID, $result->workflowId);
        self::assertSame(
            [
                'POST /api/uploads',
                'POST /api/workflows',
                'GET /api/workflows/' . self::WORKFLOW_ID . '/status',
                'GET /api/workflows/' . self::WORKFLOW_ID . '/downloads',
            ],
            $this->paths(),
        );
        $this->assertNoCredentialAndOnlyOpenEndpoints();
        self::assertFalse($this->seen[0]->hasHeader('X-Workflow-Capability'));
        self::assertFalse($this->seen[1]->hasHeader('X-Workflow-Capability'));
        self::assertSame(self::CAP, $this->seen[2]->getHeaderLine('X-Workflow-Capability'));
        self::assertSame(self::CAP, $this->seen[3]->getHeaderLine('X-Workflow-Capability'));
    }

    public function testAnUploadAtTheSingleShotCapGoesSingleShot(): void
    {
        file_put_contents($this->tmpFile, "\xff\xd8\xff" . str_repeat("\0", GislAnonymousClient::MAX_UPLOAD_BYTES - 3));
        $this->client()->uploadFile($this->tmpFile);

        self::assertSame(['POST /api/uploads'], $this->paths());
        self::assertSame(10_000_000, GislAnonymousClient::MAX_UPLOAD_BYTES);
    }

    public function testAnUploadOverTheSingleShotCapIsRefusedBeforeAnyRequest(): void
    {
        // Multipart needs an account (compression_api security.yaml), so a
        // guest file one byte over the single-shot cap has no route.
        file_put_contents($this->tmpFile, str_repeat("\0", GislAnonymousClient::MAX_UPLOAD_BYTES + 1));
        try {
            $this->client()->file($this->tmpFile)->compress()->run(useSSE: false);
            self::fail('Expected GislFeatureRequiresAuthError');
        } catch (GislFeatureRequiresAuthError $e) {
            self::assertSame('uploadFile', $e->operation);
            self::assertStringContainsString('10000000', $e->getMessage());
        }
        self::assertSame([], $this->seen);
    }

    public function testABufferedNonSeekableStreamIsSizedBeforeTheCheck(): void
    {
        // A real pipe: not seekable, so its size is unknown until buffered.
        $pipe = popen('head -c ' . (GislAnonymousClient::MAX_UPLOAD_BYTES + 1) . ' /dev/zero', 'r');
        self::assertIsResource($pipe);
        self::assertFalse(stream_get_meta_data($pipe)['seekable']);
        try {
            $this->client()->uploadFile($pipe, new UploadOptions(bufferNonSeekable: true));
            self::fail('Expected GislFeatureRequiresAuthError');
        } catch (GislFeatureRequiresAuthError $e) {
            self::assertSame('uploadFile', $e->operation);
        } finally {
            pclose($pipe);
        }
        self::assertSame([], $this->seen);
    }

    public function testAnEndlessNonSeekableStreamIsRefusedAfterReadingAtMostCapPlusOne(): void
    {
        // `yes` never ends: an unbounded copy would never return.
        $pipe = popen('yes', 'r');
        self::assertIsResource($pipe);
        try {
            $this->client()->uploadFile($pipe, new UploadOptions(bufferNonSeekable: true));
            self::fail('Expected GislFeatureRequiresAuthError');
        } catch (GislFeatureRequiresAuthError $e) {
            self::assertSame('uploadFile', $e->operation);
        } finally {
            pclose($pipe);
        }
        self::assertSame([], $this->seen);
    }

    public function testCredentialHeadersAreDroppedAndOtherHeadersKept(): void
    {
        $factory = new HttpFactory();
        $client = Gisl::anonymous(
            baseUrl: self::BASE,
            headers: ['Authorization' => 'Bearer smuggled', 'cookie' => 'PHPSESSID=x', 'X-Trace' => 't-1'],
            httpClient: $this->router(),
            requestFactory: $factory,
            streamFactory: $factory,
            streamBaseUrl: self::STREAM,
        );
        $client->getWorkflowStatus(self::WORKFLOW_ID);
        self::assertCount(1, $this->seen);
        self::assertFalse($this->seen[0]->hasHeader('Authorization'));
        self::assertFalse($this->seen[0]->hasHeader('Cookie'));
        self::assertSame('t-1', $this->seen[0]->getHeaderLine('X-Trace'));
    }

    public function testCapIsSentOnStatusWaitDownloadsAndEvents(): void
    {
        $client = $this->client();
        $client->createWorkflow(new WorkflowCreatePayload(jobs: []));
        $client->getWorkflowStatus(self::WORKFLOW_ID);
        $client->waitForWorkflow(self::WORKFLOW_ID, new WaitOptions(intervalMs: 0, timeoutMs: 1000));
        $client->getWorkflowDownloads(self::WORKFLOW_ID);
        $client->streamEvents(self::WORKFLOW_ID);

        $reads = \array_slice($this->seen, 1);
        self::assertCount(4, $reads);
        foreach ($reads as $request) {
            self::assertSame(self::CAP, $request->getHeaderLine('X-Workflow-Capability'));
        }
        self::assertSame('stream.example.com', $reads[3]->getUri()->getHost());
        $this->assertNoCredentialAndOnlyOpenEndpoints();
    }

    public function testExplicitCapabilityWinsAndUnknownWorkflowGetsNone(): void
    {
        $client = $this->client();
        $client->getWorkflowStatus(self::WORKFLOW_ID);
        $client->createWorkflow(new WorkflowCreatePayload(jobs: []));
        $client->getWorkflowStatus(self::WORKFLOW_ID, 'explicit');

        self::assertFalse($this->seen[0]->hasHeader('X-Workflow-Capability'));
        self::assertSame('explicit', $this->seen[2]->getHeaderLine('X-Workflow-Capability'));
    }

    public function testNoCapIsRememberedWhenCreateReturnsNone(): void
    {
        $this->createBody = self::anonymousCreateBody(null);
        $client = $this->client();
        $client->createWorkflow(new WorkflowCreatePayload(jobs: []));
        $client->getWorkflowStatus(self::WORKFLOW_ID);

        self::assertFalse($this->seen[1]->hasHeader('X-Workflow-Capability'));
    }

    public function testDerivedClientSharesTheCapStore(): void
    {
        $client = $this->client();
        $derived = $client->withPresetDefaults(\Gisl\Sdk\PresetDefaults::create());
        $client->createWorkflow(new WorkflowCreatePayload(jobs: []));
        $derived->getWorkflowStatus(self::WORKFLOW_ID);

        self::assertInstanceOf(GislAnonymousClient::class, $derived);
        self::assertSame(self::CAP, $this->seen[1]->getHeaderLine('X-Workflow-Capability'));
    }

    public function testOperationOutsideTheGuestRuleSurfacesTheApisRefusal(): void
    {
        // The guest rule is the API's (compression_api AnonymousOperationPolicy);
        // the SDK sends the create and surfaces the refusal.
        $this->createStatus = 403;
        $this->createBody = [
            'success' => false,
            'error' => 'ANONYMOUS_OPERATION_NOT_ALLOWED',
            'error_type' => 'anonymous_operation_not_allowed',
            'message' => 'This operation requires an account.',
            'operation' => 'text_watermark',
        ];

        try {
            $this->client()->operation('text_watermark', $this->tmpFile)->run();
            self::fail('Expected GislApiError');
        } catch (GislApiError $e) {
            self::assertSame(403, $e->statusCode);
            self::assertSame('ANONYMOUS_OPERATION_NOT_ALLOWED', $e->errorCode);
        }
        self::assertContains('POST /api/workflows', $this->paths());
    }

    public function testOversizeUploadSurfacesTierRestriction(): void
    {
        $factory = new HttpFactory();
        $client = Gisl::anonymous(
            baseUrl: self::BASE,
            httpClient: new class () implements ClientInterface {
                public function sendRequest(RequestInterface $request): ResponseInterface
                {
                    return new Response(403, ['Content-Type' => 'application/json'], (string) json_encode([
                        'success' => false,
                        'error' => 'TIER_RESTRICTION',
                        'error_type' => 'tier_restriction',
                        'message' => 'File size exceeds the 10 MiB limit for visitors without an account.',
                        'restriction_kind' => 'file_size',
                        // The API sends the base tier's canonical value for a guest.
                        'current_tier' => 'basic',
                        'required_tier' => null,
                    ]));
                }
            },
            requestFactory: $factory,
            streamFactory: $factory,
        );

        $this->expectException(GislTierRestrictedError::class);
        $client->uploadFile($this->tmpFile);
    }

    public function testErgonomicSugarOverAnAuthOnlyEndpointThrowsBeforeIo(): void
    {
        try {
            $this->client()->credits();
            self::fail('Expected GislFeatureRequiresAuthError');
        } catch (GislFeatureRequiresAuthError $e) {
            self::assertSame('getCreditsBalance', $e->operation);
        }
        self::assertSame([], $this->seen);
    }

    public function testMultipartResumeIsRefusedBeforeIo(): void
    {
        $this->expectException(GislFeatureRequiresAuthError::class);
        try {
            $this->client()->uploadFile($this->tmpFile, new UploadOptions(resumeUploadId: '019539ab-1111-7000-8000-000000000001'));
        } finally {
            self::assertSame([], $this->seen);
        }
    }

    public function testVideoProbeWaitIsANoOp(): void
    {
        $this->client()->maybeWaitForVideoProbe('019539ab-1111-7000-8000-000000000001', true, true, 500 * 1024 * 1024);
        self::assertSame([], $this->seen);
    }

    public function testProbePendingRecoveryMeetsTheGateInsteadOfProbing(): void
    {
        $this->createStatus = 422;
        $this->createBody = [
            'success' => false,
            'error' => 'PROBE_PENDING',
            'error_type' => 'probe_pending',
            'message' => 'probe pending',
            'job_ref' => 'job_0',
        ];
        $payload = new WorkflowCreatePayload(jobs: [
            new \Gisl\Sdk\JobDefinitionPayload(
                source: \Gisl\Sdk\Sources::upload('f1'),
                operations: [],
            ),
        ]);

        try {
            $this->client()->createWorkflowAwaitingProbe($payload, 1000);
            self::fail('Expected GislFeatureRequiresAuthError');
        } catch (GislFeatureRequiresAuthError $e) {
            self::assertSame('waitForProbe', $e->operation);
        }
        self::assertSame(['POST /api/workflows'], $this->paths());
    }

    // -----------------------------------------------------------------

    private function client(): GislAnonymousClient
    {
        $factory = new HttpFactory();
        return Gisl::anonymous(
            baseUrl: self::BASE,
            httpClient: $this->router(),
            requestFactory: $factory,
            streamFactory: $factory,
            streamBaseUrl: self::STREAM,
        );
    }

    private function router(): ClientInterface
    {
        $test = $this;
        return new class ($test) implements ClientInterface {
            public function __construct(private readonly GislAnonymousClientTest $test)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->test->route($request);
            }
        };
    }

    /** @internal Routing for the stub client above. */
    public function route(RequestInterface $request): ResponseInterface
    {
        $this->seen[] = $request;
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $wf = self::WORKFLOW_ID;

        return match (true) {
            $method === 'POST' && $path === '/api/uploads' => self::json(201, ['success' => true, 'data' => [
                'file_id' => self::FILE_ID,
                'original_name' => 'photo.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 3,
                'constraints_applied' => [
                    'max_size_bytes' => 10485760,
                    'max_duration_seconds' => null,
                    'processing_class_pre_assignment' => null,
                ],
            ]]),
            $method === 'POST' && $path === '/api/workflows' => self::json($this->createStatus, $this->createBody),
            $method === 'GET' && $path === "/api/workflows/{$wf}/status" => self::json(200, ['success' => true, 'data' => [
                'workflow_id' => $wf,
                'status' => 'completed',
                'created_at' => '2026-09-26T11:00:00Z',
                'updated_at' => '2026-09-26T11:00:30Z',
                'jobs' => [[
                    'job_id' => '01936fb3-0001-7000-8000-0000000070e3',
                    'ref' => 'op',
                    'status' => 'completed',
                    'operations' => [[
                        'id' => '01936fb4-0001-7000-8000-0000000070e4',
                        'type' => 'compress',
                        'status' => 'completed',
                        'progress' => 1.0,
                    ]],
                ]],
            ]]),
            $method === 'GET' && $path === "/api/workflows/{$wf}/downloads" => self::json(200, ['success' => true, 'data' => [
                'downloads' => [[
                    'job_id' => '01936fb3-0001-7000-8000-0000000070e3',
                    'ref' => 'op',
                    'files' => [[
                        'operation' => 'compress',
                        'operation_id' => '01936fb4-0001-7000-8000-0000000070e4',
                        'filename' => 'photo.webp',
                        'size_bytes' => 2,
                        'download_url' => 'https://cdn.example.com/photo.webp',
                    ]],
                ]],
            ]]),
            $method === 'GET' && $path === "/api/workflows/{$wf}/events" => new Response(
                200,
                ['Content-Type' => 'text/event-stream'],
                "event: workflow.completed\ndata: {\"status\":\"completed\"}\n\n",
            ),
            default => self::json(404, ['success' => false, 'error' => 'NOT_FOUND']),
        };
    }

    /** @return array<string, mixed> */
    private static function anonymousCreateBody(?string $cap): array
    {
        return ['success' => true, 'data' => [
            'workflow_id' => self::WORKFLOW_ID,
            'anonymous' => true,
            'status' => 'pending',
            'created_at' => '2026-09-26T11:00:00Z',
            'jobs' => [],
            'delivery_plan' => ['mode' => 'individual', 'selection_type' => 'terminal', 'outputs' => [], 'hidden_outputs' => []],
            'processing_plan' => ['jobs' => []],
            'warnings' => [],
            'cap' => $cap,
        ]];
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    /** @return list<string> */
    private function paths(): array
    {
        return \array_map(
            static fn (RequestInterface $r): string => $r->getMethod() . ' ' . $r->getUri()->getPath(),
            $this->seen,
        );
    }

    private function assertNoCredentialAndOnlyOpenEndpoints(): void
    {
        $opFile = (new \ReflectionClass(\Gisl\Generated\Operations\CompressMetadata::class))->getFileName();
        /** @var array{endpoints: array<string, array{auth: string}>} $availability */
        $availability = json_decode(
            (string) file_get_contents(\dirname((string) $opFile, 3) . '/availability/availability.json'),
            true,
        );
        foreach ($this->seen as $request) {
            self::assertFalse($request->hasHeader('Authorization'), 'a guest request carried Authorization');
            self::assertFalse($request->hasHeader('Cookie'), 'a guest request carried a Cookie');
            $path = $request->getUri()->getPath();
            $matched = null;
            foreach (\array_keys($availability['endpoints']) as $key) {
                [$method, $template] = explode(' ', $key, 2);
                $pattern = '#^' . preg_replace('/\{[^}]+\}/', '[^/]+', $template) . '$#';
                if ($method === $request->getMethod() && preg_match($pattern, $path) === 1) {
                    $matched = $key;
                    break;
                }
            }
            self::assertNotNull($matched, "{$request->getMethod()} {$path} matches no contract endpoint");
            self::assertNotSame('required', $availability['endpoints'][$matched]['auth']);
        }
    }
}
