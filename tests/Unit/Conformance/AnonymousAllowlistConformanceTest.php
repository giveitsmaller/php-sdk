<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Conformance;

use Gisl\Generated\Operations\CompressMetadata;
use Gisl\Sdk\Errors\GislFeatureRequiresAuthError;
use Gisl\Sdk\Gisl;
use Gisl\Sdk\GislAnonymousClient;
use Gisl\Sdk\GislClient;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * OuegCUtq — the anonymous allowlist is PINNED TO THE CONTRACT, in both
 * directions (owner decision 610(4): the API is the source of truth). PHP arm
 * of the TS `anonymous-allowlist-conformance.test.ts`; the tables below are
 * the same.
 *
 *   (a) every allowlisted client method reaches ONLY endpoints the vendored
 *       `availability.json` marks `auth: optional` or `anonymous`;
 *   (b) every endpoint the contract marks non-`required` is reached by an
 *       allowlisted method or named in ENDPOINT_EXCLUSIONS with a reason, and
 *       every EXCLUDED method still reaches a `required` endpoint unless it is
 *       a named policy exclusion.
 *
 * Plus the structural half TS gets from its type system: every public
 * {@see GislClient} method is either allowlisted or overridden to throw in
 * {@see GislAnonymousClient} — a method nobody classified fails here.
 */
#[CoversClass(Gisl::class)]
#[CoversClass(GislAnonymousClient::class)]
final class AnonymousAllowlistConformanceTest extends TestCase
{
    /**
     * Every endpoint each allowlisted method can reach ON AN ANONYMOUS CLIENT.
     * See {@see Gisl::ANONYMOUS_ALLOWLIST} for why multipart is here, why the
     * probe wait reaches nothing, and why the resume path is not reachable.
     */
    private const METHOD_ENDPOINTS = [
        'uploadFile' => ['POST /api/uploads'],
        'getMetadata' => ['GET /api/uploads/{id}/metadata'],
        'createWorkflow' => ['POST /api/workflows'],
        'createWorkflowAwaitingProbe' => ['POST /api/workflows'],
        'getWorkflowStatus' => ['GET /api/workflows/{id}/status'],
        'waitForWorkflow' => ['GET /api/workflows/{id}/status'],
        'getWorkflowDownloads' => ['GET /api/workflows/{id}/downloads'],
        'streamEvents' => ['GET /api/workflows/{id}/events'],
        'getSchema' => ['GET /api/operations/schema'],
        'submitContact' => ['POST /api/contact'],
        'maybeWaitForVideoProbe' => [],
    ];

    /** Every public method NOT on the allowlist => [endpoints, policy reason or null]. */
    private const EXCLUDED_METHODS = [
        'getUploadStatus' => [['GET /api/uploads/multipart/{uploadId}/status'], null],
        'presignParts' => [['POST /api/uploads/multipart/{uploadId}/presign'], null],
        'keepaliveUpload' => [['POST /api/uploads/multipart/{uploadId}/keepalive'], null],
        'cancelWorkflow' => [['POST /api/workflows/{id}/cancel'], null],
        'archiveWorkflow' => [['POST /api/workflows/{id}/archive'], null],
        'restoreWorkflow' => [['POST /api/workflows/{id}/restore'], null],
        'resumeWorkflow' => [['POST /api/workflows/{id}/resume'], null],
        'listWorkflows' => [['GET /api/workflows'], null],
        'workflows' => [['GET /api/workflows'], null],
        'createCheckoutSession' => [['POST /api/billing/checkout'], null],
        'getCreditsBalance' => [['GET /api/v2/credits/balance'], null],
        'getCreditsUsage' => [['GET /api/v2/credits/usage'], null],
        'getAccountLimits' => [['GET /api/v2/account/limits'], null],
        'getProfile' => [['GET /api/auth/profile'], null],
        'logout' => [['POST /api/auth/logout'], null],
        'createExternalImport' => [['POST /api/external-imports'], null],
        'decodeAudioWatermark' => [['POST /api/audio-watermark/decode'], null],
        'probeUpload' => [['POST /api/uploads/{id}/probe'], null],
        'waitForProbe' => [['POST /api/uploads/{id}/probe'], null],
        'preflightClips' => [['POST /api/uploads/{id}/probe'], null],
        'retryOperation' => [['POST /api/operations/{id}/retry'], self::API_REQUIRES_AUTH_RETRY],
        'login' => [
            ['POST /api/auth/login'],
            'The endpoint accepts guests, but logging in turns the client into a session client. '
            . 'An anonymous client carries no credential; use Gisl::create(useSessionCookie: true).',
        ],
    ];

    /*
     * ⚠️ CONTRACT-VS-API DISAGREEMENTS. The contract marks these `optional`,
     * but the API requires authentication — measured 2026-09-26 in
     * compression_api `compression/config/packages/security.yaml`
     * access_control (origin/main 566d3350): `^/api/uploads/multipart/initiate$`
     * and `^/api/operations/[^/]+/retry$` are `IS_AUTHENTICATED_FULLY`. The API
     * is the source of truth for what a guest may do. Delete these exclusions
     * when the contract is corrected; the stale-exclusion check goes red on its
     * own once it is.
     */
    private const API_REQUIRES_AUTH_MULTIPART = 'contract says optional, but the API requires auth on multipart '
        . 'initiate (security.yaml IS_AUTHENTICATED_FULLY), so a guest cannot start a multipart upload and '
        . 'complete is unreachable';
    private const API_REQUIRES_AUTH_RETRY = 'contract says optional, but the API requires auth on retry '
        . '(security.yaml IS_AUTHENTICATED_FULLY)';

    /** Non-`required` endpoints no allowlisted method reaches, and why. */
    private const ENDPOINT_EXCLUSIONS = [
        'POST /api/uploads/multipart/initiate' => self::API_REQUIRES_AUTH_MULTIPART,
        'POST /api/uploads/multipart/complete' => self::API_REQUIRES_AUTH_MULTIPART,
        'POST /api/operations/{id}/retry' => self::API_REQUIRES_AUTH_RETRY,
        'GET /healthz' => 'infrastructure probe; the SDK has no method for it',
        'GET /readyz' => 'infrastructure probe; the SDK has no method for it',
        'POST /api/auth/login' => 'policy exclusion: see EXCLUDED_METHODS login',
        'POST /api/auth/register' => 'account lifecycle; the SDK has no method for it',
        'POST /api/auth/verify-email' => 'account lifecycle; the SDK has no method for it',
        'POST /api/auth/resend-verification' => 'account lifecycle; the SDK has no method for it',
        'POST /api/auth/forgot-password' => 'account lifecycle; the SDK has no method for it',
        'POST /api/auth/reset-password' => 'account lifecycle; the SDK has no method for it',
        'POST /api/auth/confirm-email-change' => 'account lifecycle; the SDK has no method for it',
    ];

    /** @var array{endpoints: array<string, array{auth: string}>} */
    private static array $availability;

    public static function setUpBeforeClass(): void
    {
        // operations/src/CompressMetadata.php -> dirname x3 = generated/php root.
        $opFile = (new \ReflectionClass(CompressMetadata::class))->getFileName();
        $root = \dirname((string) $opFile, 3);
        /** @var array{endpoints: array<string, array{auth: string}>} $decoded */
        $decoded = \json_decode((string) \file_get_contents($root . '/availability/availability.json'), true);
        self::$availability = $decoded;
    }

    public function testReadsAPopulatedEndpointTable(): void
    {
        // Positive control: every check below passes vacuously on an empty table.
        $open = \array_filter(self::$availability['endpoints'], static fn (array $e): bool => $e['auth'] !== 'required');
        self::assertGreaterThan(20, \count(self::$availability['endpoints']));
        self::assertGreaterThan(5, \count($open));
    }

    public function testAllowlistAndEndpointTableNameTheSameMethods(): void
    {
        $allowlist = Gisl::ANONYMOUS_ALLOWLIST;
        $table = \array_keys(self::METHOD_ENDPOINTS);
        \sort($allowlist);
        \sort($table);
        self::assertSame($table, $allowlist);
    }

    public function testEveryPublicClientMethodIsClassifiedAndGated(): void
    {
        $classified = \array_merge(\array_keys(self::METHOD_ENDPOINTS), \array_keys(self::EXCLUDED_METHODS));
        $anonymous = new \ReflectionClass(GislAnonymousClient::class);
        $public = [];
        foreach ((new \ReflectionClass(GislClient::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor()) {
                continue;
            }
            $public[] = $method->getName();
        }
        \sort($public);
        \sort($classified);
        self::assertSame($public, $classified, 'every public GislClient method must be allowlisted or excluded');

        foreach (\array_keys(self::EXCLUDED_METHODS) as $name) {
            self::assertSame(
                GislAnonymousClient::class,
                $anonymous->getMethod($name)->getDeclaringClass()->getName(),
                "{$name} is excluded from the anonymous allowlist but GislAnonymousClient does not override it, "
                . 'so a guest would reach it ungated.',
            );
        }
    }

    public function testHoldsInBothDirectionsAgainstTheVendoredContract(): void
    {
        self::assertSame([], self::check(self::$availability));
    }

    public function testPositiveControlAllowlistedMethodReachingRequired(): void
    {
        $doctored = self::$availability;
        $doctored['endpoints']['POST /api/workflows']['auth'] = 'required';
        self::assertContains(['allowlisted_reaches_required', 'createWorkflow -> POST /api/workflows'], self::check($doctored));
    }

    public function testPositiveControlUncoveredOpenEndpoint(): void
    {
        $doctored = self::$availability;
        $doctored['endpoints']['GET /api/workflows/{id}/new-thing'] = ['auth' => 'optional'];
        self::assertContains(['uncovered_endpoint', 'GET /api/workflows/{id}/new-thing'], self::check($doctored));
    }

    public function testPositiveControlExcludedMethodThatOpened(): void
    {
        $doctored = self::$availability;
        $doctored['endpoints']['POST /api/workflows/{id}/cancel']['auth'] = 'optional';
        self::assertContains(['excluded_but_open', 'cancelWorkflow'], self::check($doctored));
    }

    public function testPositiveControlRemovedEndpoint(): void
    {
        $doctored = self::$availability;
        unset($doctored['endpoints']['GET /api/workflows/{id}/status']);
        self::assertContains(['unknown_endpoint', 'getWorkflowStatus -> GET /api/workflows/{id}/status'], self::check($doctored));
    }

    public function testPositiveControlStaleExclusion(): void
    {
        $doctored = self::$availability;
        $doctored['endpoints']['POST /api/auth/register']['auth'] = 'required';
        self::assertContains(['stale_exclusion', 'POST /api/auth/register'], self::check($doctored));
    }

    /**
     * Every excluded method throws before any I/O, driven from the table the
     * structural test proves complete.
     */
    #[DataProvider('excludedMethods')]
    public function testExcludedMethodThrowsBeforeAnyIo(string $method): void
    {
        $factory = new HttpFactory();
        $client = Gisl::anonymous(
            baseUrl: 'https://api.example.com',
            httpClient: new class () implements ClientInterface {
                public function sendRequest(RequestInterface $request): ResponseInterface
                {
                    throw new class ('an anonymous gate let a request through') extends \RuntimeException implements ClientExceptionInterface {};
                }
            },
            requestFactory: $factory,
            streamFactory: $factory,
        );
        $reflection = new \ReflectionMethod($client, $method);
        $args = [];
        foreach ($reflection->getParameters() as $parameter) {
            if ($parameter->isOptional()) {
                break;
            }
            $args[] = self::dummyFor($parameter);
        }

        try {
            $result = $reflection->invokeArgs($client, $args);
            if ($result instanceof \Generator) {
                $result->current();
            }
            self::fail("{$method} did not throw on an anonymous client");
        } catch (GislFeatureRequiresAuthError $e) {
            self::assertSame($method, $e->operation);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function excludedMethods(): iterable
    {
        foreach (\array_keys(self::EXCLUDED_METHODS) as $name) {
            yield $name => [$name];
        }
    }

    private static function dummyFor(\ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();
        $name = $type instanceof \ReflectionNamedType ? $type->getName() : 'string';
        return match (true) {
            $name === 'string' => '019539ab-1111-7000-8000-000000000001',
            $name === 'int' => 2,
            $name === 'array' => [],
            $name === 'bool' => false,
            \class_exists($name) => (new \ReflectionClass($name))->newInstanceWithoutConstructor(),
            default => null,
        };
    }

    /**
     * @param array{endpoints: array<string, array{auth: string}>} $availability
     * @return list<array{string, string}>
     */
    private static function check(array $availability): array
    {
        $endpoints = $availability['endpoints'];
        $authOf = static fn (string $endpoint): ?string => $endpoints[$endpoint]['auth'] ?? null;
        $violations = [];

        $reached = [];
        foreach (self::METHOD_ENDPOINTS as $method => $methodEndpoints) {
            foreach ($methodEndpoints as $endpoint) {
                $reached[$endpoint] = true;
                $auth = $authOf($endpoint);
                if ($auth === null) {
                    $violations[] = ['unknown_endpoint', "{$method} -> {$endpoint}"];
                } elseif ($auth === 'required') {
                    $violations[] = ['allowlisted_reaches_required', "{$method} -> {$endpoint}"];
                }
            }
        }
        foreach ($endpoints as $endpoint => $entry) {
            if ($entry['auth'] !== 'required' && !isset($reached[$endpoint]) && !isset(self::ENDPOINT_EXCLUSIONS[$endpoint])) {
                $violations[] = ['uncovered_endpoint', $endpoint];
            }
        }
        foreach (\array_keys(self::ENDPOINT_EXCLUSIONS) as $endpoint) {
            $auth = $authOf($endpoint);
            if ($auth === null || $auth === 'required') {
                $violations[] = ['stale_exclusion', $endpoint];
            }
        }
        foreach (self::EXCLUDED_METHODS as $method => [$methodEndpoints, $policy]) {
            $allOpen = true;
            foreach ($methodEndpoints as $endpoint) {
                $auth = $authOf($endpoint);
                if ($auth === null) {
                    $violations[] = ['unknown_endpoint', "{$method} -> {$endpoint}"];
                }
                if ($auth === null || $auth === 'required') {
                    $allOpen = false;
                }
            }
            if ($allOpen && $policy === null) {
                $violations[] = ['excluded_but_open', $method];
            }
        }
        return $violations;
    }
}
