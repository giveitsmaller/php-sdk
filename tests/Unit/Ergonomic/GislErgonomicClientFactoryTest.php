<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Ergonomic;

use Gisl\Sdk\Ergonomic\OperationBuilder;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(GislErgonomicClient::class)]
final class GislErgonomicClientFactoryTest extends TestCase
{
    private function makeClient(): GislErgonomicClient
    {
        $factory = new HttpFactory();
        $http = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new \LogicException('Builder factories must not perform I/O.');
            }
        };
        return new GislErgonomicClient(
            config: new GislClientConfig(baseUrl: 'https://api.test', apiKey: 'sk_test'),
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
        );
    }

    public function test_subclasses_gisl_client(): void
    {
        $client = $this->makeClient();
        $this->assertInstanceOf(GislClient::class, $client);
    }

    /**
     * Each verb paired with a VALID option bag (ExVcchMz — convert/thumbnail now
     * validate pre-upload, so the generic `['quality' => 80]` bag no longer works
     * for thumbnail (needs width+height) or convert (needs output_format)).
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function ergonomicVerbProvider(): array
    {
        return [
            'compress' => ['compress', ['quality' => 80]],
            'thumbnail' => ['thumbnail', ['width' => 320, 'height' => 240]],
            'convert' => ['convert', ['output_format' => 'webp', 'quality' => 80]],
        ];
    }

    /**
     * `watermark` and `archive` are deliberately NOT factory methods on
     * `GislErgonomicClient` — see the class docblock for why. Pin the
     * absence so a future re-introduction trips this test alongside
     * proper preset/multi-input wiring.
     */
    public function test_watermark_and_archive_are_not_factories_yet(): void
    {
        $client = $this->makeClient();
        $this->assertFalse(\method_exists($client, 'watermark'));
        $this->assertFalse(\method_exists($client, 'archive'));
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('ergonomicVerbProvider')]
    public function test_factory_returns_operation_builder(string $verb, array $options): void
    {
        $client = $this->makeClient();
        /** @var OperationBuilder $builder */
        $builder = $client->$verb('/tmp/anywhere.bin', $options);
        $this->assertInstanceOf(OperationBuilder::class, $builder);
    }

    /**
     * Pin the builder's captured (verb, input, options) tuple via
     * reflection. Cheap regression guard against an accidental rename
     * of `opType` or argument-shuffle in the factory.
     */
    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('ergonomicVerbProvider')]
    public function test_factory_captures_verb_and_options(string $verb, array $options): void
    {
        $client = $this->makeClient();
        $opts = $options;
        $builder = $client->$verb('/tmp/some.bin', $opts);

        $reflection = new \ReflectionObject($builder);

        // PHP 8.1+ readonly props are readable via reflection without
        // setAccessible (which is deprecated as a no-op in PHP 8.5+).
        $this->assertSame($verb, $reflection->getProperty('opType')->getValue($builder));
        $this->assertSame('/tmp/some.bin', $reflection->getProperty('input')->getValue($builder));
        $this->assertSame($opts, $reflection->getProperty('opOptions')->getValue($builder));
    }

    public function test_default_options_is_empty_array(): void
    {
        $client = $this->makeClient();
        $builder = $client->compress('/tmp/x');
        $reflection = new \ReflectionObject($builder);
        $this->assertSame([], $reflection->getProperty('opOptions')->getValue($builder));
    }
}
