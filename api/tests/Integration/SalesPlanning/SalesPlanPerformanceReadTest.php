<?php

declare(strict_types=1);

namespace App\Tests\Integration\SalesPlanning;

use App\SalesPlanning\Application\ReadSalesPlanPerformanceAction;
use App\SalesPlanning\Application\SalesPlanPerformanceRequest;
use App\SalesPlanning\Domain\SalesPlanMetric;
use App\SalesPlanning\Domain\SalesPlanRepository;
use App\Tests\Support\Builder\SalesPlanBuilder;
use App\Tests\Support\Builder\SalesPlanLineBuilder;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[SkipDatabaseRollback]
final class SalesPlanPerformanceReadTest extends KernelTestCase
{
    public function testReadsOriginalGoalAndLeavesUnknownCohortsUnavailable(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $repository = self::getContainer()->get(SalesPlanRepository::class);
        self::assertInstanceOf(SalesPlanRepository::class, $repository);
        $base = SalesPlanBuilder::aSalesPlan()->withMetric(SalesPlanMetric::RetainedSales)->build();
        try {
            $lines = SalesPlanLineBuilder::linesOf($base)->withMarketplaceSku('SKU')->withTotalQuantity(31)->build();
            $base->approve(\Symfony\Component\Uid\Uuid::v7(), new \DateTimeImmutable());
            $repository->add($base, $lines);
            $ingestion = self::getContainer()->get(\App\Ingestion\Application\Facade\IngestionFacade::class);
            self::assertInstanceOf(\App\Ingestion\Application\Facade\IngestionFacade::class, $ingestion);
            $action = new ReadSalesPlanPerformanceAction(new \App\SalesPlanning\Application\SalesPlanPerformanceSnapshot($connection),
                new \App\SalesPlanning\Infrastructure\Query\SalesPlansQuery($connection), new \App\SalesPlanning\Infrastructure\Query\SalesPlanBaselineQuery($connection),
                new \App\SalesPlanning\Infrastructure\Query\SalesPlanPerformanceQuery($connection), $ingestion, new \App\SalesPlanning\Application\SalesPlanPerformanceCalculator());
            self::assertInstanceOf(ReadSalesPlanPerformanceAction::class, $action);
            $view = $action($base->companyId()->toRfc4122(), $base->id()->toRfc4122(), new SalesPlanPerformanceRequest(new \DateTimeImmutable('2026-07-10'), new \DateTimeImmutable('2026-07-11')));
            self::assertSame($base->id()->toRfc4122(), $view->baselinePlanId);
            self::assertSame('2026-07-01', $view->accumulationFrom);
            self::assertSame(1, $view->skus[0]->points[0]->period->planQuantity);
            self::assertSame(10, $view->skus[0]->points[0]->cumulative->planQuantity);
            self::assertNull($view->skus[0]->points[0]->period->actualGoalQuantity);
            self::assertSame('missing', $view->skus[0]->points[0]->periodCoverage);
            self::assertFalse($connection->isTransactionActive());
            self::assertSame('off', $connection->fetchOne('SHOW transaction_read_only'));
        } finally {
            foreach (['sales_plan_day_claim', 'sales_plan_line', 'sales_plan'] as $table) {
                $connection->executeStatement('DELETE FROM '.$table.' WHERE company_id = ?', [$base->companyId()->toRfc4122()]);
            }
        }
    }
}
