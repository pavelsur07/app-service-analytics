<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\MarketplacePostingStatusRepository;
use App\Ingestion\Domain\MarketplaceReturnFactRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Query\Buyout\BuyoutDailyQuery;
use App\Ingestion\Infrastructure\Query\Buyout\BuyoutDailyRow;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\MarketplacePostingStatusBuilder;
use App\Tests\Support\Builder\MarketplaceReturnFactBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BuyoutDailyQueryTest extends KernelTestCase
{
    private Uuid $companyId;
    private Uuid $accountId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->companyId = Uuid::v7();
        $this->accountId = Uuid::v7();
        $this->seed();
    }

    public function testDailySeriesCombinesMatureActualAndFreshForecastInDateOrder(): void
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $query = new BuyoutDailyQuery($connection);
        $rawRows = $query->build(
            companyId: $this->companyId->toRfc4122(),
            marketplaceSku: 'DAILY',
            from: new \DateTimeImmutable('2026-08-29'),
            to: new \DateTimeImmutable('2026-08-30'),
            asOf: new \DateTimeImmutable('2026-08-30T12:00:00Z'),
        )->executeQuery()->fetchAllAssociative();
        $rows = array_map(BuyoutDailyQuery::mapRow(...), $rawRows);

        self::assertCount(2, $rows);
        self::assertSame(['2026-08-29', '2026-08-30'], array_column($rows, 'date'));

        self::assertSame(20, $rows[0]->orderedQuantity);
        self::assertSame(20, $rows[0]->resolvedQuantity);
        self::assertSame(10, $rows[0]->projectedBuyoutQuantity);
        self::assertSame(7692, $rows[0]->actualBuyoutRateBps);
        self::assertSame(7692, $rows[0]->projectedBuyoutRateBps);
        self::assertSame(10000, $rows[0]->resolutionRateBps);
        self::assertSame('mature', $rows[0]->maturityStatus);
        self::assertSame(0, $rows[0]->inFlightRateBps);

        self::assertSame(10, $rows[1]->orderedQuantity);
        self::assertSame(0, $rows[1]->resolvedQuantity);
        self::assertSame(7, $rows[1]->projectedBuyoutQuantity);
        self::assertNull($rows[1]->actualBuyoutRateBps);
        // Дозревший день 29-го уже вошёл в rolling training window:
        // Прогноз выкупа исключает ожидаемые T1 из знаменателя:
        // (24+10) / (24+10+2+1) = 9189 bps.
        self::assertSame(9189, $rows[1]->projectedBuyoutRateBps);
        self::assertSame(0, $rows[1]->resolutionRateBps);
        self::assertSame('preliminary', $rows[1]->maturityStatus);
        self::assertSame(10000, $rows[1]->inFlightRateBps);
    }

    public function testMaturityIsPerDaySoAnInFlightDayBetweenClosedDaysStaysPreliminary(): void
    {
        $facts = [];
        $statuses = [];
        foreach (['2026-08-26', '2026-08-27', '2026-08-28'] as $date) {
            $posting = 'GAP-D-'.$date;
            $facts[] = $this->sale($posting, $posting, 'GAP', 'delivered', 3, $date);
            $statuses[] = $this->postingStatus($posting, $posting, 'delivering', $date.' 01:00:00');
            $statuses[] = $this->postingStatus($posting, $posting, 'delivered', $date.' 02:00:00');
        }
        // Долгий хвост 27-го: одна штука из четырёх всё ещё в доставке.
        $facts[] = $this->sale('GAP-TAIL', 'GAP-TAIL', 'GAP', 'delivering', 1, '2026-08-27');
        $statuses[] = $this->postingStatus('GAP-TAIL', 'GAP-TAIL', 'delivering', '2026-08-27 01:00:00');
        $this->sales()->upsertAll($facts);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), $statuses);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $rawRows = (new BuyoutDailyQuery($connection))->build(
            companyId: $this->companyId->toRfc4122(),
            marketplaceSku: 'GAP',
            from: new \DateTimeImmutable('2026-08-26'),
            to: new \DateTimeImmutable('2026-08-28'),
            asOf: new \DateTimeImmutable('2026-08-30T12:00:00Z'),
        )->executeQuery()->fetchAllAssociative();
        $rows = array_map(BuyoutDailyQuery::mapRow(...), $rawRows);

        self::assertSame(['mature', 'preliminary', 'mature'], array_column($rows, 'maturityStatus'));
        self::assertSame([0, 2500, 0], array_column($rows, 'inFlightRateBps'));
        self::assertSame(10000, $rows[0]->actualBuyoutRateBps);
        // Факт строго по известным исходам был бы 100% — но день не созрел.
        self::assertNull($rows[1]->actualBuyoutRateBps);
        self::assertSame(10000, $rows[2]->actualBuyoutRateBps);
    }

    public function testDailySeriesLeavesTerminalUnknownWithoutForecast(): void
    {
        $this->sales()->upsertAll([
            $this->sale('DAY-UNKNOWN', 'DAY-UNKNOWN', 'DAILY-UNKNOWN', 'cancelled', 2, '2026-08-30'),
        ]);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), [
            $this->postingStatus('DAY-UNKNOWN', 'DAY-UNKNOWN', 'cancelled', '2026-08-30 10:00:00'),
        ]);
        $this->returns()->upsertAll([
            $this->returnFact('RET-DAY-UNKNOWN', 'DAY-UNKNOWN', 'DAY-UNKNOWN', 'DAILY-UNKNOWN', 'Cancellation', 'Новая неизвестная причина'),
        ]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $rawRows = (new BuyoutDailyQuery($connection))->build(
            companyId: $this->companyId->toRfc4122(),
            marketplaceSku: 'DAILY-UNKNOWN',
            from: new \DateTimeImmutable('2026-08-30'),
            to: new \DateTimeImmutable('2026-08-30'),
            asOf: new \DateTimeImmutable('2026-08-30T12:00:00Z'),
        )->executeQuery()->fetchAllAssociative();
        $rows = array_map(BuyoutDailyQuery::mapRow(...), $rawRows);

        self::assertCount(1, $rows);
        self::assertNull($rows[0]->projectedBuyoutQuantity);
        self::assertNull($rows[0]->projectedBuyoutRateBps);
    }

    public function testMonetarySeriesUsesOrderPriceAndNetDeliveredUnits(): void
    {
        $delivered = SalesFactBuilder::aSalesFact()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withSourceRowId('MONEY-D|MONEY')
            ->withPostingNumber('MONEY-D')
            ->withOrderNumber('MONEY-D')
            ->withMarketplaceSku('MONEY')
            ->withStatus('delivered')
            ->withQuantity(3)
            ->withAmount(Money::ofMinor(10000, 'RUB'))
            ->withBusinessDate(new \DateTimeImmutable('2026-08-29'))
            ->build();
        $cancelled = SalesFactBuilder::aSalesFact()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withSourceRowId('MONEY-T1|MONEY')
            ->withPostingNumber('MONEY-T1')
            ->withOrderNumber('MONEY-T1')
            ->withMarketplaceSku('MONEY')
            ->withStatus('cancelled')
            ->withQuantity(2)
            ->withAmount(Money::ofMinor(25000, 'RUB'))
            ->withBusinessDate(new \DateTimeImmutable('2026-08-29'))
            ->build();
        $otherAccountId = Uuid::v7();
        $otherAccount = SalesFactBuilder::aSalesFact()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($otherAccountId)
            ->withSourceRowId('MONEY-D|MONEY')
            ->withPostingNumber('MONEY-D')
            ->withOrderNumber('MONEY-D')
            ->withMarketplaceSku('MONEY')
            ->withStatus('delivered')
            ->withQuantity(1)
            ->withAmount(Money::ofMinor(3000, 'RUB'))
            ->withBusinessDate(new \DateTimeImmutable('2026-08-29'))
            ->build();
        $this->sales()->upsertAll([$delivered, $cancelled, $otherAccount]);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), [
            $this->postingStatus('MONEY-D', 'MONEY-D', 'delivered', '2026-08-30 02:00:00'),
            $this->postingStatus('MONEY-T1', 'MONEY-T1', 'awaiting_packaging', '2026-08-29 01:00:00'),
            $this->postingStatus('MONEY-T1', 'MONEY-T1', 'cancelled', '2026-08-30 02:00:00'),
        ]);
        $this->returns()->upsertAll([
            $this->returnFact('MONEY-RETURN', 'MONEY-D', 'MONEY-D', 'MONEY', 'ClientReturn', 'Возврат покупателя', 1),
            $this->returnFact('MONEY-CANCEL', 'MONEY-T1', 'MONEY-T1', 'MONEY', 'Cancellation', 'Покупатель отменил заказ', 2),
        ]);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), [
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
                ->withCompanyId($this->companyId)
                ->withMarketplaceAccountId($otherAccountId)
                ->withPostingNumber('MONEY-D')
                ->withOrderNumber('MONEY-D')
                ->withStatus('delivered')
                ->withObservedAt(new \DateTimeImmutable('2026-08-30 02:00:00'))
                ->build(),
        ]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $raw = (new BuyoutDailyQuery($connection))->build(
            $this->companyId->toRfc4122(), 'MONEY',
            new \DateTimeImmutable('2026-08-29'), new \DateTimeImmutable('2026-08-29'),
            new \DateTimeImmutable('2026-08-31T12:00:00Z'),
            withMoney: true,
        )->executeQuery()->fetchAssociative();

        self::assertIsArray($raw);
        $row = BuyoutDailyQuery::mapRow($raw);
        self::assertSame(6, $row->orderedQuantity);
        self::assertSame(83000, $row->orderedAmountMinor);
        self::assertSame(23000, $row->actualRevenueMinor);
        self::assertSame(23000, $row->forecastRevenueMinor);
        self::assertSame(10000, $row->projectedBuyoutRateBps);
    }

    public function testLateBuyoutAndFullReturnChangeOriginalOrderDay(): void
    {
        $this->sales()->upsertAll([
            $this->pricedSale('LATE', 'LATE', 'LATE', 'delivered', 2, '2026-08-31', 12345),
            $this->pricedSale('FULL', 'FULL', 'FULL', 'delivered', 3, '2026-08-31', 5000),
        ]);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), [
            $this->postingStatus('LATE', 'LATE', 'delivered', '2026-09-03 02:00:00'),
            $this->postingStatus('FULL', 'FULL', 'delivered', '2026-09-03 02:00:00'),
        ]);
        $this->returns()->upsertAll([
            $this->returnFact('FULL-RETURN', 'FULL', 'FULL', 'FULL', 'ClientReturn', 'Возврат покупателя', 3),
        ]);

        $late = $this->moneyRow('LATE', '2026-08-31');
        $full = $this->moneyRow('FULL', '2026-08-31');
        self::assertSame(24690, $late->orderedAmountMinor);
        self::assertSame(24690, $late->actualRevenueMinor);
        self::assertSame(24690, $late->forecastRevenueMinor);
        self::assertSame(15000, $full->orderedAmountMinor);
        self::assertSame(0, $full->actualRevenueMinor);
        self::assertSame(0, $full->forecastRevenueMinor);
    }

    public function testUnestimatedThresholdAndCurrencyIsolationForMoney(): void
    {
        $this->sales()->upsertAll([
            $this->pricedSale('EST-D', 'EST-D', 'EST', 'delivered', 9, '2026-08-29', 10000),
            $this->pricedSale('EST-U1', 'EST-U1', 'EST', 'cancelled', 1, '2026-08-29', 5000),
            $this->pricedSale('EST-USD', 'EST-USD', 'EST', 'delivered', 2, '2026-08-29', 99999, 'USD'),
        ]);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), [
            $this->postingStatus('EST-D', 'EST-D', 'delivered', '2026-08-29 02:00:00'),
            $this->postingStatus('EST-U1', 'EST-U1', 'cancelled', '2026-08-29 02:00:00'),
            $this->postingStatus('EST-USD', 'EST-USD', 'delivered', '2026-08-29 02:00:00'),
        ]);
        $this->returns()->upsertAll([
            $this->returnFact('EST-U1-RETURN', 'EST-U1', 'EST-U1', 'EST', 'Cancellation', 'Новая неизвестная причина'),
        ]);

        $atThreshold = $this->moneyRow('EST', '2026-08-29');
        self::assertSame(10, $atThreshold->orderedQuantity);
        self::assertSame(95000, $atThreshold->orderedAmountMinor);
        self::assertSame(90000, $atThreshold->actualRevenueMinor);
        self::assertSame(95000, $atThreshold->forecastRevenueMinor);

        $this->sales()->upsertAll([
            $this->pricedSale('EST-U2', 'EST-U2', 'EST', 'cancelled', 1, '2026-08-29', 1000),
        ]);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), [
            $this->postingStatus('EST-U2', 'EST-U2', 'cancelled', '2026-08-29 03:00:00'),
        ]);
        $this->returns()->upsertAll([
            $this->returnFact('EST-U2-RETURN', 'EST-U2', 'EST-U2', 'EST', 'Cancellation', 'Новая неизвестная причина'),
        ]);

        $overThreshold = $this->moneyRow('EST', '2026-08-29');
        self::assertSame(11, $overThreshold->orderedQuantity);
        self::assertNull($overThreshold->forecastRevenueMinor);
        self::assertNull($overThreshold->projectedBuyoutRateBps);
        self::assertSame(90000, $overThreshold->actualRevenueMinor);
    }

    public function testForecastMoneyRoundsOnceAfterSummingAllOrderLines(): void
    {
        $facts = [];
        $statuses = [];
        for ($index = 1; $index <= 7; ++$index) {
            $posting = 'PENNY-'.$index;
            $facts[] = $this->pricedSale($posting, $posting, 'PENNY', 'awaiting_packaging', 1, '2026-08-30', 1);
            $statuses[] = $this->postingStatus($posting, $posting, 'awaiting_packaging', '2026-08-30 09:00:00');
        }
        $this->sales()->upsertAll($facts);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), $statuses);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $row = (new BuyoutDailyQuery($connection))->build(
            $this->companyId->toRfc4122(), 'PENNY',
            new \DateTimeImmutable('2026-08-30'), new \DateTimeImmutable('2026-08-30'),
            new \DateTimeImmutable('2026-08-30T12:00:00Z'),
            withMoney: true,
        )->executeQuery()->fetchAssociative();

        self::assertIsArray($row);
        $row = BuyoutDailyQuery::mapRow($row);
        self::assertSame(7, $row->orderedAmountMinor);
        self::assertSame(6, $row->forecastRevenueMinor);
        self::assertSame(0, $row->actualRevenueMinor);
    }

    public function testUnestimatedRevenueUsesQuantityRateAtEachUnknownOrdersPrice(): void
    {
        $facts = [
            $this->pricedSale('CHEAP-D', 'CHEAP-D', 'MIXED', 'delivered', 1, '2026-08-29', 100),
            $this->pricedSale('EXPENSIVE-T2', 'EXPENSIVE-T2', 'MIXED', 'cancelled', 8, '2026-08-29', 10000),
            $this->pricedSale('EXPENSIVE-U', 'EXPENSIVE-U', 'MIXED', 'cancelled', 1, '2026-08-29', 10000),
        ];
        $this->sales()->upsertAll($facts);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), [
            $this->postingStatus('CHEAP-D', 'CHEAP-D', 'delivered', '2026-08-29 02:00:00'),
            $this->postingStatus('EXPENSIVE-T2', 'EXPENSIVE-T2', 'delivering', '2026-08-29 01:00:00'),
            $this->postingStatus('EXPENSIVE-T2', 'EXPENSIVE-T2', 'cancelled', '2026-08-29 02:00:00'),
            $this->postingStatus('EXPENSIVE-U', 'EXPENSIVE-U', 'cancelled', '2026-08-29 02:00:00'),
        ]);
        $this->returns()->upsertAll([
            $this->returnFact('EXPENSIVE-U-RETURN', 'EXPENSIVE-U', 'EXPENSIVE-U', 'MIXED', 'Cancellation', 'Новая неизвестная причина'),
        ]);

        $row = $this->moneyRow('MIXED', '2026-08-29');
        self::assertSame(90100, $row->orderedAmountMinor);
        self::assertSame(100, $row->actualRevenueMinor);
        self::assertSame(1211, $row->forecastRevenueMinor);
    }

    private function moneyRow(string $sku, string $date): BuyoutDailyRow
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $row = (new BuyoutDailyQuery($connection))->build(
            $this->companyId->toRfc4122(), $sku,
            new \DateTimeImmutable($date), new \DateTimeImmutable($date),
            new \DateTimeImmutable('2026-09-04T12:00:00Z'),
            withMoney: true,
        )->executeQuery()->fetchAssociative();
        self::assertIsArray($row);

        return BuyoutDailyQuery::mapRow($row);
    }

    private function pricedSale(string $posting, string $order, string $sku, string $status, int $quantity, string $date, int $minor, string $currency = 'RUB'): \App\Ingestion\Domain\SalesFact
    {
        return SalesFactBuilder::aSalesFact()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withSourceRowId($posting.'|'.$sku)
            ->withPostingNumber($posting)
            ->withOrderNumber($order)
            ->withMarketplaceSku($sku)
            ->withStatus($status)
            ->withQuantity($quantity)
            ->withBusinessDate(new \DateTimeImmutable($date))
            ->withAmount(Money::ofMinor($minor, $currency))
            ->withCommissionAmount(Money::ofMinor(0, $currency))
            ->build();
    }

    private function seed(): void
    {
        $facts = [];
        $statuses = [];
        $returns = [];

        for ($index = 1; $index <= 30; ++$index) {
            $posting = 'DAILY-MAT-'.$index;
            $facts[] = $this->sale($posting, $posting, 'MATURITY', 'delivered', 1, '2026-06-01');
            $statuses[] = $this->postingStatus($posting, $posting, 'delivering', '2026-06-02 00:00:00');
            $statuses[] = $this->postingStatus($posting, $posting, 'delivered', '2026-06-02 01:00:00');
        }
        for ($index = 1; $index <= 24; ++$index) {
            $posting = 'DAILY-TRAIN-D-'.$index;
            $facts[] = $this->sale($posting, $posting, 'DAILY', 'delivered', 1, '2026-08-01');
            $statuses[] = $this->postingStatus($posting, $posting, 'delivering', '2026-08-02 00:00:00');
            $statuses[] = $this->postingStatus($posting, $posting, 'delivered', '2026-08-02 01:00:00');
        }
        for ($index = 1; $index <= 6; ++$index) {
            $posting = 'DAILY-TRAIN-T1-'.$index;
            $facts[] = $this->sale($posting, $posting, 'DAILY', 'cancelled', 1, '2026-08-01');
            $statuses[] = $this->postingStatus($posting, $posting, 'awaiting_packaging', '2026-08-02 00:00:00');
            $statuses[] = $this->postingStatus($posting, $posting, 'cancelled', '2026-08-02 01:00:00');
            $returns[] = $this->returnFact('RET-'.$posting, $posting, $posting, 'DAILY', 'Cancellation', 'Покупатель отменил заказ');
        }

        // Mature day: D=10, T1=4, T2=2, P=1, R=3, ordered/resolved=20.
        $facts[] = $this->sale('DAY-D', 'DAY-MIX', 'DAILY', 'delivered', 10, '2026-08-29');
        $statuses[] = $this->postingStatus('DAY-D', 'DAY-MIX', 'delivering', '2026-08-29 01:00:00');
        $statuses[] = $this->postingStatus('DAY-D', 'DAY-MIX', 'delivered', '2026-08-29 02:00:00');
        $facts[] = $this->sale('DAY-P', 'DAY-MIX', 'DAILY', 'cancelled', 1, '2026-08-29');
        $statuses[] = $this->postingStatus('DAY-P', 'DAY-MIX', 'cancelled', '2026-08-29 02:00:00');
        $returns[] = $this->returnFact('RET-DAY-P', 'DAY-P', 'DAY-MIX', 'DAILY', 'Cancellation', 'Покупатель отказался при вручении: товар не подошел');
        $facts[] = $this->sale('DAY-T2', 'DAY-T2', 'DAILY', 'cancelled', 2, '2026-08-29');
        $statuses[] = $this->postingStatus('DAY-T2', 'DAY-T2', 'delivering', '2026-08-29 01:00:00');
        $statuses[] = $this->postingStatus('DAY-T2', 'DAY-T2', 'cancelled', '2026-08-29 02:00:00');
        $facts[] = $this->sale('DAY-R', 'DAY-R', 'DAILY', 'delivered', 3, '2026-08-29');
        $statuses[] = $this->postingStatus('DAY-R', 'DAY-R', 'delivering', '2026-08-29 01:00:00');
        $statuses[] = $this->postingStatus('DAY-R', 'DAY-R', 'delivered', '2026-08-29 02:00:00');
        $returns[] = $this->returnFact('RET-DAY-R', 'DAY-R', 'DAY-R', 'DAILY', 'ClientReturn', 'Возврат покупателя', 3);
        $facts[] = $this->sale('DAY-T1', 'DAY-T1', 'DAILY', 'cancelled', 4, '2026-08-29');
        $statuses[] = $this->postingStatus('DAY-T1', 'DAY-T1', 'awaiting_packaging', '2026-08-29 01:00:00');
        $statuses[] = $this->postingStatus('DAY-T1', 'DAY-T1', 'cancelled', '2026-08-29 02:00:00');
        $returns[] = $this->returnFact('RET-DAY-T1', 'DAY-T1', 'DAY-T1', 'DAILY', 'Cancellation', 'Покупатель отменил заказ', 4);

        // Fresh day: pre-handover unresolved qty=10, baseline 80%.
        $facts[] = $this->sale('DAY-FRESH', 'DAY-FRESH', 'DAILY', 'awaiting_packaging', 10, '2026-08-30');
        $statuses[] = $this->postingStatus('DAY-FRESH', 'DAY-FRESH', 'awaiting_packaging', '2026-08-30 09:00:00');

        // Другой SKU той же компании обязан быть отсечён path-фильтром.
        $facts[] = $this->sale('DAY-OTHER', 'DAY-OTHER', 'OTHER', 'delivered', 100, '2026-08-29');
        $statuses[] = $this->postingStatus('DAY-OTHER', 'DAY-OTHER', 'delivered', '2026-08-29 02:00:00');

        $this->sales()->upsertAll($facts);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), $statuses);
        $this->returns()->upsertAll($returns);
    }

    private function sale(string $posting, string $order, string $sku, string $status, int $quantity, string $date): \App\Ingestion\Domain\SalesFact
    {
        return SalesFactBuilder::aSalesFact()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withSourceRowId($posting.'|'.$sku)
            ->withPostingNumber($posting)
            ->withOrderNumber($order)
            ->withMarketplaceSku($sku)
            ->withStatus($status)
            ->withQuantity($quantity)
            ->withBusinessDate(new \DateTimeImmutable($date))
            ->build();
    }

    private function postingStatus(string $posting, string $order, string $status, string $observedAt): \App\Ingestion\Domain\MarketplacePostingStatus
    {
        return MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withPostingNumber($posting)
            ->withOrderNumber($order)
            ->withStatus($status)
            ->withObservedAt(new \DateTimeImmutable($observedAt))
            ->withRawDocumentId(Uuid::v7())
            ->build();
    }

    private function returnFact(
        string $id,
        string $posting,
        string $order,
        string $sku,
        string $type,
        string $reason,
        int $quantity = 1,
    ): \App\Ingestion\Domain\MarketplaceReturnFact {
        return MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withSourceRowId($id)
            ->withPostingNumber($posting)
            ->withOrderNumber($order)
            ->withMarketplaceSku($sku)
            ->withReturnType($type)
            ->withReturnReasonName($reason)
            ->withQuantity($quantity)
            ->build();
    }

    private function sales(): SalesFactRepository
    {
        /** @var SalesFactRepository $repository */
        $repository = self::getContainer()->get(SalesFactRepository::class);

        return $repository;
    }

    private function postingStatuses(): MarketplacePostingStatusRepository
    {
        /** @var MarketplacePostingStatusRepository $repository */
        $repository = self::getContainer()->get(MarketplacePostingStatusRepository::class);

        return $repository;
    }

    private function returns(): MarketplaceReturnFactRepository
    {
        /** @var MarketplaceReturnFactRepository $repository */
        $repository = self::getContainer()->get(MarketplaceReturnFactRepository::class);

        return $repository;
    }
}
