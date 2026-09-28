<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Domain;

use App\Ingestion\Domain\OzonAdExpenseParser;
use App\Ingestion\Domain\OzonAdSkuExpense;
use App\Ingestion\Domain\OzonAdSkuReportParser;
use App\Ingestion\Domain\OzonPerformanceMoney;
use App\Shared\Domain\ValueObject\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Разбор SKU-разбивки рекламы (ADR-035) на зафиксированных ответах
 * кабинета первого клиента (CLAUDE.md §9).
 */
final class OzonAdSkuReportParserTest extends TestCase
{
    private const string DIR = __DIR__.'/../../../Fixtures/Marketplace/ozon/performance/';

    public function testAsyncReportRowsAreTakenAsTheCabinetGaveThem(): void
    {
        $expenses = (new OzonAdSkuReportParser())->parseReport($this->fixture('statistics-json-many-2026-08-25.json'));

        // 725 строк пяти кампаний за 30 дней, 158 960,62 ₽. Итог расхода
        // кампаний за те же дни — 158 960,57 ₽: разница — построчное
        // округление площадки, и сумма по SKU его не прячет.
        self::assertCount(725, $expenses);
        self::assertSame(-15896062, $this->total($expenses)->minorAmount());
        self::assertSame(25, \count(array_unique(array_map(static fn (OzonAdSkuExpense $e): string => $e->marketplaceSku, $expenses))));

        $first = $expenses[0];
        // Кампания — ключ верхнего уровня, дата d.m.Y, сумма с запятой.
        self::assertSame(['14275771', '2026-08-25', '286085455', -210147, 'RUB'], [
            $first->campaignId,
            $first->businessDate->format('Y-m-d'),
            $first->marketplaceSku,
            $first->amount->minorAmount(),
            $first->amount->currency(),
        ]);
    }

    public function testSyncDayRowsAreTakenAsTheCabinetGaveThem(): void
    {
        $expenses = (new OzonAdSkuReportParser())->parseDay($this->fixture('statistics-products-sku-2026-09-23.json'));

        self::assertCount(21, $expenses);
        self::assertSame(-465482, $this->total($expenses)->minorAmount());
        self::assertSame(['14275771', '2026-09-23', '286085455', -119339], [
            $expenses[0]->campaignId,
            $expenses[0]->businessDate->format('Y-m-d'),
            $expenses[0]->marketplaceSku,
            $expenses[0]->amount->minorAmount(),
        ]);
    }

    public function testSameDayGivesTheSameAmountsInBothForms(): void
    {
        $parser = new OzonAdSkuReportParser();
        $async = [];
        foreach ($parser->parseReport($this->fixture('statistics-json-many-2026-09-17.json')) as $e) {
            if ('2026-09-23' === $e->businessDate->format('Y-m-d')) {
                $async[$e->campaignId.'|'.$e->marketplaceSku] = $e->amount->minorAmount();
            }
        }
        $sync = [];
        foreach ($parser->parseDay($this->fixture('statistics-products-sku-2026-09-23.json')) as $e) {
            $sync[$e->campaignId.'|'.$e->marketplaceSku] = $e->amount->minorAmount();
        }

        // Запятая и точка — одна и та же сумма: 19 общих пар до копейки.
        $common = array_intersect_key($async, $sync);
        self::assertCount(19, $common);
        self::assertSame($common, array_intersect_key($sync, $common));
    }

    public function testOmittedAmountInAsyncReportIsZeroAndTheRowStays(): void
    {
        $expenses = (new OzonAdSkuReportParser())->parseReport(
            '{"1":{"report":{"rows":[{"date":"17.09.2026","sku":"308389906"}]}}}',
        );

        // Нулевая строка хранится: иначе корректировка до нуля
        // не перезаписала бы старое значение (ADR-035 п. 2).
        self::assertCount(1, $expenses);
        self::assertSame(0, $expenses[0]->amount->minorAmount());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenAsyncReports(): iterable
    {
        yield 'dot instead of comma' => ['{"1":{"report":{"rows":[{"date":"17.09.2026","sku":"1","moneySpent":"18.34"}]}}}'];
        yield 'three fraction digits' => ['{"1":{"report":{"rows":[{"date":"17.09.2026","sku":"1","moneySpent":"18,345"}]}}}'];
        yield 'negative' => ['{"1":{"report":{"rows":[{"date":"17.09.2026","sku":"1","moneySpent":"-18,34"}]}}}'];
        yield 'ISO date' => ['{"1":{"report":{"rows":[{"date":"2026-09-17","sku":"1","moneySpent":"18,34"}]}}}'];
        yield 'no sku' => ['{"1":{"report":{"rows":[{"date":"17.09.2026","moneySpent":"18,34"}]}}}'];
        yield 'campaign key not digits' => ['{"x":{"report":{"rows":[]}}}'];
        yield 'no report rows' => ['{"1":{"title":"t"}}'];
        yield 'same triple twice' => ['{"1":{"report":{"rows":[{"date":"17.09.2026","sku":"1","moneySpent":"1,00"},{"date":"17.09.2026","sku":"1","moneySpent":"2,00"}]}}}'];
    }

    #[DataProvider('brokenAsyncReports')]
    public function testBrokenAsyncReportIsAParseError(string $body): void
    {
        $this->expectException(\UnexpectedValueException::class);

        (new OzonAdSkuReportParser())->parseReport($body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenSyncDays(): iterable
    {
        yield 'comma instead of dot' => ['{"rows":[{"campaignId":"1","date":"2026-09-23","sku":"1","expense":"1193,39"}]}'];
        yield 'no expense' => ['{"rows":[{"campaignId":"1","date":"2026-09-23","sku":"1"}]}'];
        yield 'no campaign' => ['{"rows":[{"date":"2026-09-23","sku":"1","expense":"1.00"}]}'];
        yield 'dotted date' => ['{"rows":[{"campaignId":"1","date":"23.09.2026","sku":"1","expense":"1.00"}]}'];
        yield 'no rows' => ['{"products":[]}'];
    }

    #[DataProvider('brokenSyncDays')]
    public function testBrokenSyncDayIsAParseError(string $body): void
    {
        $this->expectException(\UnexpectedValueException::class);

        (new OzonAdSkuReportParser())->parseDay($body);
    }

    public function testPerformanceAmountsBecomeMinorUnitsWithoutFloat(): void
    {
        self::assertSame(276463, OzonPerformanceMoney::commaDecimal('2764,63')->minorAmount());
        self::assertSame(250, OzonPerformanceMoney::commaDecimal('2,5')->minorAmount());
        self::assertSame(700, OzonPerformanceMoney::commaDecimal('7')->minorAmount());
        self::assertSame(119339, OzonPerformanceMoney::dotDecimal('1193.39')->minorAmount());
        self::assertSame('RUB', OzonPerformanceMoney::dotDecimal('0.00')->currency());
    }

    public function testCampaignsWithSpendAreThoseThatSpentInThePeriod(): void
    {
        $parser = new OzonAdExpenseParser();
        $september = $this->fixture('statistics-expense-2025-09-01.json');

        // Весь сентябрь 2025 — пять кампаний, архивные включительно:
        // состояние кампании здесь не смотрится вовсе.
        self::assertSame(
            ['12387459', '14275771', '16017246', '17656929', '5268079'],
            $parser->campaignsWithSpend($september, new \DateTimeImmutable('2025-09-01'), new \DateTimeImmutable('2025-09-30')),
        );
        // Последний расход 12387459 — 14.09: после него её в периоде нет.
        self::assertNotContains(
            '12387459',
            $parser->campaignsWithSpend($september, new \DateTimeImmutable('2025-09-15'), new \DateTimeImmutable('2025-09-30')),
        );
    }

    public function testZeroSpendIsNotSpend(): void
    {
        $body = '{"rows":[{"id":"1","date":"2026-09-23","moneySpent":"0,00"},{"id":"2","date":"2026-09-23","moneySpent":"0,01"}]}';

        self::assertSame(['2'], (new OzonAdExpenseParser())->campaignsWithSpend($body, new \DateTimeImmutable('2026-09-23'), new \DateTimeImmutable('2026-09-23')));
    }

    /**
     * @param list<OzonAdSkuExpense> $expenses
     */
    private function total(array $expenses): Money
    {
        return Money::sum(array_map(static fn (OzonAdSkuExpense $e): Money => $e->amount, $expenses));
    }

    private function fixture(string $name): string
    {
        $body = file_get_contents(self::DIR.$name);
        self::assertIsString($body);

        return $body;
    }
}
