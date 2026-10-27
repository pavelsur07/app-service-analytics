<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\MarketplacePostingStatusRepository;
use App\Ingestion\Domain\MarketplaceReturnFactRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Query\SalesCohort\SalesCohortMetricsQuery;
use App\Tests\Support\Builder\MarketplacePostingStatusBuilder;
use App\Tests\Support\Builder\MarketplaceReturnFactBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SalesCohortMetricsQueryTest extends KernelTestCase
{
    public function testSubtractsOnlyConfirmedPreHandoverCancellationsWithoutChangingFacts(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $date = new \DateTimeImmutable('2026-08-01');
        $sale = SalesFactBuilder::aSalesFact()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withMarketplaceSku('COHORT')
            ->withBusinessDate($date);
        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([
            $sale->withSourceRowId('CANCELLED|COHORT')->withPostingNumber('CANCELLED')
                ->withOrderNumber('CANCELLED')->withStatus('cancelled')->withQuantity(10)->build(),
            $sale->withSourceRowId('PENDING|COHORT')->withPostingNumber('PENDING')
                ->withOrderNumber('PENDING')->withStatus('awaiting_packaging')->withQuantity(90)->build(),
            $sale->withCompanyId(Uuid::v7())->withSourceRowId('FOREIGN|COHORT')
                ->withPostingNumber('FOREIGN')->withOrderNumber('FOREIGN')->withQuantity(777)->build(),
            $sale->withMarketplaceAccountId(Uuid::v7())->withSourceRowId('OTHER-ACCOUNT|COHORT')
                ->withPostingNumber('OTHER-ACCOUNT')->withOrderNumber('OTHER-ACCOUNT')->withQuantity(888)->build(),
            $sale->withMarketplaceSku('OUTSIDE')->withSourceRowId('OUTSIDE|OUTSIDE')
                ->withPostingNumber('OUTSIDE')->withOrderNumber('OUTSIDE')->withQuantity(333)->build(),
            $sale->withBusinessDate($date->modify('+1 day'))->withSourceRowId('TOMORROW|COHORT')
                ->withPostingNumber('TOMORROW')->withOrderNumber('TOMORROW')->withQuantity(444)->build(),
        ]);
        $status = MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($companyId->toRfc4122(), [
            $status->withPostingNumber('CANCELLED')->withOrderNumber('CANCELLED')
                ->withStatus('awaiting_packaging')->withObservedAt(new \DateTimeImmutable('2026-08-01 10:00:00'))
                ->withRawDocumentId(Uuid::v7())->build(),
            $status->withPostingNumber('CANCELLED')->withOrderNumber('CANCELLED')
                ->withStatus('cancelled')->withObservedAt(new \DateTimeImmutable('2026-08-02 10:00:00'))
                ->withRawDocumentId(Uuid::v7())->build(),
            $status->withPostingNumber('PENDING')->withOrderNumber('PENDING')
                ->withStatus('awaiting_packaging')->withObservedAt(new \DateTimeImmutable('2026-08-01 10:00:00'))
                ->withRawDocumentId(Uuid::v7())->build(),
        ]);
        /** @var MarketplaceReturnFactRepository $returns */
        $returns = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        $returns->upsertAll([
            MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
                ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withSourceRowId('CANCELLED-RETURN')->withPostingNumber('CANCELLED')
                ->withOrderNumber('CANCELLED')->withMarketplaceSku('COHORT')
                ->withReturnType('Cancellation')->withReturnReasonName('Покупатель отменил заказ')
                ->withQuantity(10)->build(),
        ]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $rawRows = (new SalesCohortMetricsQuery($connection))
            ->build($companyId->toRfc4122(), $accountId->toRfc4122(), ['COHORT'], $date, $date)
            ->executeQuery()->fetchAllAssociative();

        self::assertCount(1, $rawRows);
        $sku = SalesCohortMetricsQuery::mapRow($rawRows[0]);
        self::assertSame('COHORT', $sku->marketplaceSku);
        self::assertCount(1, $sku->days);
        $row = $sku->days[0];
        self::assertSame('COHORT', $row->marketplaceSku);
        self::assertSame('2026-08-01', $row->date);
        self::assertSame(100, $row->rawOrderedQuantity);
        self::assertSame(10, $row->preHandoverCancelledQuantity);
        self::assertSame(90, $row->effectiveOrderedQuantity);
        self::assertSame($row->rawOrderedQuantity, $row->effectiveOrderedQuantity + $row->preHandoverCancelledQuantity);
        self::assertSame(100, $connection->fetchOne(
            'SELECT SUM(quantity) FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ? AND marketplace_sku = ? AND business_date = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122(), 'COHORT', '2026-08-01'],
        ));
    }

    /**
     * @param list<string>                                 $history
     * @param list<array{string, string, int}>             $returnEvidence
     * @param list<array{outcome: ?string, quantity: int}> $expectedAllocations
     */
    #[DataProvider('orderOutcomes')]
    public function testPreservesAllQuantitiesExceptConfirmedT1(
        string $status,
        array $history,
        array $returnEvidence,
        bool $deliveredSibling,
        array $expectedAllocations,
        int $expectedCancelled,
        int $expectedRetained,
    ): void {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $date = new \DateTimeImmutable('2026-08-01');
        $sale = SalesFactBuilder::aSalesFact()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withMarketplaceSku('OUTCOME')->withBusinessDate($date)
            ->withSourceRowId('SUBJECT|OUTCOME')->withPostingNumber('SUBJECT')
            ->withOrderNumber('ORDER')->withStatus($status)->withQuantity(3);
        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $facts = [$sale->build()];
        if ($deliveredSibling) {
            // P requires a delivered sibling; it is outside the requested SKU.
            $facts[] = $sale->withMarketplaceSku('SIBLING')->withSourceRowId('SIBLING|SIBLING')
                ->withPostingNumber('SIBLING')->withStatus('delivered')->withQuantity(1)->build();
        }
        $sales->upsertAll($facts);

        $statusBuilder = MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withPostingNumber('SUBJECT')->withOrderNumber('ORDER');
        $observations = [];
        foreach ($history as $index => $observedStatus) {
            $observations[] = $statusBuilder->withStatus($observedStatus)
                ->withObservedAt(new \DateTimeImmutable(\sprintf('2026-08-%02d 10:00:00', $index + 1)))
                ->withRawDocumentId(Uuid::v7())->build();
        }
        if ($deliveredSibling) {
            $observations[] = $statusBuilder->withPostingNumber('SIBLING')->withStatus('delivered')
                ->withObservedAt(new \DateTimeImmutable('2026-08-03 11:00:00'))
                ->withRawDocumentId(Uuid::v7())->build();
        }
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($companyId->toRfc4122(), $observations);

        $returns = [];
        foreach ($returnEvidence as $index => [$type, $reason, $quantity]) {
            $returns[] = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
                ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withSourceRowId('RETURN-'.$index)->withPostingNumber('SUBJECT')
                ->withOrderNumber('ORDER')->withMarketplaceSku('OUTCOME')
                ->withReturnType($type)->withReturnReasonName($reason)->withQuantity($quantity)->build();
        }
        /** @var MarketplaceReturnFactRepository $returnFacts */
        $returnFacts = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        $returnFacts->upsertAll($returns);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        // Validate fixture evidence against the real allocator, including partial units.
        self::assertSame($expectedAllocations, $connection->fetchAllAssociative(
            'SELECT outcome, quantity FROM buyout_outcome WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? ORDER BY outcome NULLS LAST',
            [$companyId->toRfc4122(), $accountId->toRfc4122(), 'SUBJECT|OUTCOME'],
        ));
        $rows = (new SalesCohortMetricsQuery($connection))
            ->build($companyId->toRfc4122(), $accountId->toRfc4122(), ['OUTCOME'], $date, $date)
            ->executeQuery()->fetchAllAssociative();

        self::assertCount(1, $rows);
        $sku = SalesCohortMetricsQuery::mapRow($rows[0]);
        self::assertSame('OUTCOME', $sku->marketplaceSku);
        self::assertCount(1, $sku->days);
        $day = $sku->days[0];
        self::assertSame('2026-08-01', $day->date);
        self::assertSame(3, $day->rawOrderedQuantity);
        self::assertSame($expectedCancelled, $day->preHandoverCancelledQuantity);
        self::assertSame(3 - $expectedCancelled, $day->effectiveOrderedQuantity);
        self::assertSame($expectedRetained, $day->observedNetSalesQuantity);
        self::assertSame($day->rawOrderedQuantity, $day->effectiveOrderedQuantity + $day->preHandoverCancelledQuantity);
    }

    /**
     * @return iterable<string, array{string, list<string>, list<array{string, string, int}>, bool, list<array{outcome: ?string, quantity: int}>, int, int}>
     */
    public static function orderOutcomes(): iterable
    {
        yield 'active awaiting packaging' => [
            'awaiting_packaging', ['awaiting_packaging'], [], false,
            [['outcome' => null, 'quantity' => 3]], 0, 0,
        ];
        yield 'active in delivery' => [
            'delivering', ['awaiting_packaging', 'delivering'], [], false,
            [['outcome' => null, 'quantity' => 3]], 0, 0,
        ];
        yield 'delivered D' => [
            'delivered', ['delivering', 'delivered'], [], false,
            [['outcome' => 'D', 'quantity' => 3]], 0, 3,
        ];
        yield 'post handover cancellation T2' => [
            'cancelled', ['delivering', 'cancelled'], [], false,
            [['outcome' => 'T2', 'quantity' => 3]], 0, 0,
        ];
        yield 'partial pickup refusal T2' => [
            'cancelled', ['cancelled'], [['Cancellation', 'Покупатель не забрал заказ', 1]], false,
            [['outcome' => 'T2', 'quantity' => 1], ['outcome' => null, 'quantity' => 2]], 0, 0,
        ];
        yield 'partial refusal P with delivered sibling' => [
            'cancelled', ['cancelled'], [['Cancellation', 'Покупатель отказался при вручении: товар не подошел', 1]], true,
            [['outcome' => 'P', 'quantity' => 1], ['outcome' => null, 'quantity' => 2]], 0, 0,
        ];
        yield 'partial client return R preserves delivered remainder' => [
            'delivered', ['delivering', 'delivered'], [['ClientReturn', 'Возврат одной штуки', 1]], false,
            [['outcome' => 'D', 'quantity' => 2], ['outcome' => 'R', 'quantity' => 1]], 0, 2,
        ];
        yield 'terminal unknown without previous history despite known cancellation reason' => [
            'cancelled', ['cancelled'], [['Cancellation', 'Покупатель отменил заказ', 3]], false,
            [['outcome' => null, 'quantity' => 3]], 0, 0,
        ];
        yield 'partial confirmed T1 subtracts only allocated unit' => [
            'cancelled', ['awaiting_packaging', 'cancelled'], [['Cancellation', 'Покупатель отменил заказ', 1]], false,
            [['outcome' => 'T1', 'quantity' => 1], ['outcome' => null, 'quantity' => 2]], 1, 0,
        ];
    }

    public function testOrdersBatchBySkuAndDayWithoutInventingMissingRows(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $sale = SalesFactBuilder::aSalesFact()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withStatus('awaiting_packaging');
        $facts = [];
        foreach ([
            ['BATCH-B', '2026-08-03', 7],
            ['BATCH-A', '2026-08-03', 3],
            ['BATCH-B', '2026-08-01', 5],
            ['BATCH-A', '2026-08-01', 2],
            ['BATCH-A', '2026-08-01', 4],
        ] as $index => [$sku, $date, $quantity]) {
            $facts[] = $sale->withMarketplaceSku($sku)
                ->withBusinessDate(new \DateTimeImmutable($date))->withQuantity($quantity)
                ->withSourceRowId('BATCH-'.$index.'|'.$sku)
                ->withPostingNumber('BATCH-'.$index)->withOrderNumber('BATCH-'.$index)->build();
        }
        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll($facts);
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $rows = (new SalesCohortMetricsQuery($connection))
            ->build($companyId->toRfc4122(), $accountId->toRfc4122(), ['BATCH-B', 'MISSING', 'BATCH-A'],
                new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-03'))
            ->executeQuery()->fetchAllAssociative();

        self::assertCount(2, $rows);
        foreach ($rows as $index => $rawRow) {
            $sku = SalesCohortMetricsQuery::mapRow($rawRow);
            self::assertSame(['BATCH-A', 'BATCH-B'][$index], $sku->marketplaceSku);
            self::assertSame(['2026-08-01', '2026-08-03'], array_column($sku->days, 'date'));
            self::assertSame([[6, 3], [5, 7]][$index], array_column($sku->days, 'rawOrderedQuantity'));
            foreach ($sku->days as $day) {
                self::assertSame($sku->marketplaceSku, $day->marketplaceSku);
                self::assertSame(0, $day->preHandoverCancelledQuantity);
                self::assertSame($day->rawOrderedQuantity, $day->effectiveOrderedQuantity + $day->preHandoverCancelledQuantity);
            }
        }
    }

    public function testAcceptsFiftySkusAndOneHundredEightyFourCalendarDays(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $from = new \DateTimeImmutable('2026-01-01');
        $to = new \DateTimeImmutable('2026-07-03');
        $sale = SalesFactBuilder::aSalesFact()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withStatus('awaiting_packaging')->withQuantity(2);
        $skus = [];
        $facts = [];
        for ($index = 49; $index >= 0; --$index) {
            $sku = \sprintf('BOUNDARY-%02d', $index);
            $skus[] = $sku;
            $dayCount = 0 === $index ? 184 : 1;
            for ($day = $dayCount - 1; $day >= 0; --$day) {
                $posting = $sku.'-'.$day;
                $facts[] = $sale->withMarketplaceSku($sku)
                    ->withBusinessDate($from->modify('+'.$day.' days'))
                    ->withSourceRowId($posting.'|'.$sku)
                    ->withPostingNumber($posting)->withOrderNumber($posting)->build();
            }
        }
        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll($facts);
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $rows = (new SalesCohortMetricsQuery($connection))
            ->build($companyId->toRfc4122(), $accountId->toRfc4122(), $skus, $from, $to)
            ->executeQuery()->fetchAllAssociative();

        self::assertCount(50, $rows);
        sort($skus);
        self::assertSame($skus, array_column($rows, 'marketplace_sku'));
        foreach ($rows as $index => $rawRow) {
            $sku = SalesCohortMetricsQuery::mapRow($rawRow);
            self::assertCount(0 === $index ? 184 : 1, $sku->days);
            self::assertSame('2026-01-01', $sku->days[0]->date);
            foreach ($sku->days as $dayIndex => $day) {
                self::assertSame($from->modify('+'.$dayIndex.' days')->format('Y-m-d'), $day->date);
                self::assertSame($sku->marketplaceSku, $day->marketplaceSku);
                self::assertSame(2, $day->rawOrderedQuantity);
                self::assertSame(0, $day->preHandoverCancelledQuantity);
                self::assertSame(2, $day->effectiveOrderedQuantity);
                self::assertSame($day->rawOrderedQuantity, $day->effectiveOrderedQuantity + $day->preHandoverCancelledQuantity);
            }
        }
        self::assertSame('2026-07-03', SalesCohortMetricsQuery::mapRow($rows[0])->days[183]->date);
    }

    #[DataProvider('calendarRequests')]
    public function testPreservesCalendarDatesDespiteTimeAndTimezone(string $from, string $to): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $sale = SalesFactBuilder::aSalesFact()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withMarketplaceSku('CALENDAR')->withStatus('awaiting_packaging');
        $facts = [];
        foreach (['2026-07-31', '2026-08-01', '2026-08-02'] as $index => $date) {
            $facts[] = $sale->withBusinessDate(new \DateTimeImmutable($date))
                ->withSourceRowId('CALENDAR-'.$index.'|CALENDAR')
                ->withPostingNumber('CALENDAR-'.$index)->withOrderNumber('CALENDAR-'.$index)->build();
        }
        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll($facts);
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $rows = (new SalesCohortMetricsQuery($connection))
            ->build($companyId->toRfc4122(), $accountId->toRfc4122(), ['CALENDAR'],
                new \DateTimeImmutable($from), new \DateTimeImmutable($to))
            ->executeQuery()->fetchAllAssociative();

        self::assertCount(1, $rows);
        $sku = SalesCohortMetricsQuery::mapRow($rows[0]);
        self::assertSame('CALENDAR', $sku->marketplaceSku);
        self::assertCount(1, $sku->days);
        self::assertSame('2026-08-01', $sku->days[0]->date);
        self::assertSame(1, $sku->days[0]->rawOrderedQuantity);
        self::assertSame(0, $sku->days[0]->preHandoverCancelledQuantity);
        self::assertSame(1, $sku->days[0]->effectiveOrderedQuantity);
    }

    /** @return iterable<string, array{string, string}> */
    public static function calendarRequests(): iterable
    {
        yield 'UTC conversion would widen both boundaries' => [
            '2026-08-01 00:01:00 +14:00', '2026-08-01 23:59:00 -12:00',
        ];
        yield 'same calendar day despite reversed instants' => [
            '2026-08-01 23:59:00 -12:00', '2026-08-01 00:01:00 +14:00',
        ];
    }

    /** @param list<string> $skus */
    #[DataProvider('invalidRequests')]
    public function testRejectsInvalidOrUnboundedRequests(array $skus, string $from, string $to): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $query = new SalesCohortMetricsQuery($connection);

        $this->expectException(\InvalidArgumentException::class);
        $query->build(Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), $skus, new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }

    /** @return iterable<string, array{list<string>, string, string}> */
    public static function invalidRequests(): iterable
    {
        yield 'no SKU' => [[], '2026-01-01', '2026-01-01'];
        yield 'too many SKUs' => [array_map(strval(...), range(1, 51)), '2026-01-01', '2026-01-01'];
        yield 'blank SKU' => [[' '], '2026-01-01', '2026-01-01'];
        yield 'reverse dates' => [['SKU'], '2026-01-02', '2026-01-01'];
        yield '185 days' => [['SKU'], '2026-01-01', '2026-07-04'];
    }
}
