<?php

declare(strict_types=1);

namespace App\Tests\Integration\SalesPlanning;

use App\Ingestion\Application\Facade\IngestionFacade;
use App\Ingestion\Application\Facade\SalesCohortRequest;
use App\Ingestion\Domain\MarketplaceDayLoadRepository;
use App\Ingestion\Domain\MarketplacePostingStatusRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\SalesFactRepository;
use App\SalesPlanning\Application\SalesPlanPerformanceReadException;
use App\SalesPlanning\Application\SalesPlanPerformanceSnapshot;
use App\Tests\Support\Builder\MarketplaceDayLoadBuilder;
use App\Tests\Support\Builder\MarketplacePostingStatusBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

#[SkipDatabaseRollback]
final class SalesPlanPerformanceSnapshotTest extends KernelTestCase
{
    public function testSnapshotIsActuallyReadOnlyRepeatableAndConcurrentFactRefreshDoesNotChangeLaterModuleRead(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $other = DriverManager::getConnection($connection->getParams());
        $company = Uuid::v7();
        $account = Uuid::v7();
        $day = new \DateTimeImmutable('2026-07-10');
        $sales = self::getContainer()->get(SalesFactRepository::class);
        self::assertInstanceOf(SalesFactRepository::class, $sales);
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        self::assertInstanceOf(MarketplacePostingStatusRepository::class, $statuses);
        $loads = self::getContainer()->get(MarketplaceDayLoadRepository::class);
        self::assertInstanceOf(MarketplaceDayLoadRepository::class, $loads);
        try {
            $sales->upsertAll([SalesFactBuilder::aSalesFact()->withCompanyId($company)->withMarketplaceAccountId($account)
                ->withMarketplaceSku('SNAPSHOT')->withPostingNumber('SNAPSHOT')->withOrderNumber('SNAPSHOT')->withSourceRowId('SNAPSHOT')
                ->withBusinessDate($day)->withQuantity(2)->build()]);
            $statuses->recordChanged($company->toRfc4122(), [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
                ->withCompanyId($company)->withMarketplaceAccountId($account)->withPostingNumber('SNAPSHOT')->withOrderNumber('SNAPSHOT')
                ->withStatus('delivered')->withObservedAt($day)->build()]);
            MarketplaceDayLoadBuilder::aMarketplaceDayLoad()->withCompanyId($company)->withMarketplaceAccountId($account)
                ->withReportType(MarketplaceReportType::OzonPostingFboList)->withPeriod($day)->completed()->persistWith($loads);
            $ingestion = self::getContainer()->get(IngestionFacade::class);
            self::assertInstanceOf(IngestionFacade::class, $ingestion);
            (new SalesPlanPerformanceSnapshot($connection))->read(static function () use ($connection, $other, $company, $account, $day, $ingestion): void {
                self::assertSame('repeatable read', $connection->fetchOne('SHOW transaction_isolation'));
                self::assertSame('on', $connection->fetchOne('SHOW transaction_read_only'));
                // A real SELECT pins the snapshot before another connection refreshes mutable inputs.
                self::assertSame(2, $connection->fetchOne('SELECT quantity FROM sales_fact WHERE company_id = ? AND source_row_id = ?', [$company->toRfc4122(), 'SNAPSHOT']));
                $other->executeStatement('UPDATE sales_fact SET quantity = 9 WHERE company_id = ? AND source_row_id = ?', [$company->toRfc4122(), 'SNAPSHOT']);
                $batch = $ingestion->salesCohortMetrics($company->toRfc4122(), $account->toRfc4122(), new SalesCohortRequest(['SNAPSHOT'], $day, $day, $day));
                self::assertNotNull($batch->skus[0]->points[0]->period);
                self::assertSame(2, $batch->skus[0]->points[0]->period->rawOrderedQuantity);
            });
            self::assertSame(9, $connection->fetchOne('SELECT quantity FROM sales_fact WHERE company_id = ? AND source_row_id = ?', [$company->toRfc4122(), 'SNAPSHOT']));
            self::assertFalse($connection->isTransactionActive());
            self::assertSame('off', $connection->fetchOne('SHOW transaction_read_only'));
            self::assertSame('read committed', $connection->fetchOne('SHOW transaction_isolation'));
        } finally {
            foreach (['marketplace_posting_status', 'marketplace_day_load', 'sales_fact'] as $table) {
                $connection->executeStatement('DELETE FROM '.$table.' WHERE company_id = ?', [$company->toRfc4122()]);
            }
            $other->close();
        }
    }

    public function testRejectsLowerIsolationOuterTransactionWithoutChangingItsScope(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->beginTransaction();
        try {
            try {
                (new SalesPlanPerformanceSnapshot($connection))->read(static fn (): string => 'unsafe');
                self::fail('READ COMMITTED must not masquerade as a coherent multi-query snapshot.');
            } catch (SalesPlanPerformanceReadException $error) {
                self::assertSame('performance_snapshot_unsupported', $error->errorCode);
            }
            self::assertTrue($connection->isTransactionActive());
            self::assertSame('read committed', $connection->fetchOne('SHOW transaction_isolation'));
            self::assertSame('off', $connection->fetchOne('SHOW transaction_read_only'));
        } finally {
            $connection->rollBack();
        }
    }

    public function testAlreadyStrongReadOnlyOwnerKeepsItsTransactionAndIsolation(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->beginTransaction();
        try {
            $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE, READ ONLY');
            self::assertSame(42, (new SalesPlanPerformanceSnapshot($connection))->read(static fn (): int => 42));
            self::assertTrue($connection->isTransactionActive());
            self::assertSame('serializable', $connection->fetchOne('SHOW transaction_isolation'));
            self::assertSame('on', $connection->fetchOne('SHOW transaction_read_only'));
        } finally {
            $connection->rollBack();
        }
    }

    public function testPostgresRejectsWritesAndRollbackRestoresConnectionAfterFailure(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        try {
            (new SalesPlanPerformanceSnapshot($connection))->read(static fn (): int|string => $connection->executeStatement('DELETE FROM sales_plan WHERE company_id = ?', [Uuid::v7()->toRfc4122()]));
            self::fail('PostgreSQL must enforce read only.');
        } catch (\Doctrine\DBAL\Exception\DriverException $error) {
            self::assertSame('25006', $error->getSQLState());
            self::assertFalse($connection->isTransactionActive());
            self::assertSame('off', $connection->fetchOne('SHOW transaction_read_only'));
        }
    }
}
