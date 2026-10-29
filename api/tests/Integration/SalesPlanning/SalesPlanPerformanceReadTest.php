<?php

declare(strict_types=1);

namespace App\Tests\Integration\SalesPlanning;

use App\Ingestion\Application\Facade\IngestionFacade;
use App\Ingestion\Application\NetForecast\AggregateNetForecastAction;
use App\Ingestion\Application\NetForecast\NetTrainingSnapshot;
use App\Ingestion\Application\NetForecast\ReadNetForecastAction;
use App\Ingestion\Application\NetForecast\ReadNetTrainingAction;
use App\Ingestion\Domain\MarketplaceDayLoadRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Infrastructure\Config\NetForecastCaptureEpochs;
use App\Ingestion\Infrastructure\Config\NetForecastPublication;
use App\Ingestion\Infrastructure\Query\Buyout\BuyoutMaturityQuery;
use App\Ingestion\Infrastructure\Query\Listings\AccountCatalogSkuSearchQuery;
use App\Ingestion\Infrastructure\Query\Listings\AccountCatalogSkusPresentQuery;
use App\Ingestion\Infrastructure\Query\Listings\ListingSnapshotsQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\GrossTrainingAggregateQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\ReturnTrainingAggregateQuery;
use App\Ingestion\Infrastructure\Query\SalesCohort\SalesCohortCoverageQuery;
use App\Ingestion\Infrastructure\Query\SalesCohort\SalesCohortMetricsQuery;
use App\Ingestion\Infrastructure\Query\SalesCohort\SalesCohortSeriesQuery;
use App\SalesPlanning\Application\ReadSalesPlanPerformanceAction;
use App\SalesPlanning\Application\SalesPlanPerformanceCalculator;
use App\SalesPlanning\Application\SalesPlanPerformanceRequest;
use App\SalesPlanning\Application\SalesPlanPerformanceSnapshot;
use App\SalesPlanning\Domain\SalesPlanMetric;
use App\SalesPlanning\Domain\SalesPlanRepository;
use App\SalesPlanning\Infrastructure\Query\SalesPlanBaselineQuery;
use App\SalesPlanning\Infrastructure\Query\SalesPlanPerformanceQuery;
use App\SalesPlanning\Infrastructure\Query\SalesPlansQuery;
use App\Tests\Support\Builder\MarketplaceDayLoadBuilder;
use App\Tests\Support\Builder\SalesPlanBuilder;
use App\Tests\Support\Builder\SalesPlanLineBuilder;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

#[SkipDatabaseRollback]
final class SalesPlanPerformanceReadTest extends KernelTestCase
{
    /** @return iterable<string, array{bool}> */
    public static function publicationModes(): iterable
    {
        yield 'closed' => [false];
        yield 'enabled without epoch' => [true];
    }

    #[DataProvider('publicationModes')]
    public function testSingleReadAtNearMskMidnightKeepsCurrentAndFutureCohortsNull(bool $enabled): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $repository = self::getContainer()->get(SalesPlanRepository::class);
        self::assertInstanceOf(SalesPlanRepository::class, $repository);
        $loads = self::getContainer()->get(MarketplaceDayLoadRepository::class);
        self::assertInstanceOf(MarketplaceDayLoadRepository::class, $loads);
        $clock = new class implements ClockInterface {
            public int $calls = 0;

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable(1 === ++$this->calls ? '2026-07-10T20:59:59.999999Z' : '2026-07-10T21:00:00.000001Z');
            }
        };
        $aggregate = self::getContainer()->get(AggregateNetForecastAction::class);
        self::assertInstanceOf(AggregateNetForecastAction::class, $aggregate);
        $reader = new ReadNetForecastAction($db, new ReadNetTrainingAction($db, new GrossTrainingAggregateQuery($db), new ReturnTrainingAggregateQuery($db), new NetForecastCaptureEpochs(), $clock), new NetTargetQuery($db), $aggregate);
        $facade = new IngestionFacade(new ListingSnapshotsQuery($db), new AccountCatalogSkuSearchQuery($db), new AccountCatalogSkusPresentQuery($db), new SalesCohortSeriesQuery($db, new SalesCohortMetricsQuery($db), new SalesCohortCoverageQuery($db)), new BuyoutMaturityQuery($db), $clock,
            new NetForecastPublication($enabled ? 'observed_retention_h60_v1' : ''), $reader, new NetTrainingSnapshot($db));
        $plan = SalesPlanBuilder::aSalesPlan()->withPeriod(new \DateTimeImmutable('2026-07-09'), new \DateTimeImmutable('2026-07-11'))->withMetric(SalesPlanMetric::RetainedSales)->build();
        try {
            $lines = SalesPlanLineBuilder::linesOf($plan)->withMarketplaceSku('SKU')->withTotalQuantity(30)->build();
            $plan->approve(Uuid::v7(), new \DateTimeImmutable('2026-07-01'));
            $repository->add($plan, $lines);
            foreach (['2026-07-09', '2026-07-10', '2026-07-11'] as $day) {
                MarketplaceDayLoadBuilder::aMarketplaceDayLoad()->withCompanyId($plan->companyId())->withMarketplaceAccountId($plan->marketplaceAccountId())
                    ->withReportType(MarketplaceReportType::OzonPostingFboList)->withPeriod(new \DateTimeImmutable($day))->completed()->persistWith($loads);
            }
            $action = new ReadSalesPlanPerformanceAction(new SalesPlanPerformanceSnapshot($db), new SalesPlansQuery($db), new SalesPlanBaselineQuery($db), new SalesPlanPerformanceQuery($db), $facade, new SalesPlanPerformanceCalculator());
            $view = $action($plan->companyId()->toRfc4122(), $plan->id()->toRfc4122(), new SalesPlanPerformanceRequest(new \DateTimeImmutable('2026-07-09'), new \DateTimeImmutable('2026-07-11')));
            self::assertSame(1, $clock->calls);
            self::assertSame('2026-07-10T20:59:59.999999+00:00', $view->calculatedAt->format('Y-m-d\TH:i:s.uP'));
            self::assertSame($enabled ? '2026-07-10T20:59:59.999999+00:00' : null, $view->netForecast->readAt?->format('Y-m-d\TH:i:s.uP'));
            self::assertNotNull($view->skus[0]->points[0]->period->cohort);
            foreach ([1, 2] as $index) {
                self::assertNull($view->skus[0]->points[$index]->period->cohort);
                self::assertNull($view->skus[0]->points[$index]->cumulative->cohort);
                self::assertSame('partial', $view->skus[0]->points[$index]->periodCoverage);
            }
            self::assertNull($view->netForecast->epochId);
        } finally {
            foreach (['sales_plan_day_claim', 'sales_plan_line', 'sales_plan', 'marketplace_day_load_receipt', 'marketplace_day_load'] as $table) {
                $db->executeStatement('DELETE FROM '.$table.' WHERE company_id = ?', [$plan->companyId()->toRfc4122()]);
            }
        }
    }

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
            $base->approve(Uuid::v7(), new \DateTimeImmutable());
            $repository->add($base, $lines);
            $ingestion = self::getContainer()->get(IngestionFacade::class);
            self::assertInstanceOf(IngestionFacade::class, $ingestion);
            $action = new ReadSalesPlanPerformanceAction(new SalesPlanPerformanceSnapshot($connection),
                new SalesPlansQuery($connection), new SalesPlanBaselineQuery($connection),
                new SalesPlanPerformanceQuery($connection), $ingestion, new SalesPlanPerformanceCalculator());
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
