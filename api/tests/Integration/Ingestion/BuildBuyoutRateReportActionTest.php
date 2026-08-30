<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\BuildBuyoutRateReportAction;
use App\Ingestion\Domain\MarketplacePostingStatusRepository;
use App\Ingestion\Domain\MarketplaceReturnFactRepository;
use App\Ingestion\Domain\SalesFact;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplacePostingStatusWriter;
use App\Ingestion\Infrastructure\Persistence\DoctrineSalesFactWriter;
use App\Ingestion\Infrastructure\Query\BuyoutForecastQuery;
use App\Ingestion\Infrastructure\Query\BuyoutForecastSummaryQuery;
use App\Ingestion\Infrastructure\Query\BuyoutRateQuery;
use App\Tests\Support\Builder\MarketplacePostingStatusBuilder;
use App\Tests\Support\Builder\MarketplaceReturnFactBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bridge\Doctrine\Middleware\Debug\Middleware as DebugMiddleware;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BuildBuyoutRateReportActionTest extends KernelTestCase
{
    private Uuid $companyId;
    private Uuid $accountId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->companyId = Uuid::v7();
        $this->accountId = Uuid::v7();
        $this->seedMaturitySample();
        $this->seedReportCohort();
    }

    public function testReportIsQuantityWeightedTenantScopedAndUsesNullableDenominators(): void
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $plannerSettings = [
            'jit' => $connection->fetchOne('SHOW jit'),
            'enable_nestloop' => $connection->fetchOne('SHOW enable_nestloop'),
            'statement_timeout' => $connection->fetchOne('SHOW statement_timeout'),
        ];
        $report = $this->report(new \DateTimeImmutable('2026-08-02T22:00:01Z'));

        self::assertSame($plannerSettings['jit'], $connection->fetchOne('SHOW jit'));
        self::assertSame($plannerSettings['enable_nestloop'], $connection->fetchOne('SHOW enable_nestloop'));
        self::assertSame($plannerSettings['statement_timeout'], $connection->fetchOne('SHOW statement_timeout'));

        self::assertNull($report->nextCursor);
        self::assertCount(2, $report->items);
        $bySku = [];
        foreach ($report->items as $item) {
            $bySku[$item->marketplaceSku] = $item;
        }

        $skuA = $bySku['SKU-A'];
        self::assertSame(26, $skuA->orderedQuantity);
        self::assertSame(2, $skuA->t1Quantity);
        self::assertSame(10, $skuA->deliveredQuantity);
        self::assertSame(1, $skuA->t2Quantity);
        self::assertSame(1, $skuA->partialReturnQuantity);
        self::assertSame(2, $skuA->clientReturnQuantity);
        self::assertSame(10, $skuA->unresolvedQuantity);
        self::assertSame(7143, $skuA->conversionRateBps);
        self::assertSame(8333, $skuA->actualBuyoutRateBps);
        self::assertSame(6154, $skuA->resolutionRateBps);
        self::assertSame(769, $skuA->t1RateBps);
        self::assertSame(385, $skuA->t2RateBps);
        self::assertSame(385, $skuA->partialReturnRateBps);
        self::assertSame('mature', $skuA->maturityStatus);

        $zeroDenominator = $bySku['SKU-Z'];
        self::assertSame(2, $zeroDenominator->orderedQuantity);
        self::assertSame(1, $zeroDenominator->clientReturnQuantity);
        self::assertSame(1, $zeroDenominator->unresolvedQuantity);
        self::assertNull($zeroDenominator->conversionRateBps);
        self::assertNull($zeroDenominator->actualBuyoutRateBps);
        self::assertSame(5000, $zeroDenominator->resolutionRateBps);
    }

    public function testMaturityUsesStrictAgeGreaterThanP95(): void
    {
        $onBoundary = $this->report(new \DateTimeImmutable('2026-08-02T22:00:00Z'));
        $afterBoundary = $this->report(new \DateTimeImmutable('2026-08-02T22:00:01Z'));

        self::assertSame('preliminary', $onBoundary->items[0]->maturityStatus);
        self::assertSame('mature', $afterBoundary->items[0]->maturityStatus);
    }

    public function testEmptyPeriodKeepsAllRatesUnknownInsteadOfInventingZeroPercent(): void
    {
        $report = ($this->action())(
            companyId: $this->companyId->toRfc4122(),
            from: new \DateTimeImmutable('2026-09-01'),
            to: new \DateTimeImmutable('2026-09-30'),
            asOf: new \DateTimeImmutable('2026-10-01T00:00:00Z'),
            limit: 50,
            cursor: null,
        );

        self::assertSame([], $report->items);
        self::assertSame(0, $report->summary->orderedQuantity);
        self::assertSame(0, $report->summary->resolvedQuantity);
        self::assertNull($report->summary->projectedBuyoutQuantity);
        self::assertNull($report->summary->projectedBuyoutRateBps);
        self::assertNull($report->summary->resolutionRateBps);
    }

    public function testOwnsRepeatableReadTransactionWithoutAnOuterTransaction(): void
    {
        /** @var Connection $testConnection */
        $testConnection = self::getContainer()->get(Connection::class);
        $connection = DriverManager::getConnection($testConnection->getParams());
        $forecast = new BuyoutForecastQuery($connection);
        $action = new BuildBuyoutRateReportAction(
            $connection,
            new BuyoutRateQuery($connection),
            $forecast,
            new BuyoutForecastSummaryQuery($connection, $forecast),
        );

        try {
            $report = $action(
                companyId: Uuid::v7()->toRfc4122(),
                from: new \DateTimeImmutable('2026-09-01'),
                to: new \DateTimeImmutable('2026-09-30'),
                asOf: new \DateTimeImmutable('2026-10-01T00:00:00Z'),
                limit: 50,
                cursor: null,
            );

            self::assertSame([], $report->items);
            self::assertSame(0, $report->summary->orderedQuantity);
            self::assertFalse($connection->isTransactionActive());
        } finally {
            $connection->close();
        }
    }

    public function testNonEmptyReportUsesTwoDataQueriesRegardlessOfPageSize(): void
    {
        [$connection, $debugData] = $this->standaloneConnection();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $posting = 'QUERY-COUNT-POSTING';
        $order = 'QUERY-COUNT-ORDER';
        $sku = 'QUERY-COUNT-SKU';
        $fact = SalesFactBuilder::aSalesFact()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withSourceRowId($posting.'|'.$sku)
            ->withPostingNumber($posting)
            ->withOrderNumber($order)
            ->withMarketplaceSku($sku)
            ->withStatus('delivered')
            ->withBusinessDate(new \DateTimeImmutable('2026-08-01'))
            ->build();
        $statuses = [
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
                ->withCompanyId($companyId)
                ->withMarketplaceAccountId($accountId)
                ->withPostingNumber($posting)
                ->withOrderNumber($order)
                ->withStatus('delivering')
                ->withObservedAt(new \DateTimeImmutable('2026-08-01 10:00:00'))
                ->build(),
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
                ->withCompanyId($companyId)
                ->withMarketplaceAccountId($accountId)
                ->withPostingNumber($posting)
                ->withOrderNumber($order)
                ->withStatus('delivered')
                ->withObservedAt(new \DateTimeImmutable('2026-08-01 11:00:00'))
                ->build(),
        ];
        (new DoctrineSalesFactWriter($connection))->upsertAll([$fact]);
        (new DoctrineMarketplacePostingStatusWriter($connection))->recordChanged($companyId->toRfc4122(), $statuses);

        try {
            foreach ([1, 200] as $limit) {
                $debugData->reset();
                ($this->actionFor($connection))(
                    companyId: $companyId->toRfc4122(),
                    from: new \DateTimeImmutable('2026-08-01'),
                    to: new \DateTimeImmutable('2026-08-02'),
                    asOf: new \DateTimeImmutable('2026-08-02T22:00:01Z'),
                    limit: $limit,
                    cursor: null,
                );
                self::assertCount(2, self::buyoutDataQueries($debugData));
            }
        } finally {
            $connection->executeStatement('DELETE FROM marketplace_posting_status WHERE company_id = ?', [$companyId->toRfc4122()]);
            $connection->executeStatement('DELETE FROM sales_fact WHERE company_id = ?', [$companyId->toRfc4122()]);
            $connection->close();
        }
    }

    public function testEmptyReportUsesOneBoundedSummaryFallbackQuery(): void
    {
        [$connection, $debugData] = $this->standaloneConnection();
        try {
            $debugData->reset();
            ($this->actionFor($connection))(
                companyId: Uuid::v7()->toRfc4122(),
                from: new \DateTimeImmutable('2026-09-01'),
                to: new \DateTimeImmutable('2026-09-30'),
                asOf: new \DateTimeImmutable('2026-10-01T00:00:00Z'),
                limit: 50,
                cursor: null,
            );

            self::assertCount(3, self::buyoutDataQueries($debugData));
        } finally {
            $connection->close();
        }
    }

    private function seedMaturitySample(): void
    {
        $facts = [];
        $statuses = [];
        for ($index = 1; $index <= 30; ++$index) {
            $posting = 'TRAIN-'.$index;
            $facts[] = $this->sale($posting, 'TRAIN-'.$index, 'TRAIN', 'delivered', 1, '2026-06-01');
            $statuses[] = $this->postingStatus($posting, 'TRAIN-'.$index, 'delivering', '2026-06-02 00:00:00');
            $statuses[] = $this->postingStatus($posting, 'TRAIN-'.$index, 'delivered', '2026-06-02 01:00:00');
        }
        $this->sales()->upsertAll($facts);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), $statuses);
    }

    private function seedReportCohort(): void
    {
        $facts = [
            $this->sale('P-SIBLING', 'ORDER-P', 'SKU-A', 'delivered', 10),
            $this->sale('P-REFUSED', 'ORDER-P', 'SKU-A', 'cancelled', 1),
            $this->sale('T1', 'ORDER-T1', 'SKU-A', 'cancelled', 2),
            $this->sale('T2', 'ORDER-T2', 'SKU-A', 'cancelled', 1),
            $this->sale('R', 'ORDER-R', 'SKU-A', 'delivered', 2),
            $this->sale('U', 'ORDER-U', 'SKU-A', 'awaiting_packaging', 10),
            $this->sale('R-Z', 'ORDER-R-Z', 'SKU-Z', 'delivered', 1),
            $this->sale('U-Z', 'ORDER-U-Z', 'SKU-Z', 'awaiting_packaging', 1),
            // Обе границы периода проверяются данными, которые не должны
            // попасть в aggregate.
            $this->sale('BEFORE', 'ORDER-BEFORE', 'SKU-A', 'delivered', 100, '2026-07-31'),
            $this->sale('AFTER', 'ORDER-AFTER', 'SKU-A', 'delivered', 100, '2026-08-03'),
        ];
        $statuses = [
            $this->postingStatus('P-SIBLING', 'ORDER-P', 'delivering', '2026-08-01 00:00:00'),
            $this->postingStatus('P-SIBLING', 'ORDER-P', 'delivered', '2026-08-01 01:00:00'),
            $this->postingStatus('P-REFUSED', 'ORDER-P', 'cancelled', '2026-08-01 01:00:00'),
            $this->postingStatus('T1', 'ORDER-T1', 'awaiting_packaging', '2026-08-01 00:00:00'),
            $this->postingStatus('T1', 'ORDER-T1', 'cancelled', '2026-08-01 01:00:00'),
            $this->postingStatus('T2', 'ORDER-T2', 'delivering', '2026-08-01 00:00:00'),
            $this->postingStatus('T2', 'ORDER-T2', 'cancelled', '2026-08-01 01:00:00'),
            $this->postingStatus('R', 'ORDER-R', 'delivering', '2026-08-01 00:00:00'),
            $this->postingStatus('R', 'ORDER-R', 'delivered', '2026-08-01 01:00:00'),
            $this->postingStatus('U', 'ORDER-U', 'awaiting_packaging', '2026-08-01 00:00:00'),
            $this->postingStatus('R-Z', 'ORDER-R-Z', 'delivering', '2026-08-01 00:00:00'),
            $this->postingStatus('R-Z', 'ORDER-R-Z', 'delivered', '2026-08-01 01:00:00'),
            $this->postingStatus('U-Z', 'ORDER-U-Z', 'awaiting_packaging', '2026-08-01 00:00:00'),
        ];
        $returns = [
            $this->returnFact('RET-P', 'P-REFUSED', 'ORDER-P', 'SKU-A', 'Cancellation', 'Покупатель отказался при вручении: товар не подошел'),
            $this->returnFact('RET-T1', 'T1', 'ORDER-T1', 'SKU-A', 'Cancellation', 'Покупатель отменил заказ', 2),
            $this->returnFact('RET-R', 'R', 'ORDER-R', 'SKU-A', 'ClientReturn', 'Возврат покупателя', 2),
            $this->returnFact('RET-R-Z', 'R-Z', 'ORDER-R-Z', 'SKU-Z', 'ClientReturn', 'Возврат покупателя'),
        ];
        $this->sales()->upsertAll($facts);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), $statuses);
        $this->returns()->upsertAll($returns);

        // Та же SKU чужой компании не прибавляет 100 к ordered/delivered.
        $foreignCompany = Uuid::v7();
        $foreignAccount = Uuid::v7();
        $foreignFact = SalesFactBuilder::aSalesFact()
            ->withCompanyId($foreignCompany)
            ->withMarketplaceAccountId($foreignAccount)
            ->withSourceRowId('FOREIGN|SKU-A')
            ->withPostingNumber('FOREIGN')
            ->withOrderNumber('FOREIGN')
            ->withMarketplaceSku('SKU-A')
            ->withStatus('delivered')
            ->withQuantity(100)
            ->withBusinessDate(new \DateTimeImmutable('2026-08-02'))
            ->build();
        $foreignStatus = MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($foreignCompany)
            ->withMarketplaceAccountId($foreignAccount)
            ->withPostingNumber('FOREIGN')
            ->withOrderNumber('FOREIGN')
            ->withStatus('delivered')
            ->build();
        $this->sales()->upsertAll([$foreignFact]);
        $this->postingStatuses()->recordChanged($foreignCompany->toRfc4122(), [$foreignStatus]);
    }

    private function sale(
        string $posting,
        string $order,
        string $sku,
        string $status,
        int $quantity,
        string $businessDate = '2026-08-02',
    ): SalesFact {
        return SalesFactBuilder::aSalesFact()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withSourceRowId($posting.'|'.$sku)
            ->withPostingNumber($posting)
            ->withOrderNumber($order)
            ->withMarketplaceSku($sku)
            ->withStatus($status)
            ->withQuantity($quantity)
            ->withBusinessDate(new \DateTimeImmutable($businessDate))
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

    private function report(\DateTimeImmutable $asOf): \App\Ingestion\Application\BuyoutRateReport
    {
        return ($this->action())(
            companyId: $this->companyId->toRfc4122(),
            from: new \DateTimeImmutable('2026-08-01'),
            to: new \DateTimeImmutable('2026-08-02'),
            asOf: $asOf,
            limit: 50,
            cursor: null,
        );
    }

    private function action(): BuildBuyoutRateReportAction
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $this->actionFor($connection);
    }

    private function actionFor(Connection $connection): BuildBuyoutRateReportAction
    {
        $forecast = new BuyoutForecastQuery($connection);

        return new BuildBuyoutRateReportAction(
            $connection,
            new BuyoutRateQuery($connection),
            $forecast,
            new BuyoutForecastSummaryQuery($connection, $forecast),
        );
    }

    /** @return array{Connection, DebugDataHolder} */
    private function standaloneConnection(): array
    {
        /** @var Connection $testConnection */
        $testConnection = self::getContainer()->get(Connection::class);
        $debugData = new DebugDataHolder();
        $configuration = (new Configuration())->setMiddlewares([
            new DebugMiddleware($debugData, null, 'query_count'),
        ]);

        return [DriverManager::getConnection($testConnection->getParams(), $configuration), $debugData];
    }

    /** @return list<array<string, mixed>> */
    private static function buyoutDataQueries(DebugDataHolder $debugData): array
    {
        $queries = [];
        foreach ($debugData->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                if (str_contains((string) ($query['sql'] ?? ''), 'buyout_outcome')) {
                    $queries[] = $query;
                }
            }
        }

        return $queries;
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
