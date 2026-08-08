<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Infrastructure\Connector\Ozon;

use App\Ingestion\Infrastructure\Connector\Ozon\OzonPostingFboListParser;
use App\Shared\Domain\ValueObject\Money;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class OzonPostingFboListParserTest extends TestCase
{
    private const string FIXTURE = __DIR__.'/../../../../../Fixtures/Marketplace/ozon/posting-fbo-list-2026-07-01.json';

    public function testParsesEveryProductLineInTheFixture(): void
    {
        $fixtureBody = file_get_contents(self::FIXTURE);
        self::assertIsString($fixtureBody);

        $decoded = json_decode($fixtureBody, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['result']);

        $expectedCount = 0;
        foreach ($decoded['result'] as $posting) {
            self::assertIsArray($posting);
            self::assertIsArray($posting['products']);
            $expectedCount += \count($posting['products']);
        }

        $facts = (new OzonPostingFboListParser())->parse($fixtureBody, Uuid::v7(), Uuid::v7(), Uuid::v7());

        self::assertSame($expectedCount, \count($facts));
    }

    public function testMapsCancelledPostingFields(): void
    {
        $fixtureBody = file_get_contents(self::FIXTURE);
        self::assertIsString($fixtureBody);

        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $rawDocumentId = Uuid::v7();

        $facts = (new OzonPostingFboListParser())->parse($fixtureBody, $companyId, $accountId, $rawDocumentId);

        $fact = $facts[0];

        self::assertSame($companyId, $fact->companyId());
        self::assertSame($accountId, $fact->marketplaceAccountId());
        self::assertSame($rawDocumentId, $fact->rawDocumentId());
        // Конкатенация posting_number|sku (ADR-009).
        self::assertSame('40705738-0407-1|4404411581', $fact->sourceRowId());
        self::assertSame('cancelled', $fact->status());
        self::assertSame('4404411581', $fact->marketplaceSku());
        self::assertSame(1, $fact->quantity());
        // in_process_at = 2026-06-30T21:01:11Z — в Europe/Moscow (+03:00)
        // это уже 2026-07-01: граница суток пересечена, бизнес-дата
        // считается в часовом поясе площадки, не в UTC (ADR-009).
        self::assertSame('2026-07-01', $fact->businessDate()->format('Y-m-d'));
        self::assertEquals(Money::ofMinor(216_000, 'RUB'), $fact->amount());
        self::assertEquals(Money::ofMinor(0, 'RUB'), $fact->commissionAmount());
    }

    public function testMapsNegativeCommissionWithoutFloatPrecisionLoss(): void
    {
        $fixtureBody = file_get_contents(self::FIXTURE);
        self::assertIsString($fixtureBody);

        $facts = (new OzonPostingFboListParser())->parse($fixtureBody, Uuid::v7(), Uuid::v7(), Uuid::v7());

        $fact = $facts[1];

        self::assertSame('delivered', $fact->status());
        self::assertSame('81246442-0476-1|308520421', $fact->sourceRowId());
        self::assertEquals(Money::ofMinor(240_200, 'RUB'), $fact->amount());
        // -1152.96 руб. -> -115296 копеек, без размытия домножением float.
        self::assertEquals(Money::ofMinor(-115_296, 'RUB'), $fact->commissionAmount());
    }

    public function testThrowsOnMissingResultKey(): void
    {
        $parser = new OzonPostingFboListParser();

        $this->expectException(\UnexpectedValueException::class);

        $parser->parse('{"foo":"bar"}', Uuid::v7(), Uuid::v7(), Uuid::v7());
    }

    public function testThrowsWhenProductHasNoMatchingFinancialData(): void
    {
        $parser = new OzonPostingFboListParser();
        $body = json_encode([
            'result' => [[
                'posting_number' => 'P-1',
                'status' => 'delivered',
                'in_process_at' => '2026-07-01T00:00:00Z',
                'products' => [['sku' => 111, 'quantity' => 1]],
                'financial_data' => ['products' => []],
            ]],
        ], \JSON_THROW_ON_ERROR);

        $this->expectException(\UnexpectedValueException::class);

        $parser->parse($body, Uuid::v7(), Uuid::v7(), Uuid::v7());
    }
}
