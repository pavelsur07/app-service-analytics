<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\Facade\IngestionFacade;
use App\Ingestion\Application\Facade\SalesCohortComponents;
use App\Ingestion\Application\Facade\SalesCohortGrouping;
use App\Ingestion\Application\Facade\SalesCohortRequest;
use App\Ingestion\Application\NetForecast\NetTrainingSnapshot;
use App\Ingestion\Application\NetForecast\ReadNetForecastAction;
use App\Ingestion\Domain\MarketplaceDayLoadRepository;
use App\Ingestion\Domain\MarketplacePostingStatusRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\MarketplaceReturnFactRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Config\NetForecastPublication;
use App\Ingestion\Infrastructure\Query\Buyout\BuyoutMaturityQuery;
use App\Ingestion\Infrastructure\Query\Listings\AccountCatalogSkuSearchQuery;
use App\Ingestion\Infrastructure\Query\Listings\AccountCatalogSkusPresentQuery;
use App\Ingestion\Infrastructure\Query\Listings\ListingSnapshotsQuery;
use App\Ingestion\Infrastructure\Query\SalesCohort\SalesCohortCoverageQuery;
use App\Ingestion\Infrastructure\Query\SalesCohort\SalesCohortMetricsQuery;
use App\Ingestion\Infrastructure\Query\SalesCohort\SalesCohortSeriesQuery;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\MarketplaceDayLoadBuilder;
use App\Tests\Support\Builder\MarketplacePostingStatusBuilder;
use App\Tests\Support\Builder\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Builder\MarketplaceReturnFactBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bridge\Doctrine\Middleware\Debug\Middleware as DebugMiddleware;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class IngestionFacadeSalesCohortTest extends KernelTestCase
{
    public function testCompleteZeroIsDifferentFromMissingAndFailedAndCumulativePreservesHiddenMissing(): void
    {
        self::bootKernel();
        $company = Uuid::v7();
        $account = Uuid::v7();
        $mark = MarketplaceDayLoadBuilder::aMarketplaceDayLoad()
            ->withCompanyId($company)->withMarketplaceAccountId($account)
            ->withReportType(MarketplaceReportType::OzonPostingFboList);
        $repository = self::loads();
        $mark->withPeriod(new \DateTimeImmutable('2026-08-02'))->completed()->persistWith($repository);
        $mark->withPeriod(new \DateTimeImmutable('2026-08-03'))->failed()->persistWith($repository);
        $facade = self::getContainer()->get(IngestionFacade::class);
        self::assertInstanceOf(IngestionFacade::class, $facade);
        $batch = $facade->salesCohortMetrics($company->toRfc4122(), $account->toRfc4122(),
            new SalesCohortRequest(['EMPTY'], new \DateTimeImmutable('2026-08-02'), new \DateTimeImmutable('2026-08-03'), new \DateTimeImmutable('2026-08-01'), valuationCurrency: 'RUB'));
        self::assertCount(1, $batch->skus);
        self::assertCount(2, $batch->skus[0]->points);
        $zero = $batch->skus[0]->points[0];
        self::assertSame('2026-08-02', $zero->bucketFrom);
        self::assertSame('complete', $zero->periodCoverage);
        self::assertSame(0, self::components($zero->period)->effectiveOrderedQuantity);
        self::assertSame(0, self::components($zero->period)->observedRevenue?->minorAmount());
        self::assertNull($zero->cumulative);
        self::assertSame('partial', $zero->cumulativeCoverage);
        self::assertSame('not_assessed', $batch->returnsCoverage);
        self::assertSame('partial', $batch->ordersCoverage);
        self::assertNull(self::components($zero->period)->expectedNetSalesQuantity);
        self::assertSame('validation_pending', self::components($zero->period)->netForecastUnavailableReason);
        self::assertNull($batch->netForecast->readAt);
        self::assertSame('not_assessed', $batch->netForecast->assessmentStatus);
        self::assertNull(self::components($zero->period)->netForecastQuality->sourceUnavailableReason);
        self::assertNull($batch->skus[0]->points[1]->period);
        self::assertSame('partial', $batch->skus[0]->points[1]->periodCoverage);
    }

    public function testEmptyBatchDoesNotQueryFactsAndUsesCurrentClock(): void
    {
        self::bootKernel();
        [$connection, $debug] = $this->profiledConnection();
        $at = new \DateTimeImmutable('2026-08-30T12:00:00Z');
        $facade = $this->facade($connection, $at);
        $batch = $facade->salesCohortMetrics(Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(),
            new SalesCohortRequest([], $at, $at, $at));
        self::assertSame([], $batch->skus);
        self::assertSame('not_assessed', $batch->ordersCoverage);
        self::assertSame($at, $batch->calculatedAt);
        self::assertSame(0, self::queryCount($debug));
        self::assertSame('not_assessed', $batch->netForecast->assessmentStatus);
        self::assertNull($batch->netForecast->readAt);
        $connection->close();
    }

    public function testCompanyAndAccountScopeCoverMoneyCoverageMaturityAndTraining(): void
    {
        self::bootKernel();
        $company = Uuid::v7();
        $account = Uuid::v7();
        $foreignCompany = Uuid::v7();
        $foreignAccount = Uuid::v7();
        $this->point($company, $account, 'OWN', '2026-08-30', 'delivered', 2, Money::ofMinor(12345, 'RUB'));
        $this->point($company, $account, 'ACTIVE', '2026-08-30', 'awaiting_packaging', 10);
        $this->complete($company, $account, '2026-08-30');
        foreach ([[$foreignCompany, $account], [$company, $foreignAccount]] as [$outsideCompany, $outsideAccount]) {
            $this->point($outsideCompany, $outsideAccount, 'OWN', '2026-08-30', 'delivered', 999, Money::ofMinor(98765, 'USD'));
            $this->training($outsideCompany, $outsideAccount, '2026-06-01', 30);
            $this->training($outsideCompany, $outsideAccount, '2026-08-01', 30);
            $this->complete($outsideCompany, $outsideAccount, '2026-08-29');
            MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()->withCompanyId($outsideCompany)
                ->withMarketplaceAccountId($outsideAccount)->withPeriod(new \DateTimeImmutable('2026-08-29'))
                ->persistWith(MarketplaceRawDocumentBuilder::repository(self::getContainer()));
        }
        $facade = $this->facade(self::connection(), new \DateTimeImmutable('2026-08-30T12:00:00Z'));
        $batch = $facade->salesCohortMetrics($company->toRfc4122(), $account->toRfc4122(),
            new SalesCohortRequest(['OWN', 'ACTIVE'], new \DateTimeImmutable('2026-08-29'), new \DateTimeImmutable('2026-08-30'), new \DateTimeImmutable('2026-08-29'), valuationCurrency: 'RUB'));
        self::assertSame(['ACTIVE', 'OWN'], array_column($batch->skus, 'marketplaceSku'));
        foreach ($batch->skus as $sku) {
            self::assertSame('missing', $sku->points[0]->periodCoverage);
            self::assertNull($sku->points[0]->period);
            self::assertNull($sku->points[1]->cumulative);
            self::assertSame('preliminary', self::components($sku->points[1]->period)->maturityStatus);
        }
        $active = self::components($batch->skus[0]->points[1]->period);
        self::assertSame(10, $active->rawOrderedQuantity);
        self::assertSame(10, $active->unestimatedQuantity);
        self::assertNull($active->currentProjectedBuyoutQuantity);
        $own = self::components($batch->skus[1]->points[1]->period);
        self::assertSame(2, $own->rawOrderedQuantity);
        self::assertSame(2, $own->observedNetSalesQuantity);
        self::assertSame(24690, $own->observedRevenue?->minorAmount());
        self::assertSame('RUB', $own->observedRevenue->currency());
    }

    public function testOneAndFiftySkuBatchesHaveTheSameTwoQueriesAndBoundedNestedSeries(): void
    {
        self::bootKernel();
        $counts = [];
        foreach ([1, 50] as $size) {
            [$connection, $debug] = $this->profiledConnection();
            $batch = $this->facade($connection, new \DateTimeImmutable('2026-09-01T12:00:00Z'))
                ->salesCohortMetrics(Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(),
                    new SalesCohortRequest(array_map(strval(...), range(1, $size)),
                        new \DateTimeImmutable('2026-03-01 23:59:00 America/New_York'),
                        new \DateTimeImmutable('2026-08-31 00:01:00 Pacific/Auckland'),
                        new \DateTimeImmutable('2026-03-01')));
            self::assertCount($size, $batch->skus);
            foreach ($batch->skus as $sku) {
                self::assertCount(184, $sku->points);
                self::assertSame('2026-03-01', $sku->points[0]->bucketFrom);
                self::assertSame('2026-08-31', $sku->points[183]->bucketTo);
                self::assertNull($sku->points[183]->period);
                self::assertSame('missing', $sku->points[183]->periodCoverage);
            }
            $counts[] = self::queryCount($debug);
            $connection->close();
        }
        self::assertSame([2, 2], $counts);
    }

    public function testMonthClipsVisibleBoundsAndCumulativeStartsBeforeVisibleWindow(): void
    {
        self::bootKernel();
        $company = Uuid::v7();
        $account = Uuid::v7();
        foreach (['2026-08-30' => 3, '2026-08-31' => 2, '2026-09-01' => 7] as $day => $quantity) {
            $this->point($company, $account, 'SKU', $day, 'delivered', $quantity, Money::ofMinor(12345, 'RUB'));
            $this->complete($company, $account, $day);
        }
        $facade = $this->facade(self::connection(), new \DateTimeImmutable('2026-10-01T12:00:00Z'));
        $batch = $facade->salesCohortMetrics($company->toRfc4122(), $account->toRfc4122(),
            new SalesCohortRequest(['SKU'], new \DateTimeImmutable('2026-08-31'), new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-08-30'), SalesCohortGrouping::Month, 'RUB'));
        $points = $batch->skus[0]->points;
        self::assertCount(2, $points);
        self::assertSame(['2026-08-31', '2026-09-01'], array_column($points, 'bucketFrom'));
        self::assertSame(['2026-08-31', '2026-09-01'], array_column($points, 'bucketTo'));
        self::assertSame(2, self::components($points[0]->period)->observedNetSalesQuantity);
        self::assertSame(5, self::components($points[0]->cumulative)->observedNetSalesQuantity);
        self::assertSame(7, self::components($points[1]->period)->observedNetSalesQuantity);
        self::assertSame(12, self::components($points[1]->cumulative)->observedNetSalesQuantity);
        self::assertSame(148140, self::components($points[1]->cumulative)->observedRevenue?->minorAmount());
        self::assertSame('12', self::components($points[1]->cumulative)->knownProjectedBuyoutQuantity);
        self::assertSame(10000, self::components($points[1]->cumulative)->knownBuyoutRateBps);
        $withoutCurrency = $facade->salesCohortMetrics($company->toRfc4122(), $account->toRfc4122(),
            new SalesCohortRequest(['SKU', 'EMPTY'], new \DateTimeImmutable('2026-08-30'), new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-08-30')));
        foreach ($withoutCurrency->skus as $sku) {
            self::assertNull(self::components($sku->points[0]->period)->observedRevenue);
        }
    }

    public function testWeekMaturityUsesAggregateInFlightAndLastDayAge(): void
    {
        self::bootKernel();
        $company = Uuid::v7();
        $account = Uuid::v7();
        $this->training($company, $account, '2026-06-01', 30);
        $this->point($company, $account, 'SKU', '2026-08-24', 'delivering', 1);
        $this->point($company, $account, 'SKU', '2026-08-25', 'delivered', 99);
        $this->complete($company, $account, '2026-08-24');
        $this->complete($company, $account, '2026-08-25');
        $connection = self::connection();
        $facade = $this->facade($connection, new \DateTimeImmutable('2026-08-30T12:00:00Z'));
        $request = new SalesCohortRequest(['SKU'], new \DateTimeImmutable('2026-08-24'), new \DateTimeImmutable('2026-08-25'), new \DateTimeImmutable('2026-08-24'));
        $days = $facade->salesCohortMetrics($company->toRfc4122(), $account->toRfc4122(), $request)->skus[0]->points;
        self::assertSame('preliminary', self::components($days[0]->period)->maturityStatus);
        $weekly = new SalesCohortRequest($request->marketplaceSkus, $request->from, $request->to, $request->accumulationFrom, SalesCohortGrouping::Week);
        $week = $facade->salesCohortMetrics($company->toRfc4122(), $account->toRfc4122(), $weekly)->skus[0]->points[0];
        self::assertSame('2026-08-24', $week->bucketFrom);
        self::assertSame('2026-08-25', $week->bucketTo);
        self::assertSame('mature', self::components($week->period)->maturityStatus);
        self::assertSame('mature', self::components($week->cumulative)->maturityStatus);
        self::assertSame(100, self::components($week->period)->inFlightRateBps);
        self::assertSame(10000, self::components($week->period)->actualBuyoutRateBps);
        $fresh = $this->facade($connection, new \DateTimeImmutable('2026-08-25T12:00:00Z'))
            ->salesCohortMetrics($company->toRfc4122(), $account->toRfc4122(), $weekly)->skus[0]->points[0];
        self::assertSame('preliminary', self::components($fresh->period)->maturityStatus);
        self::assertNull(self::components($fresh->period)->actualBuyoutRateBps);
    }

    public function testWeekForecastSumsExactComponentsBeforeRoundingAndMoneyNeverConverts(): void
    {
        self::bootKernel();
        $company = Uuid::v7();
        $account = Uuid::v7();
        $this->training($company, $account, '2026-06-01', 30);
        $this->training($company, $account, '2026-08-01', 10);
        // Twenty proven T1 produce a measured pre-handover rate 10/30.
        for ($i = 0; $i < 20; ++$i) {
            $this->point($company, $account, 'T1', '2026-08-01', 'cancelled', 1, suffix: '-'.$i);
            $posting = 'T1-2026-08-01-cancelled-'.$i;
            self::statuses()->recordChanged($company->toRfc4122(), [
                MarketplacePostingStatusBuilder::aMarketplacePostingStatus()->withCompanyId($company)->withMarketplaceAccountId($account)
                    ->withPostingNumber($posting)->withOrderNumber($posting)->withStatus('awaiting_packaging')
                    ->withObservedAt(new \DateTimeImmutable('2026-08-01T01:00:00Z'))->withRawDocumentId(Uuid::v7())->build(),
            ]);
            self::returns()->upsertAll([
                MarketplaceReturnFactBuilder::aMarketplaceReturnFact()->withCompanyId($company)->withMarketplaceAccountId($account)
                    ->withSourceRowId('RETURN-'.$posting)->withPostingNumber($posting)->withOrderNumber($posting)->withMarketplaceSku('T1')
                    ->withReturnType('Cancellation')->withReturnReasonName('Покупатель отменил заказ')->withQuantity(1)->build(),
            ]);
        }
        foreach (['2026-08-24', '2026-08-25'] as $day) {
            $this->point($company, $account, 'ACTIVE', $day, 'awaiting_packaging', 1);
            $this->complete($company, $account, $day);
        }
        $facade = $this->facade(self::connection(), new \DateTimeImmutable('2026-08-30T12:00:00Z'));
        $request = new SalesCohortRequest(['ACTIVE'], new \DateTimeImmutable('2026-08-24'), new \DateTimeImmutable('2026-08-25'), new \DateTimeImmutable('2026-08-24'));
        $days = $facade->salesCohortMetrics($company->toRfc4122(), $account->toRfc4122(), $request)->skus[0]->points;
        self::assertSame(0, self::components($days[0]->period)->currentProjectedBuyoutQuantity);
        self::assertSame(0, self::components($days[1]->period)->currentProjectedBuyoutQuantity);
        $week = $facade->salesCohortMetrics($company->toRfc4122(), $account->toRfc4122(),
            new SalesCohortRequest(['ACTIVE'], $request->from, $request->to, $request->accumulationFrom, SalesCohortGrouping::Week))->skus[0]->points[0];
        self::assertSame(1, self::components($week->period)->currentProjectedBuyoutQuantity);
        self::assertSame(1, self::components($days[1]->cumulative)->currentProjectedBuyoutQuantity);
        self::assertMatchesRegularExpression('/^0\.66666/', self::components($week->period)->knownProjectedBuyoutQuantity);
        self::assertSame(10000, self::components($week->period)->currentProjectedBuyoutRateBps);
        self::assertNull(self::components($week->period)->observedRevenue);
        $this->point($company, $account, 'MIXED', '2026-08-24', 'delivered', 2, Money::ofMinor(123, 'RUB'));
        $this->point($company, $account, 'MIXED', '2026-08-25', 'delivered', 3, Money::ofMinor(123, 'USD'));
        $mixed = $facade->salesCohortMetrics($company->toRfc4122(), $account->toRfc4122(),
            new SalesCohortRequest(['MIXED'], $request->from, $request->to, $request->accumulationFrom, SalesCohortGrouping::Week, 'RUB'))->skus[0]->points[0];
        self::assertSame(5, self::components($mixed->period)->observedNetSalesQuantity);
        self::assertNull(self::components($mixed->period)->observedRevenue);
    }

    public function testUnknownOutcomeIsSeparateFromKnownActiveWithoutTraining(): void
    {
        self::bootKernel();
        $company = Uuid::v7();
        $account = Uuid::v7();
        $this->point($company, $account, 'SKU', '2026-08-24', 'cancelled', 1);
        $this->point($company, $account, 'SKU', '2026-08-24', 'awaiting_packaging', 9);
        $this->complete($company, $account, '2026-08-24');
        $facade = $this->facade(self::connection(), new \DateTimeImmutable('2026-08-30T12:00:00Z'));
        foreach ([SalesCohortGrouping::Day, SalesCohortGrouping::Week, SalesCohortGrouping::Month] as $group) {
            $point = $facade->salesCohortMetrics($company->toRfc4122(), $account->toRfc4122(),
                new SalesCohortRequest(['SKU'], new \DateTimeImmutable('2026-08-24'), new \DateTimeImmutable('2026-08-24'), new \DateTimeImmutable('2026-08-24'), $group))
                ->skus[0]->points[0];
            foreach ([$point->period, $point->cumulative] as $value) {
                $components = self::components($value);
                self::assertSame(1, $components->unknownOutcomeQuantity);
                self::assertSame(10, $components->unestimatedQuantity);
                self::assertSame(10, $components->effectiveOrderedQuantity);
            }
        }
    }

    public function testPeriodIsOneVisibleBucketAndKeepsHiddenAccumulation(): void
    {
        self::bootKernel();
        $company = Uuid::v7();
        $account = Uuid::v7();
        foreach (['2026-08-30' => 3, '2026-08-31' => 2, '2026-09-01' => 7] as $day => $quantity) {
            $this->point($company, $account, 'SKU', $day, 'delivered', $quantity);
            $this->complete($company, $account, $day);
        }
        $period = $this->facade(self::connection(), new \DateTimeImmutable('2026-10-01T12:00:00Z'))
            ->salesCohortMetrics($company->toRfc4122(), $account->toRfc4122(),
                new SalesCohortRequest(['SKU'], new \DateTimeImmutable('2026-08-31'), new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-08-30'), SalesCohortGrouping::Period));
        self::assertCount(1, $period->skus[0]->points);
        $point = $period->skus[0]->points[0];
        self::assertSame('2026-08-31', $point->bucketFrom);
        self::assertSame('2026-09-01', $point->bucketTo);
        self::assertSame(9, self::components($point->period)->observedNetSalesQuantity);
        self::assertSame(12, self::components($point->cumulative)->observedNetSalesQuantity);
    }

    private function complete(Uuid $company, Uuid $account, string $day): void
    {
        MarketplaceDayLoadBuilder::aMarketplaceDayLoad()->withCompanyId($company)->withMarketplaceAccountId($account)
            ->withReportType(MarketplaceReportType::OzonPostingFboList)->withPeriod(new \DateTimeImmutable($day))
            ->completed()->persistWith(self::loads());
    }

    private function point(Uuid $company, Uuid $account, string $sku, string $day, string $state, int $quantity, ?Money $price = null, string $suffix = ''): void
    {
        $posting = $sku.'-'.$day.'-'.$state.$suffix;
        self::sales()->upsertAll([
            SalesFactBuilder::aSalesFact()->withCompanyId($company)->withMarketplaceAccountId($account)
                ->withMarketplaceSku($sku)->withBusinessDate(new \DateTimeImmutable($day))
                ->withSourceRowId($posting.'|'.$sku)->withPostingNumber($posting)->withOrderNumber($posting)
                ->withStatus($state)->withQuantity($quantity)->withAmount($price ?? Money::ofMinor(100, 'RUB'))
                ->withCommissionAmount(Money::ofMinor(0, $price?->currency() ?? 'RUB'))->build(),
        ]);
        self::statuses()->recordChanged($company->toRfc4122(), [
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()->withCompanyId($company)->withMarketplaceAccountId($account)
                ->withPostingNumber($posting)->withOrderNumber($posting)->withStatus($state)
                ->withObservedAt(new \DateTimeImmutable($day.' 10:00:00 UTC'))->build(),
        ]);
    }

    private function training(Uuid $company, Uuid $account, string $day, int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->point($company, $account, 'TRAIN', $day, 'delivered', 1, suffix: '-'.$i);
            $posting = 'TRAIN-'.$day.'-delivered-'.$i;
            self::statuses()->recordChanged($company->toRfc4122(), [
                MarketplacePostingStatusBuilder::aMarketplacePostingStatus()->withCompanyId($company)->withMarketplaceAccountId($account)
                    ->withPostingNumber($posting)->withOrderNumber($posting)->withStatus('awaiting_packaging')
                    ->withObservedAt(new \DateTimeImmutable($day.' 01:00:00 UTC'))->withRawDocumentId(Uuid::v7())->build(),
            ]);
        }
    }

    /** @return array{Connection, DebugDataHolder} */
    private function profiledConnection(): array
    {
        $connection = self::connection();
        $debug = new DebugDataHolder();
        $configuration = (new Configuration())->setMiddlewares([new DebugMiddleware($debug, null, 'cohort')]);

        return [DriverManager::getConnection($connection->getParams(), $configuration), $debug];
    }

    private static function queryCount(DebugDataHolder $debug): int
    {
        $count = 0;
        foreach ($debug->getData() as $queries) {
            $count += \count($queries);
        }

        return $count;
    }

    private function facade(Connection $connection, \DateTimeImmutable $at): IngestionFacade
    {
        $clock = new class($at) implements ClockInterface {
            public function __construct(private readonly \DateTimeImmutable $at)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return $this->at;
            }
        };

        $reader = self::getContainer()->get(ReadNetForecastAction::class);
        self::assertInstanceOf(ReadNetForecastAction::class, $reader);

        return new IngestionFacade(new ListingSnapshotsQuery($connection), new AccountCatalogSkuSearchQuery($connection),
            new AccountCatalogSkusPresentQuery($connection),
            new SalesCohortSeriesQuery($connection, new SalesCohortMetricsQuery($connection), new SalesCohortCoverageQuery($connection)),
            new BuyoutMaturityQuery($connection), $clock,
            new NetForecastPublication(),
            $reader,
            new NetTrainingSnapshot($connection));
    }

    private static function components(?SalesCohortComponents $components): SalesCohortComponents
    {
        self::assertNotNull($components);

        return $components;
    }

    private static function connection(): Connection
    {
        $service = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $service);

        return $service;
    }

    private static function sales(): SalesFactRepository
    {
        $service = self::getContainer()->get(SalesFactRepository::class);
        self::assertInstanceOf(SalesFactRepository::class, $service);

        return $service;
    }

    private static function statuses(): MarketplacePostingStatusRepository
    {
        $service = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        self::assertInstanceOf(MarketplacePostingStatusRepository::class, $service);

        return $service;
    }

    private static function returns(): MarketplaceReturnFactRepository
    {
        $service = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        self::assertInstanceOf(MarketplaceReturnFactRepository::class, $service);

        return $service;
    }

    private static function loads(): MarketplaceDayLoadRepository
    {
        $service = self::getContainer()->get(MarketplaceDayLoadRepository::class);
        self::assertInstanceOf(MarketplaceDayLoadRepository::class, $service);

        return $service;
    }
}
