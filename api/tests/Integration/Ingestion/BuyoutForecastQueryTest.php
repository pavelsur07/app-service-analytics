<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\MarketplacePostingStatusRepository;
use App\Ingestion\Domain\MarketplaceReturnFactRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Query\Buyout\BuyoutDailyQuery;
use App\Ingestion\Infrastructure\Query\Buyout\BuyoutForecastQuery;
use App\Ingestion\Infrastructure\Query\Buyout\BuyoutForecastRow;
use App\Ingestion\Infrastructure\Query\Buyout\BuyoutForecastSummaryQuery;
use App\Tests\Support\Builder\MarketplacePostingStatusBuilder;
use App\Tests\Support\Builder\MarketplaceReturnFactBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BuyoutForecastQueryTest extends KernelTestCase
{
    private Uuid $companyId;
    private Uuid $accountId;
    private Uuid $noDataAccountId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->companyId = Uuid::v7();
        $this->accountId = Uuid::v7();
        $this->noDataAccountId = Uuid::v7();
        $this->seedTrainingAndCurrentCohort();
    }

    public function testForecastChangesFromPreHandoverToHandoverToTerminalWithoutAJob(): void
    {
        $before = $this->rows()['TARGET'];
        self::assertSame(10, $before->orderedQuantity);
        self::assertSame(0, $before->resolvedQuantity);
        self::assertSame(8, $before->projectedBuyoutQuantity);
        self::assertSame(10000, $before->projectedBuyoutRateBps);

        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), [
            $this->postingStatus($this->accountId, 'CURRENT-TARGET', 'CURRENT-TARGET', 'delivering', '2026-08-30 10:00:00'),
        ]);
        $afterHandover = $this->rows()['TARGET'];
        self::assertSame(0, $afterHandover->resolvedQuantity);
        self::assertSame(10, $afterHandover->projectedBuyoutQuantity);
        self::assertSame(10000, $afterHandover->projectedBuyoutRateBps);

        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), [
            $this->postingStatus($this->accountId, 'CURRENT-TARGET', 'CURRENT-TARGET', 'delivered', '2026-08-30 11:00:00'),
        ]);
        $terminal = $this->rows()['TARGET'];
        self::assertSame(10, $terminal->resolvedQuantity);
        self::assertSame(10, $terminal->projectedBuyoutQuantity);
        self::assertSame(10000, $terminal->projectedBuyoutRateBps);
        self::assertSame(10000, $terminal->resolutionRateBps);
    }

    public function testSparseSkuFallsBackToAccountAndMissingAccountSampleReturnsNull(): void
    {
        $rows = $this->rows();

        self::assertSame(5, $rows['SPARSE']->orderedQuantity);
        self::assertSame(4, $rows['SPARSE']->projectedBuyoutQuantity);
        self::assertSame(10000, $rows['SPARSE']->projectedBuyoutRateBps);

        self::assertSame(7, $rows['NO-DATA']->orderedQuantity);
        self::assertNull($rows['NO-DATA']->projectedBuyoutQuantity);
        self::assertNull($rows['NO-DATA']->projectedBuyoutRateBps);

        self::assertSame(5, $rows['TERMINAL-EXCLUDED']->orderedQuantity);
        self::assertSame(5, $rows['TERMINAL-EXCLUDED']->resolvedQuantity);
        self::assertSame(0, $rows['TERMINAL-EXCLUDED']->projectedBuyoutQuantity);
        self::assertNull($rows['TERMINAL-EXCLUDED']->projectedBuyoutRateBps);
    }

    public function testWindowSummaryStaysFullAfterKeysetCursor(): void
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $query = new BuyoutForecastQuery($connection);
        $arguments = [
            'companyId' => $this->companyId->toRfc4122(),
            'from' => new \DateTimeImmutable('2026-08-30'),
            'to' => new \DateTimeImmutable('2026-08-30'),
            'asOf' => new \DateTimeImmutable('2026-08-30T12:00:00Z'),
            'limit' => 1,
        ];
        $firstPage = $query->build(...$arguments, cursor: null)->executeQuery()->fetchAllAssociative();
        self::assertNotEmpty($firstPage);
        self::assertIsString($firstPage[0]['marketplace_sku']);
        $secondPage = $query->build(...$arguments, cursor: $firstPage[0]['marketplace_sku'])->executeQuery()->fetchAllAssociative();
        self::assertNotEmpty($secondPage);

        self::assertEquals(
            BuyoutForecastSummaryQuery::mapWindowRow($firstPage[0]),
            BuyoutForecastSummaryQuery::mapWindowRow($secondPage[0]),
        );
    }

    public function testTerminalAndDriftedUnknownsDoNotReceivePlausibleForecasts(): void
    {
        $facts = [
            $this->sale($this->accountId, 'UNKNOWN-CANCEL', 'UNKNOWN-CANCEL', 'UNKNOWN-CANCEL', 'cancelled', 2, '2026-08-30'),
            $this->sale($this->accountId, 'UNKNOWN-SUBSTATUS', 'UNKNOWN-SUBSTATUS', 'UNKNOWN-SUBSTATUS', 'delivered', 3, '2026-08-30'),
            $this->sale($this->accountId, 'WAIT-CANCEL', 'WAIT-ORDER', 'WAIT-CANCEL', 'cancelled', 1, '2026-08-30'),
            $this->sale($this->accountId, 'WAIT-SIBLING', 'WAIT-ORDER', 'WAIT-SIBLING', 'awaiting_packaging', 1, '2026-08-30'),
        ];
        $statuses = [
            $this->postingStatus($this->accountId, 'UNKNOWN-CANCEL', 'UNKNOWN-CANCEL', 'cancelled', '2026-08-30 10:00:00'),
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
                ->withCompanyId($this->companyId)
                ->withMarketplaceAccountId($this->accountId)
                ->withPostingNumber('UNKNOWN-SUBSTATUS')
                ->withOrderNumber('UNKNOWN-SUBSTATUS')
                ->withStatus('delivered', 'new_delivered_substatus')
                ->withObservedAt(new \DateTimeImmutable('2026-08-30 10:00:00'))
                ->build(),
            $this->postingStatus($this->accountId, 'WAIT-CANCEL', 'WAIT-ORDER', 'cancelled', '2026-08-30 10:00:00'),
            $this->postingStatus($this->accountId, 'WAIT-SIBLING', 'WAIT-ORDER', 'awaiting_packaging', '2026-08-30 10:00:00'),
        ];
        $returns = [
            MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
                ->withCompanyId($this->companyId)
                ->withMarketplaceAccountId($this->accountId)
                ->withSourceRowId('RET-UNKNOWN-CANCEL')
                ->withPostingNumber('UNKNOWN-CANCEL')
                ->withOrderNumber('UNKNOWN-CANCEL')
                ->withMarketplaceSku('UNKNOWN-CANCEL')
                ->withReturnReasonName('Новая неизвестная причина')
                ->build(),
            MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
                ->withCompanyId($this->companyId)
                ->withMarketplaceAccountId($this->accountId)
                ->withSourceRowId('RET-WAIT-CANCEL')
                ->withPostingNumber('WAIT-CANCEL')
                ->withOrderNumber('WAIT-ORDER')
                ->withMarketplaceSku('WAIT-CANCEL')
                ->withReturnReasonName('Покупатель отказался при вручении: товар не подошел')
                ->build(),
        ];
        $this->sales()->upsertAll($facts);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), $statuses);
        $this->returns()->upsertAll($returns);

        $rows = $this->rows();
        foreach (['UNKNOWN-CANCEL', 'UNKNOWN-SUBSTATUS', 'WAIT-CANCEL'] as $sku) {
            self::assertNull($rows[$sku]->projectedBuyoutQuantity, $sku);
            self::assertNull($rows[$sku]->projectedBuyoutRateBps, $sku);
        }
        self::assertNotNull($rows['WAIT-SIBLING']->projectedBuyoutQuantity);
    }

    public function testPendingWithReturnEvidenceDoesNotReceiveForecast(): void
    {
        $this->sales()->upsertAll([
            $this->sale(
                $this->accountId,
                'PENDING-WITH-RETURN',
                'PENDING-WITH-RETURN',
                'PENDING-WITH-RETURN',
                'awaiting_packaging',
                4,
                '2026-08-30',
            ),
        ]);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), [
            $this->postingStatus(
                $this->accountId,
                'PENDING-WITH-RETURN',
                'PENDING-WITH-RETURN',
                'awaiting_packaging',
                '2026-08-30 09:00:00',
            ),
        ]);
        $this->returns()->upsertAll([
            MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
                ->withCompanyId($this->companyId)
                ->withMarketplaceAccountId($this->accountId)
                ->withSourceRowId('RET-PENDING-WITH-RETURN')
                ->withPostingNumber('PENDING-WITH-RETURN')
                ->withOrderNumber('PENDING-WITH-RETURN')
                ->withMarketplaceSku('PENDING-WITH-RETURN')
                ->withReturnReasonName('Новая причина при отстающем статусе')
                ->build(),
        ]);

        $row = $this->rows()['PENDING-WITH-RETURN'];
        self::assertSame(4, $row->orderedQuantity);
        self::assertSame(0, $row->resolvedQuantity);
        self::assertNull($row->projectedBuyoutQuantity);
        self::assertNull($row->projectedBuyoutRateBps);
    }

    public function testUnestimatedUnitsAreExcludedUpToTenPercentAndDoNotNullTheSummary(): void
    {
        // Штука без оценки — закрытая отмена без истории: исход unknown.
        $facts = [
            $this->sale($this->accountId, 'MIXED-PENDING', 'MIXED-PENDING', 'MIXED', 'awaiting_packaging', 19, '2026-08-29'),
            $this->sale($this->accountId, 'MIXED-UNKNOWN', 'MIXED-UNKNOWN', 'MIXED', 'cancelled', 1, '2026-08-29'),
            $this->sale($this->accountId, 'MOSTLY-PENDING', 'MOSTLY-PENDING', 'MOSTLY-UNKNOWN', 'awaiting_packaging', 8, '2026-08-29'),
            $this->sale($this->accountId, 'MOSTLY-UNKNOWN', 'MOSTLY-UNKNOWN', 'MOSTLY-UNKNOWN', 'cancelled', 1, '2026-08-29'),
        ];
        $this->sales()->upsertAll($facts);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), [
            $this->postingStatus($this->accountId, 'MIXED-PENDING', 'MIXED-PENDING', 'awaiting_packaging', '2026-08-29 09:00:00'),
            $this->postingStatus($this->accountId, 'MIXED-UNKNOWN', 'MIXED-UNKNOWN', 'cancelled', '2026-08-29 10:00:00'),
            $this->postingStatus($this->accountId, 'MOSTLY-PENDING', 'MOSTLY-PENDING', 'awaiting_packaging', '2026-08-29 09:00:00'),
            $this->postingStatus($this->accountId, 'MOSTLY-UNKNOWN', 'MOSTLY-UNKNOWN', 'cancelled', '2026-08-29 10:00:00'),
        ]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $from = new \DateTimeImmutable('2026-08-29');
        $asOf = new \DateTimeImmutable('2026-08-30T12:00:00Z');
        $rows = [];
        foreach ((new BuyoutForecastQuery($connection))->build($this->companyId->toRfc4122(), $from, $from, $asOf, 50, null)->executeQuery()->fetchAllAssociative() as $rawRow) {
            $row = BuyoutForecastQuery::mapRow($rawRow);
            $rows[$row->marketplaceSku] = $row;
        }

        // 1 из 20 без оценки (5%): ставка по 19 штукам со ставкой кабинета
        // 80% до передачи — 15,2 / 15,2; количество 15,2 × 20 / 19 = 16.
        self::assertSame(10000, $rows['MIXED']->projectedBuyoutRateBps);
        self::assertSame(16, $rows['MIXED']->projectedBuyoutQuantity);
        // 1 из 9 (11%) — больше порога.
        self::assertNull($rows['MOSTLY-UNKNOWN']->projectedBuyoutRateBps);
        self::assertNull($rows['MOSTLY-UNKNOWN']->projectedBuyoutQuantity);

        // Сводка по штукам: 2 из 29 (6,9%) — есть, хотя один SKU без прогноза;
        // 21,6 × 29 / 27 = 23,2.
        $summaryRow = (new BuyoutForecastSummaryQuery($connection, new BuyoutForecastQuery($connection)))
            ->build($this->companyId->toRfc4122(), $from, $from, $asOf)
            ->executeQuery()
            ->fetchAssociative();
        self::assertNotFalse($summaryRow);
        $summary = BuyoutForecastSummaryQuery::mapRow($summaryRow);
        self::assertSame(10000, $summary->projectedBuyoutRateBps);
        self::assertSame(23, $summary->projectedBuyoutQuantity);

        // Дневной ряд считается тем же правилом.
        $daily = array_map(BuyoutDailyQuery::mapRow(...), (new BuyoutDailyQuery($connection))
            ->build($this->companyId->toRfc4122(), 'MIXED', $from, $from, $asOf)
            ->executeQuery()
            ->fetchAllAssociative());
        self::assertCount(1, $daily);
        self::assertSame(10000, $daily[0]->projectedBuyoutRateBps);
        self::assertSame(16, $daily[0]->projectedBuyoutQuantity);
    }

    public function testHandedOverRateFollowsDaysInTransitCurve(): void
    {
        // Медленная часть обучения: 10 D и 30 T2, закрыты через 5 дней
        // 1 час после передачи. Вместе с 24 быстрыми D из setUp:
        // выкуп(0) = 34/64, выкуп(1..5) = 10/40, дальше — откат к 5.
        $facts = [];
        $statuses = [];
        for ($index = 1; $index <= 40; ++$index) {
            $posting = 'SLOW-'.$index;
            $terminal = $index <= 10 ? 'delivered' : 'cancelled';
            $facts[] = $this->sale($this->accountId, $posting, $posting, 'SLOW', $terminal, 1, '2026-08-01');
            $statuses[] = $this->postingStatus($this->accountId, $posting, $posting, 'delivering', '2026-08-01 10:00:00');
            $statuses[] = $this->postingStatus($this->accountId, $posting, $posting, $terminal, '2026-08-06 11:00:00');
        }
        // В пути на дату прогноза: 3 дня 1 час и 2 часа после передачи.
        $facts[] = $this->sale($this->accountId, 'IN-TRANSIT-LONG', 'IN-TRANSIT-LONG', 'IN-TRANSIT-LONG', 'delivering', 10, '2026-08-27');
        $statuses[] = $this->postingStatus($this->accountId, 'IN-TRANSIT-LONG', 'IN-TRANSIT-LONG', 'delivering', '2026-08-27 11:00:00');
        $facts[] = $this->sale($this->accountId, 'IN-TRANSIT-FRESH', 'IN-TRANSIT-FRESH', 'IN-TRANSIT-FRESH', 'delivering', 10, '2026-08-30');
        $statuses[] = $this->postingStatus($this->accountId, 'IN-TRANSIT-FRESH', 'IN-TRANSIT-FRESH', 'delivering', '2026-08-30 10:00:00');
        $this->sales()->upsertAll($facts);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), $statuses);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $rows = [];
        foreach ((new BuyoutForecastQuery($connection))->build(
            $this->companyId->toRfc4122(),
            new \DateTimeImmutable('2026-08-27'),
            new \DateTimeImmutable('2026-08-30'),
            new \DateTimeImmutable('2026-08-30T12:00:00Z'),
            50,
            null,
        )->executeQuery()->fetchAllAssociative() as $rawRow) {
            $row = BuyoutForecastQuery::mapRow($rawRow);
            $rows[$row->marketplaceSku] = $row;
        }

        // Только что переданная: ставка кабинета после передачи 34/64.
        self::assertSame(5313, $rows['IN-TRANSIT-FRESH']->projectedBuyoutRateBps);
        self::assertSame(5, $rows['IN-TRANSIT-FRESH']->projectedBuyoutQuantity);
        // 3 дня в пути: 34/64 × (10/40) / (34/64) = 25%.
        self::assertSame(2500, $rows['IN-TRANSIT-LONG']->projectedBuyoutRateBps);
        self::assertSame(3, $rows['IN-TRANSIT-LONG']->projectedBuyoutQuantity);

        // Дневной ряд считается той же кривой.
        $daily = array_map(BuyoutDailyQuery::mapRow(...), (new BuyoutDailyQuery($connection))
            ->build($this->companyId->toRfc4122(), 'IN-TRANSIT-LONG', new \DateTimeImmutable('2026-08-27'), new \DateTimeImmutable('2026-08-27'), new \DateTimeImmutable('2026-08-30T12:00:00Z'))
            ->executeQuery()
            ->fetchAllAssociative());
        self::assertCount(1, $daily);
        self::assertSame(2500, $daily[0]->projectedBuyoutRateBps);
    }

    public function testHandedOverUnitWithoutRateAddsNoExpectedBuyout(): void
    {
        // Кабинет, обучение которого — одни T1: ставка до передачи 0,
        // ставки после передачи нет (D + T2 + P = 0).
        $account = Uuid::v7();
        $facts = [];
        $statuses = [];
        $returns = [];
        for ($index = 1; $index <= 30; ++$index) {
            $posting = 'ONLY-T1-'.$index;
            $facts[] = $this->sale($account, $posting, $posting, 'ONLY-T1-TRAIN', 'cancelled', 1, '2026-08-01');
            $statuses[] = $this->postingStatus($account, $posting, $posting, 'awaiting_packaging', '2026-08-02 00:00:00');
            $statuses[] = $this->postingStatus($account, $posting, $posting, 'cancelled', '2026-08-02 01:00:00');
            $returns[] = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
                ->withCompanyId($this->companyId)
                ->withMarketplaceAccountId($account)
                ->withSourceRowId('RET-'.$posting)
                ->withPostingNumber($posting)
                ->withOrderNumber($posting)
                ->withMarketplaceSku('ONLY-T1-TRAIN')
                ->withReturnReasonName('Покупатель отменил заказ')
                ->build();
        }
        // 19 штук до передачи (ставка 0) и 1 после передачи без ставки — 5%.
        $facts[] = $this->sale($account, 'ONLY-T1-PENDING', 'ONLY-T1-PENDING', 'ONLY-T1', 'awaiting_packaging', 19, '2026-08-30');
        $statuses[] = $this->postingStatus($account, 'ONLY-T1-PENDING', 'ONLY-T1-PENDING', 'awaiting_packaging', '2026-08-30 09:00:00');
        $facts[] = $this->sale($account, 'ONLY-T1-SHIPPED', 'ONLY-T1-SHIPPED', 'ONLY-T1', 'delivering', 1, '2026-08-30');
        $statuses[] = $this->postingStatus($account, 'ONLY-T1-SHIPPED', 'ONLY-T1-SHIPPED', 'delivering', '2026-08-30 09:00:00');
        $this->sales()->upsertAll($facts);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), $statuses);
        $this->returns()->upsertAll($returns);

        $row = $this->rows()['ONLY-T1'];
        self::assertSame(0, $row->projectedBuyoutQuantity);
        self::assertNull($row->projectedBuyoutRateBps);
    }

    private function seedTrainingAndCurrentCohort(): void
    {
        $facts = [];
        $statuses = [];
        $returns = [];

        // Отдельные 30 posting дают measured p95=1h, но лежат вне
        // 30-дневного training window и не меняют baseline TARGET.
        for ($index = 1; $index <= 30; ++$index) {
            $posting = 'MAT-'.$index;
            $facts[] = $this->sale($this->accountId, $posting, $posting, 'MATURITY', 'delivered', 1, '2026-06-01');
            $statuses[] = $this->postingStatus($this->accountId, $posting, $posting, 'delivering', '2026-06-02 00:00:00');
            $statuses[] = $this->postingStatus($this->accountId, $posting, $posting, 'delivered', '2026-06-02 01:00:00');
        }

        // SKU baseline: 24 D + 6 T1 = 80% до handover; после handover
        // T1-риск снят, поэтому D/(D+T2+P)=100%.
        for ($index = 1; $index <= 24; ++$index) {
            $posting = 'TRAIN-D-'.$index;
            $facts[] = $this->sale($this->accountId, $posting, $posting, 'TARGET', 'delivered', 1, '2026-08-01');
            $statuses[] = $this->postingStatus($this->accountId, $posting, $posting, 'delivering', '2026-08-02 00:00:00');
            $statuses[] = $this->postingStatus($this->accountId, $posting, $posting, 'delivered', '2026-08-02 01:00:00');
        }
        for ($index = 1; $index <= 6; ++$index) {
            $posting = 'TRAIN-T1-'.$index;
            $facts[] = $this->sale($this->accountId, $posting, $posting, 'TARGET', 'cancelled', 1, '2026-08-01');
            $statuses[] = $this->postingStatus($this->accountId, $posting, $posting, 'awaiting_packaging', '2026-08-02 00:00:00');
            $statuses[] = $this->postingStatus($this->accountId, $posting, $posting, 'cancelled', '2026-08-02 01:00:00');
            $returns[] = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
                ->withCompanyId($this->companyId)
                ->withMarketplaceAccountId($this->accountId)
                ->withSourceRowId('RET-'.$posting)
                ->withPostingNumber($posting)
                ->withOrderNumber($posting)
                ->withMarketplaceSku('TARGET')
                ->withReturnReasonName('Покупатель отменил заказ')
                ->build();
        }

        $facts[] = $this->sale($this->accountId, 'CURRENT-TARGET', 'CURRENT-TARGET', 'TARGET', 'awaiting_packaging', 10, '2026-08-30');
        $statuses[] = $this->postingStatus($this->accountId, 'CURRENT-TARGET', 'CURRENT-TARGET', 'awaiting_packaging', '2026-08-30 09:00:00');
        $facts[] = $this->sale($this->accountId, 'CURRENT-SPARSE', 'CURRENT-SPARSE', 'SPARSE', 'awaiting_packaging', 5, '2026-08-30');
        $statuses[] = $this->postingStatus($this->accountId, 'CURRENT-SPARSE', 'CURRENT-SPARSE', 'awaiting_packaging', '2026-08-30 09:00:00');

        $facts[] = $this->sale($this->noDataAccountId, 'CURRENT-NO-DATA', 'CURRENT-NO-DATA', 'NO-DATA', 'awaiting_packaging', 7, '2026-08-30');
        $statuses[] = $this->postingStatus($this->noDataAccountId, 'CURRENT-NO-DATA', 'CURRENT-NO-DATA', 'awaiting_packaging', '2026-08-30 09:00:00');

        $facts[] = $this->sale($this->accountId, 'CURRENT-T1', 'CURRENT-T1', 'TERMINAL-EXCLUDED', 'cancelled', 2, '2026-08-30');
        $statuses[] = $this->postingStatus($this->accountId, 'CURRENT-T1', 'CURRENT-T1', 'awaiting_packaging', '2026-08-30 09:00:00');
        $statuses[] = $this->postingStatus($this->accountId, 'CURRENT-T1', 'CURRENT-T1', 'cancelled', '2026-08-30 10:00:00');
        $returns[] = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withSourceRowId('RET-CURRENT-T1')
            ->withPostingNumber('CURRENT-T1')
            ->withOrderNumber('CURRENT-T1')
            ->withMarketplaceSku('TERMINAL-EXCLUDED')
            ->withReturnReasonName('Покупатель отменил заказ')
            ->withQuantity(2)
            ->build();
        $facts[] = $this->sale($this->accountId, 'CURRENT-R', 'CURRENT-R', 'TERMINAL-EXCLUDED', 'delivered', 3, '2026-08-30');
        $statuses[] = $this->postingStatus($this->accountId, 'CURRENT-R', 'CURRENT-R', 'delivering', '2026-08-30 09:00:00');
        $statuses[] = $this->postingStatus($this->accountId, 'CURRENT-R', 'CURRENT-R', 'delivered', '2026-08-30 10:00:00');
        $returns[] = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withSourceRowId('RET-CURRENT-R')
            ->withPostingNumber('CURRENT-R')
            ->withOrderNumber('CURRENT-R')
            ->withMarketplaceSku('TERMINAL-EXCLUDED')
            ->withReturnType('ClientReturn')
            ->withQuantity(3)
            ->build();

        $this->sales()->upsertAll($facts);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), $statuses);
        $this->returns()->upsertAll($returns);
    }

    private function sale(
        Uuid $accountId,
        string $posting,
        string $order,
        string $sku,
        string $status,
        int $quantity,
        string $businessDate,
    ): \App\Ingestion\Domain\SalesFact {
        return SalesFactBuilder::aSalesFact()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($accountId)
            ->withSourceRowId($posting.'|'.$sku)
            ->withPostingNumber($posting)
            ->withOrderNumber($order)
            ->withMarketplaceSku($sku)
            ->withStatus($status)
            ->withQuantity($quantity)
            ->withBusinessDate(new \DateTimeImmutable($businessDate))
            ->build();
    }

    private function postingStatus(
        Uuid $accountId,
        string $posting,
        string $order,
        string $status,
        string $observedAt,
    ): \App\Ingestion\Domain\MarketplacePostingStatus {
        return MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($accountId)
            ->withPostingNumber($posting)
            ->withOrderNumber($order)
            ->withStatus($status)
            ->withObservedAt(new \DateTimeImmutable($observedAt))
            ->withRawDocumentId(Uuid::v7())
            ->build();
    }

    /**
     * @return array<string, BuyoutForecastRow>
     */
    private function rows(): array
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $query = new BuyoutForecastQuery($connection);
        $rawRows = $query->build(
            companyId: $this->companyId->toRfc4122(),
            from: new \DateTimeImmutable('2026-08-30'),
            to: new \DateTimeImmutable('2026-08-30'),
            asOf: new \DateTimeImmutable('2026-08-30T12:00:00Z'),
            limit: 50,
            cursor: null,
        )->executeQuery()->fetchAllAssociative();
        $rows = [];
        foreach ($rawRows as $rawRow) {
            $row = BuyoutForecastQuery::mapRow($rawRow);
            $rows[$row->marketplaceSku] = $row;
        }

        return $rows;
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
