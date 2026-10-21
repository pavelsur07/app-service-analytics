<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Domain;

use App\Ingestion\Domain\OzonPostingFboListParser;
use App\Ingestion\Domain\SalesFact;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\SalesFactBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class OzonPostingFboListParserTest extends TestCase
{
    private const string FIXTURE = __DIR__.'/../../../Fixtures/Marketplace/ozon/posting-fbo-list-2026-07-01.json';
    private const string FIXTURE_V3 = __DIR__.'/../../../Fixtures/Marketplace/ozon/posting-fbo-list-v3-2026-07-01-page-001.json';
    private const string BUYOUT_FIXTURE = __DIR__.'/../../../Fixtures/Marketplace/ozon/ozon-buyout-posting-statuses-after.json';

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

    /**
     * Главный тест перехода на v3 (ADR-042): тот же день, снятый обеими
     * версиями метода, даёт те же факты поле в поле. Иначе перезагрузка
     * окна после перехода молча поменяла бы цифры или ключи.
     */
    public function testV3BodyYieldsTheSameFactsAsV2ForTheSameDay(): void
    {
        $v2 = file_get_contents(self::FIXTURE);
        $v3 = file_get_contents(self::FIXTURE_V3);
        self::assertIsString($v2);
        self::assertIsString($v3);
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $parser = new OzonPostingFboListParser();

        $fromV2 = array_map(self::comparable(...), $parser->parse($v2, $companyId, $accountId, Uuid::v7()));
        $fromV3 = array_map(self::comparable(...), $parser->parse($v3, $companyId, $accountId, Uuid::v7()));
        usort($fromV2, static fn (array $a, array $b): int => $a['sourceRowId'] <=> $b['sourceRowId']);
        usort($fromV3, static fn (array $a, array $b): int => $a['sourceRowId'] <=> $b['sourceRowId']);

        self::assertCount(86, $fromV3);
        self::assertSame($fromV2, $fromV3);
    }

    public function testRefusesV3CommissionInAnotherCurrency(): void
    {
        $body = json_encode(['postings' => [[
            'posting_number' => 'P-USD-1',
            'order_number' => 'P-USD',
            'status' => 'delivered',
            'in_process_at' => '2026-10-19T09:00:00Z',
            'products' => [['sku' => 111, 'quantity' => 1]],
            'financial_data' => ['products' => [
                ['product_id' => 111, 'price' => 100, 'commission' => ['amount' => -10, 'percent' => 10, 'currency' => 'USD']],
            ]],
        ]], 'has_next' => false, 'cursor' => ''], \JSON_THROW_ON_ERROR);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('commission currency USD');

        (new OzonPostingFboListParser())->parse($body, Uuid::v7(), Uuid::v7(), Uuid::v7());
    }

    public function testRefusesBodyWithoutPostingList(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        (new OzonPostingFboListParser())->parse('{"code":9,"message":"obsolete method cannot be used"}', Uuid::v7(), Uuid::v7(), Uuid::v7());
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

    public function testMapsDeliveryAttributesFromAnalyticsData(): void
    {
        $fixtureBody = file_get_contents(self::FIXTURE);
        self::assertIsString($fixtureBody);

        $facts = (new OzonPostingFboListParser())->parse($fixtureBody, Uuid::v7(), Uuid::v7(), Uuid::v7());

        self::assertSame(1020000115166000, $facts[0]->warehouseId());
        self::assertSame('ЖУКОВСКИЙ_РФЦ', $facts[0]->warehouseName());
        self::assertSame('Брянск', $facts[0]->deliveryCity());
        self::assertSame('ОМСК_РФЦ', $facts[1]->warehouseName());
        self::assertSame('Комсомольск-на-Амуре', $facts[1]->deliveryCity());
    }

    public function testMapsClustersFromFinancialData(): void
    {
        $fixtureBody = file_get_contents(self::FIXTURE);
        self::assertIsString($fixtureBody);

        $facts = (new OzonPostingFboListParser())->parse($fixtureBody, Uuid::v7(), Uuid::v7(), Uuid::v7());

        // 81246442-0476-1 в боевом ответе: из Омска на Дальний Восток —
        // нелокальная продажа.
        self::assertSame('81246442-0476-1', $facts[1]->postingNumber());
        self::assertSame('Омск', $facts[1]->clusterFrom());
        self::assertSame('Дальний Восток', $facts[1]->clusterTo());
    }

    public function testKeepsOrderMomentInUtcWhileBusinessDateCrossesMidnight(): void
    {
        $fixtureBody = file_get_contents(self::FIXTURE);
        self::assertIsString($fixtureBody);

        $fact = (new OzonPostingFboListParser())->parse($fixtureBody, Uuid::v7(), Uuid::v7(), Uuid::v7())[0];

        // in_process_at = 2026-06-30T21:01:11Z: бизнес-дата по Москве уже
        // 1 июля, а момент заказа остаётся точным и в UTC.
        self::assertSame('2026-07-01', $fact->businessDate()->format('Y-m-d'));
        self::assertNotNull($fact->orderedAt());
        self::assertSame('2026-06-30 21:01:11 +00:00', $fact->orderedAt()->format('Y-m-d H:i:s P'));
    }

    public function testOrderMomentIsOutsideRowHash(): void
    {
        $base = SalesFactBuilder::aSalesFact();

        // Неизменен: его отсутствие у старых строк не должно выглядеть
        // для upsert как изменение факта.
        self::assertSame(
            $base->withOrderedAt(null)->build()->rowHash(),
            $base->withOrderedAt(new \DateTimeImmutable('2026-07-01 10:00:00'))->build()->rowHash(),
        );
    }

    public function testMissingOrEmptyClustersBecomeNull(): void
    {
        $body = json_encode(['result' => [[
            'posting_number' => 'P-CL-1',
            'order_number' => 'P-CL',
            'status' => 'delivered',
            'in_process_at' => '2026-07-01T09:00:00Z',
            'products' => [['sku' => 111, 'quantity' => 1]],
            'financial_data' => [
                'cluster_from' => '',
                'products' => [['product_id' => 111, 'price' => 100, 'commission_amount' => -10]],
            ],
        ]]], \JSON_THROW_ON_ERROR);

        $facts = (new OzonPostingFboListParser())->parse($body, Uuid::v7(), Uuid::v7(), Uuid::v7());

        self::assertNull($facts[0]->clusterFrom());
        self::assertNull($facts[0]->clusterTo());
    }

    public function testEmptyCityInFixtureBecomesNull(): void
    {
        $fixtureBody = file_get_contents(self::FIXTURE);
        self::assertIsString($fixtureBody);

        $facts = (new OzonPostingFboListParser())->parse($fixtureBody, Uuid::v7(), Uuid::v7(), Uuid::v7());

        // 36806293-0563-1 в боевом ответе пришёл с city = "".
        $fact = array_values(array_filter(
            $facts,
            static fn ($fact): bool => '36806293-0563-1' === $fact->postingNumber(),
        ))[0];
        self::assertNull($fact->deliveryCity());
        self::assertNotNull($fact->warehouseName());
    }

    public function testCopiesDeliveryAttributesToEveryProductOfThePosting(): void
    {
        $facts = (new OzonPostingFboListParser())->parse(
            self::postingBody(['warehouse_id' => 42, 'warehouse_name' => 'КАЗАНЬ_РФЦ', 'city' => 'Уфа']),
            Uuid::v7(),
            Uuid::v7(),
            Uuid::v7(),
        );

        self::assertCount(2, $facts);
        foreach ($facts as $fact) {
            self::assertSame(42, $fact->warehouseId());
            self::assertSame('КАЗАНЬ_РФЦ', $fact->warehouseName());
            self::assertSame('Уфа', $fact->deliveryCity());
        }
    }

    /**
     * Атрибуты доставки — только для показа: их отсутствие или чужой тип
     * не должны терять суммы и статусы отправления.
     *
     * @param array<string, mixed>|null $analytics
     */
    #[DataProvider('unusableAnalyticsData')]
    public function testUnusableAnalyticsDataYieldsNullsWithoutFailing(?array $analytics): void
    {
        $facts = (new OzonPostingFboListParser())->parse(
            self::postingBody($analytics),
            Uuid::v7(),
            Uuid::v7(),
            Uuid::v7(),
        );

        self::assertCount(2, $facts);
        self::assertNull($facts[0]->warehouseId());
        self::assertNull($facts[0]->warehouseName());
        self::assertNull($facts[0]->deliveryCity());
    }

    /**
     * @return iterable<string, array{array<string, mixed>|null}>
     */
    public static function unusableAnalyticsData(): iterable
    {
        yield 'no analytics_data' => [null];
        yield 'empty values' => [['warehouse_id' => 0, 'warehouse_name' => '  ', 'city' => '']];
        yield 'foreign types' => [['warehouse_id' => '42', 'warehouse_name' => 7, 'city' => ['x']]];
    }

    public function testChangedDeliveryCityChangesRowHash(): void
    {
        $parser = new OzonPostingFboListParser();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $rawDocumentId = Uuid::v7();

        $withoutCity = $parser->parse(self::postingBody(['city' => '']), $companyId, $accountId, $rawDocumentId);
        $withCity = $parser->parse(self::postingBody(['city' => 'Уфа']), $companyId, $accountId, $rawDocumentId);

        // Ozon дозаполняет город задним числом — детектор изменений ADR-006
        // обязан это увидеть, иначе upsert оставит строку без города.
        self::assertNotSame($withoutCity[0]->rowHash(), $withCity[0]->rowHash());
    }

    public function testChangedClusterChangesRowHash(): void
    {
        $base = SalesFactBuilder::aSalesFact();

        self::assertNotSame(
            $base->withClusters('Омск', 'Омск')->build()->rowHash(),
            $base->withClusters('Омск', 'Дальний Восток')->build()->rowHash(),
        );
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

    public function testCopiesPostingAndOrderNumbersToEveryProductFact(): void
    {
        $body = file_get_contents(self::BUYOUT_FIXTURE);
        self::assertIsString($body);

        $facts = (new OzonPostingFboListParser())->parse($body, Uuid::v7(), Uuid::v7(), Uuid::v7());

        self::assertCount(7, $facts);
        self::assertSame('TEST-MIX-1-1', $facts[0]->postingNumber());
        self::assertSame('TEST-MIX-1', $facts[0]->orderNumber());
        self::assertSame('TEST-MIX-1-2', $facts[1]->postingNumber());
        self::assertSame('TEST-MIX-1', $facts[1]->orderNumber());
        self::assertSame(2, $facts[1]->quantity());
    }

    public function testMissingOrderNumberFailsLoudly(): void
    {
        $body = json_encode([
            'result' => [[
                'posting_number' => 'P-ORDER-MISSING',
                'status' => 'delivered',
                'in_process_at' => '2026-08-30T00:00:00Z',
                'products' => [],
                'financial_data' => ['products' => []],
            ]],
        ], \JSON_THROW_ON_ERROR);

        $this->expectException(\UnexpectedValueException::class);

        (new OzonPostingFboListParser())->parse($body, Uuid::v7(), Uuid::v7(), Uuid::v7());
    }

    #[DataProvider('nonPositiveQuantities')]
    public function testRejectsNonPositiveProductQuantity(int $quantity): void
    {
        $body = json_encode([
            'result' => [[
                'posting_number' => 'P-INVALID-QUANTITY',
                'order_number' => 'ORDER-INVALID-QUANTITY',
                'status' => 'delivered',
                'in_process_at' => '2026-08-30T00:00:00Z',
                'products' => [['sku' => 111, 'quantity' => $quantity]],
                'financial_data' => ['products' => [[
                    'product_id' => 111,
                    'price' => 100,
                    'commission_amount' => 0,
                ]]],
            ]],
        ], \JSON_THROW_ON_ERROR);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sales fact quantity must be positive.');

        (new OzonPostingFboListParser())->parse($body, Uuid::v7(), Uuid::v7(), Uuid::v7());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function nonPositiveQuantities(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    /**
     * Отправление из двух товаров; analytics_data = null — ключа нет вовсе.
     *
     * @param array<string, mixed>|null $analytics
     */
    private static function postingBody(?array $analytics): string
    {
        $posting = [
            'posting_number' => 'P-GEO-1',
            'order_number' => 'P-GEO',
            'status' => 'delivered',
            'in_process_at' => '2026-07-01T09:00:00Z',
            'products' => [['sku' => 111, 'quantity' => 1], ['sku' => 222, 'quantity' => 1]],
            'financial_data' => ['products' => [
                ['product_id' => 111, 'price' => 100, 'commission_amount' => -10],
                ['product_id' => 222, 'price' => 200, 'commission_amount' => -20],
            ]],
        ];
        if (null !== $analytics) {
            $posting['analytics_data'] = $analytics;
        }

        return json_encode(['result' => [$posting]], \JSON_THROW_ON_ERROR);
    }

    /**
     * Всё, что факт несёт в базу, кроме ссылки на raw-документ — она
     * у двух тел разная по построению.
     *
     * @return array<string, mixed>
     */
    private static function comparable(SalesFact $fact): array
    {
        return [
            'sourceRowId' => $fact->sourceRowId(),
            'businessDate' => $fact->businessDate()->format('Y-m-d'),
            'orderedAt' => $fact->orderedAt()?->format(\DATE_ATOM),
            'status' => $fact->status(),
            'sku' => $fact->marketplaceSku(),
            'quantity' => $fact->quantity(),
            'amount' => [$fact->amount()->minorAmount(), $fact->amount()->currency()],
            'commission' => [$fact->commissionAmount()->minorAmount(), $fact->commissionAmount()->currency()],
            'postingNumber' => $fact->postingNumber(),
            'orderNumber' => $fact->orderNumber(),
            'warehouseId' => $fact->warehouseId(),
            'warehouseName' => $fact->warehouseName(),
            'deliveryCity' => $fact->deliveryCity(),
            'clusterFrom' => $fact->clusterFrom(),
            'clusterTo' => $fact->clusterTo(),
            'rowHash' => $fact->rowHash(),
        ];
    }
}
