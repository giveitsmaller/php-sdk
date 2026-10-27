<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Generated\OpenApi\Model\AuthenticatedIdentity;
use Gisl\Generated\OpenApi\Model\TierRestrictionResponse;
use Gisl\Generated\OpenApi\Model\UserTier;
use Gisl\Generated\OpenApi\ObjectSerializer;
use Gisl\Sdk\Errors\GislTierRestrictedError;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * kcA1ZRwW — contracts v2.236.0 makes `pay_as_you_go` the base tier; `basic`
 * and `free` are DEPRECATED spellings of it (removal `IloAPrH4` / `nD8fCPDy`).
 * Expand → migrate → contract: the api writes `pay_as_you_go` only once every
 * consumer reads it. This proves the READ side through the generated readers
 * and the typed error path, for all three spellings.
 *
 * ObjectSerializer::deserialize tolerates an unknown string enum value
 * (drJpKvXS), so a deserialize alone cannot fail on a missing case. The
 * re-serialize through sanitizeForSerialization DOES check
 * getAllowableEnumValues(), which is what makes the round trip a real proof.
 *
 * Mirrors `packages/typescript/tests/unit/user-tier-pay-as-you-go-read.test.ts`.
 */
#[CoversClass(GislClient::class)]
final class UserTierPayAsYouGoReadTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function spellingProvider(): iterable
    {
        yield 'pay_as_you_go (base tier)' => ['pay_as_you_go'];
        yield 'basic (deprecated spelling)' => ['basic'];
        yield 'free (deprecated spelling)' => ['free'];
    }

    #[DataProvider('spellingProvider')]
    public function testGeneratedUserTierAllowsTheSpelling(string $tier): void
    {
        self::assertContains($tier, UserTier::getAllowableEnumValues());
    }

    public function testSerializerRejectsATierOutsideTheEnum(): void
    {
        // Negative control for the round trip below: without it, a serializer
        // that stopped checking enums would pass every spelling for the wrong reason.
        $identity = $this->deserializeIdentity('guest');

        $this->expectException(\InvalidArgumentException::class);
        ObjectSerializer::sanitizeForSerialization($identity);
    }

    #[DataProvider('spellingProvider')]
    public function testAuthenticatedIdentityRoundTripsTheSpelling(string $tier): void
    {
        $identity = $this->deserializeIdentity($tier);

        self::assertSame($tier, $identity->getTier());
        $sanitized = (array) ObjectSerializer::sanitizeForSerialization($identity);
        self::assertSame($tier, $sanitized['tier']);
    }

    public function testTierRestrictedDispatchCarriesPayAsYouGo(): void
    {
        $client = new GislClient(
            config: new GislClientConfig(baseUrl: 'https://api.example.com', apiKey: 'sk_test'),
            httpClient: $this->stubClient(new Response(
                403,
                ['Content-Type' => 'application/json'],
                \json_encode([
                    'success' => false,
                    'error' => 'tier_restriction',
                    'message' => 'Your tier does not permit videos.',
                    'message_key' => 'errors.tier.mime_blocked',
                    'locale' => 'en-GB',
                    'message_params' => ['mime' => 'video/mp4'],
                    'error_type' => 'tier_restriction',
                    'restriction_kind' => 'mime_type',
                    'current_tier' => 'pay_as_you_go',
                    'required_tier' => 'pro',
                ], JSON_THROW_ON_ERROR),
            )),
            requestFactory: new HttpFactory(),
            streamFactory: new HttpFactory(),
        );

        try {
            $client->getWorkflowStatus('01936fb2-0000-7000-8000-0000000000ff');
            self::fail('Expected GislTierRestrictedError');
        } catch (GislTierRestrictedError $e) {
            self::assertInstanceOf(TierRestrictionResponse::class, $e->typedPayload);
            self::assertSame('pay_as_you_go', $e->typedPayload->getCurrentTier());
            self::assertSame('pro', $e->typedPayload->getRequiredTier());
        }
    }

    private function deserializeIdentity(string $tier): AuthenticatedIdentity
    {
        $identity = ObjectSerializer::deserialize((object) [
            'id' => 'u-1',
            'email' => 'a@example.com',
            'tier' => $tier,
            'email_verified' => true,
            'created_at' => '2026-10-16T00:00:00+00:00',
        ], AuthenticatedIdentity::class);
        self::assertInstanceOf(AuthenticatedIdentity::class, $identity);

        return $identity;
    }

    private function stubClient(ResponseInterface $response): ClientInterface
    {
        return new class ($response) implements ClientInterface {
            public function __construct(private ResponseInterface $response)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }
}
