<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\MarketplacePostingStatusRepository;
use App\Ingestion\Domain\MarketplaceReturnFactRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Query\BuyoutDailyQuery;
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

        self::assertSame(10, $rows[1]->orderedQuantity);
        self::assertSame(0, $rows[1]->resolvedQuantity);
        self::assertSame(7, $rows[1]->projectedBuyoutQuantity);
        self::assertNull($rows[1]->actualBuyoutRateBps);
        // Дозревший день 29-го уже вошёл в rolling training window:
        // Прогноз выкупа исключает ожидаемые T1 из знаменателя:
        // (24+10) / (24+10+2+1) = 9189 bps.
        self::assertSame(9189, $rows[1]->projectedBuyoutRateBps);
        self::assertSame(0, $rows[1]->resolutionRateBps);
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
