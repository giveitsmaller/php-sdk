<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Ergonomic;

use Gisl\Generated\OpenApi\Model\ImageEncodeCapabilities;
use Gisl\Generated\OpenApi\Model\OperationCapability;
use Gisl\Generated\OpenApi\Model\OutputProperties;
use Gisl\Sdk\Ergonomic\CapabilitiesSnapshot;
use Gisl\Sdk\Ergonomic\SubmitOptions;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Unit coverage for qUhxfDA5 — the ergonomic `capabilities()` read-projection
 * over `getSchema()` and the generic `operation()` escape-hatch verb. Mirrors
 * the TS coverage in `packages/typescript/tests/unit/gisl.test.ts`.
 */
#[CoversClass(GislErgonomicClient::class)]
#[CoversClass(CapabilitiesSnapshot::class)]
final class CapabilitiesOperationTest extends TestCase
{
    private HttpFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
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
             * @param list<ResponseInterface>   $queue
             * @param list<RequestInterface>    $captured
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

    /**
     * @param array<string, mixed> $body
     */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], \json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function makeClient(ClientInterface $http): GislErgonomicClient
    {
        return new GislErgonomicClient(
            config: new GislClientConfig(baseUrl: 'https://api.example.com', apiKey: 'sk_test', multipartConcurrency: 1),
            httpClient: $http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    /**
     * The /api/operations/schema body is NOT enveloped — it IS the
     * OperationsSchemaResponse. Includes the three v2.124 capability fields.
     *
     * @return array<string, mixed>
     */
    private function fullSchemaBody(): array
    {
        return [
            'schema_version' => '2.6.0',
            'capabilities_version' => 25,
            'generated_at' => '2026-07-01T12:00:00Z',
            'operations' => new \stdClass(),
            'capabilities' => [
                'compress' => ['accepts' => ['image/jpeg'], 'availability' => 'stable', 'sole_op' => false],
                'text_watermark' => ['accepts' => ['image/png'], 'availability' => 'stable'],
            ],
            'output_properties' => [
                'webp' => ['has_audio_track' => true, 'is_animated' => 'maybe'],
            ],
            'image_encode_capabilities' => ['webp_quality_supported' => true, 'background_flatten' => 'supported'],
        ];
    }

    public function testCapabilitiesProjectsTheThreeFieldsIntoASnapshot(): void
    {
        $http = $this->stubClient([$this->jsonResponse(200, $this->fullSchemaBody())]);
        $client = $this->makeClient($http);

        $snapshot = $client->capabilities();

        self::assertInstanceOf(CapabilitiesSnapshot::class, $snapshot);
        // operations: tier-scoped matrix, keyed by op type, deep-hydrated.
        self::assertSame(['compress', 'text_watermark'], \array_keys($snapshot->operations));
        self::assertInstanceOf(OperationCapability::class, $snapshot->operations['compress']);
        self::assertFalse($snapshot->operations['compress']->getSoleOp());
        self::assertSame(['image/jpeg'], $snapshot->operations['compress']->getAccepts());
        // outputProperties (keyed by output_format) + imageEncode surfaced as the
        // typed contract models. Deep field values are the generated
        // ObjectSerializer's concern — this test pins that capabilities() projects
        // the getSchema() fields as the right types, not that model hydration is
        // correct (covered by the generated layer).
        self::assertArrayHasKey('webp', $snapshot->outputProperties);
        self::assertInstanceOf(OutputProperties::class, $snapshot->outputProperties['webp']);
        self::assertInstanceOf(ImageEncodeCapabilities::class, $snapshot->imageEncode);
    }

    public function testCapabilitiesWithOpTypeReturnsThatOpsCapability(): void
    {
        $http = $this->stubClient([$this->jsonResponse(200, $this->fullSchemaBody())]);
        $client = $this->makeClient($http);

        $cap = $client->capabilities('compress');

        self::assertInstanceOf(OperationCapability::class, $cap);
        self::assertSame('stable', $cap->getAvailability());
        self::assertFalse($cap->getSoleOp());
    }

    public function testCapabilitiesWithUnknownOpTypeReturnsNull(): void
    {
        $http = $this->stubClient([$this->jsonResponse(200, $this->fullSchemaBody())]);
        $client = $this->makeClient($http);

        self::assertNull($client->capabilities('no_such_op'));
    }

    public function testCapabilitiesReturnsEmptyMapsWhenServerOmitsTheFields(): void
    {
        $http = $this->stubClient([$this->jsonResponse(200, [
            'schema_version' => '2.6.0',
            'capabilities_version' => 25,
            'generated_at' => '2026-07-01T12:00:00Z',
            'operations' => new \stdClass(),
        ])]);
        $client = $this->makeClient($http);

        $snapshot = $client->capabilities();

        self::assertSame([], $snapshot->operations);
        self::assertSame([], $snapshot->outputProperties);
        self::assertNull($snapshot->imageEncode);
    }

    public function testOperationBuildsASingleOpJobCarryingOpTypeAndOptionsOnTheWire(): void
    {
        $tempPath = $this->writeTempFile('input bytes');
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->uploadOk()),
            $this->jsonResponse(201, $this->createOk()),
        ], $captured);
        $client = $this->makeClient($http);

        try {
            $client
                ->operation('text_watermark', $tempPath, ['text' => 'CONFIDENTIAL', 'position' => 'bottom-right'])
                ->submit(new SubmitOptions(webhook: 'https://example.com/hook'));
        } finally {
            @\unlink($tempPath);
        }

        // upload + create = 2 requests; the 2nd is createWorkflow.
        self::assertCount(2, $captured);
        self::assertStringContainsString('/api/workflows', (string) $captured[1]->getUri());
        $body = \json_decode((string) $captured[1]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertCount(1, $body['jobs']);
        self::assertSame(
            [['type' => 'text_watermark', 'options' => ['text' => 'CONFIDENTIAL', 'position' => 'bottom-right']]],
            $body['jobs'][0]['operations'],
        );
        // The uploaded file_id threads into source (not just the type).
        self::assertSame(
            ['type' => 'upload', 'file_id' => '01936fb1-7bb3-7000-8000-000000000010'],
            $body['jobs'][0]['source'],
        );
    }

    public function testOperationPassesOptionsThroughForANotYetInContractOpType(): void
    {
        $tempPath = $this->writeTempFile('input bytes');
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->uploadOk()),
            $this->jsonResponse(201, $this->createOk()),
        ], $captured);
        $client = $this->makeClient($http);

        try {
            $client
                ->operation('some_future_op', $tempPath, ['foo' => 'bar', 'n' => 1])
                ->submit(new SubmitOptions(webhook: 'https://example.com/hook'));
        } finally {
            @\unlink($tempPath);
        }

        $body = \json_decode((string) $captured[1]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(
            [['type' => 'some_future_op', 'options' => ['foo' => 'bar', 'n' => 1]]],
            $body['jobs'][0]['operations'],
        );
    }

    public function testCapabilitiesDegradesToEmptyOn304NotModified(): void
    {
        // Defensive branch: a 304 (no body) surfaces as GetSchemaNotModifiedResult,
        // which capabilities() degrades to an empty projection rather than throwing.
        $http = $this->stubClient([
            new Response(304, ['ETag' => '"v"'], ''),
            new Response(304, ['ETag' => '"v"'], ''),
        ]);
        $client = $this->makeClient($http);

        $snapshot = $client->capabilities();
        self::assertSame([], $snapshot->operations);
        self::assertSame([], $snapshot->outputProperties);
        self::assertNull($snapshot->imageEncode);

        self::assertNull($client->capabilities('compress'));
    }

    private function writeTempFile(string $bytes): string
    {
        $dir = \sys_get_temp_dir() . '/gisl-cap-op-' . \bin2hex(\random_bytes(6));
        \mkdir($dir, 0700, true);
        $path = $dir . '/fixture.bin';
        \file_put_contents($path, $bytes);
        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadOk(): array
    {
        return [
            'success' => true,
            'data' => [
                'file_id' => '01936fb1-7bb3-7000-8000-000000000010',
                'original_name' => 'fixture.bin',
                'mime_type' => 'application/octet-stream',
                'size_bytes' => 11,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createOk(): array
    {
        return [
            'success' => true,
            'data' => [
                'workflow_id' => '01936fb2-0000-7000-8000-0000000000d1',
                'status' => 'pending',
                'created_at' => '2026-07-01T11:00:00Z',
                'jobs' => [],
            ],
        ];
    }
}
