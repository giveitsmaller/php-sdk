<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Generated\OpenApi\Model\CreditQuote;
use Gisl\Generated\OpenApi\Model\CreditQuoteLine;
use Gisl\Generated\OpenApi\ObjectSerializer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * 5Bhk3c7Q — a quote whose line ids look like `main/0` hydrates.
 *
 * openapi-generator rewrites a star followed by a slash to star-underscore-slash
 * as a docblock guard, inside regex literals too, so CreditQuoteLine required
 * `main_/0` and rejected every real line id. scripts/generate.py restores the
 * spec pattern. Hydration goes through setLineId(), which holds its own copy of
 * the regex beside listInvalidProperties(), so both are exercised here.
 */
#[CoversNothing]
final class GeneratedCreditQuoteHydrationTest extends TestCase
{
    private const LINE = [
        'line_id' => 'main/0',
        'job_ref' => 'main',
        'operation_index' => 0,
        'operation_type' => 'compress',
        'job_id' => '01936fb1-7bb3-7000-8000-000000000020',
        'operation_id' => '01936fb1-7bb3-7000-8000-000000000021',
        'max_credits' => 10,
        'state' => 'held',
        'charged_credits' => 0,
        'released_credits' => 0,
    ];

    public function testAQuoteWhoseLineIdCarriesAnOperationIndexHydratesAndIsValid(): void
    {
        $quote = ObjectSerializer::deserialize(
            (object) [
                'quote_id' => '01936fb1-7bb3-7000-8000-000000000030',
                'quote_version' => 1,
                'workflow_id' => '01936fb1-7bb3-7000-8000-000000000031',
                'status' => 'open',
                'max_hold_credits' => 10,
                'held_credits' => 10,
                'charged_credits' => 0,
                'released_credits' => 0,
                'final' => false,
                'settlement_pending' => false,
                'pricing_version' => '2026-10',
                'lines' => [(object) self::LINE],
            ],
            CreditQuote::class,
        );

        self::assertInstanceOf(CreditQuote::class, $quote);
        self::assertTrue($quote->valid(), \implode('; ', $quote->listInvalidProperties()));
        $line = $quote->getLines()[0];
        self::assertSame('main/0', $line->getLineId());
        self::assertTrue($line->valid(), \implode('; ', $line->listInvalidProperties()));
    }

    public function testAMalformedLineIdIsRejectedOnHydration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid value for $line_id');

        ObjectSerializer::deserialize((object) (['line_id' => 'main/01'] + self::LINE), CreditQuoteLine::class);
    }
}
