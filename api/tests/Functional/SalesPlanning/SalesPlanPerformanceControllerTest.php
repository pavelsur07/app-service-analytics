<?php

declare(strict_types=1);

namespace App\Tests\Functional\SalesPlanning;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
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
use App\SalesPlanning\Domain\SalesPlanMetric;
use App\SalesPlanning\Domain\SalesPlanRepository;
use App\SalesPlanning\Ui\Response\PerformanceNetMoneyResponse;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceDayLoadBuilder;
use App\Tests\Support\Builder\SalesPlanBuilder;
use App\Tests\Support\Builder\SalesPlanLineBuilder;
use App\Tests\Support\Builder\UserBuilder;
use App\Tests\Support\Ingestion\NetTargetFixture;
use App\Tests\Support\Ingestion\NetTrainingFixture;
use App\Tests\Support\SessionWebTestCase;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Uid\Uuid;

#[SkipDatabaseRollback]
final class SalesPlanPerformanceControllerTest extends SessionWebTestCase
{
    /** @return iterable<string, array{int, string, int}> */
    public static function netMoneyCases(): iterable
    {
        foreach (['JPY' => 0, 'RUB' => 2, 'KWD' => 3, 'CLF' => 4] as $currency => $digits) {
            foreach ([\PHP_INT_MIN, \PHP_INT_MAX, 0, -1] as $minor) {
                yield $currency.'-'.$minor => [$minor, $currency, $digits];
            }
        }
    }

    #[DataProvider('netMoneyCases')]
    public function testNetMoneySerializerPreservesExactSignedIntegerAndAuthoritativeScale(int $minor, string $currency, int $digits): void
    {
        $dto = PerformanceNetMoneyResponse::of(Money::ofMinor($minor, $currency));
        $response = new JsonResponse($dto);
        $json = (string) $response->getContent();
        self::assertSame(['minor' => (string) $minor, 'currency' => $currency, 'fractionDigits' => $digits], json_decode($json, true, flags: \JSON_THROW_ON_ERROR));
        self::assertNull(PerformanceNetMoneyResponse::of(null));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('publicationVersions')]
    public function testHealthyNetThroughAuthorizedControllerAndClosedGateOnIdenticalSources(string $publication): void
    {
        $client = self::createClient();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $writer = DriverManager::getConnection($db->getParams());
        $database = $writer->fetchOne('SELECT current_database()');
        self::assertIsString($database);
        self::assertMatchesRegularExpression('/^app_test(?:[1-9][0-9]*)?$/D', $database);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $companies = self::getContainer()->get(CompanyRepository::class);
        self::assertInstanceOf(CompanyRepository::class, $companies);
        $plans = self::getContainer()->get(SalesPlanRepository::class);
        self::assertInstanceOf(SalesPlanRepository::class, $plans);
        $company = CompanyBuilder::aCompany()->build();
        $user = UserBuilder::aUser()->build();
        $training = new NetTrainingFixture($writer, $company->id());
        $f = new NetTargetFixture($writer, $training->readAt->modify('+12 hours'), $training->company, $training->account);
        try {
            $companies->add($company);
            $users = new DoctrineUserRepository($em);
            $users->add($user);
            CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($user)->persistWith($companies, $users, new DoctrineCompanyMemberRepository($em));
            for ($i = 0; $i < 35; ++$i) {
                $training->posting('TRAIN-D-'.$i, 'SKU', 40);
                if ($i < 20) {
                    $training->returned('TRAIN-D-'.$i, 'SKU', 5, $training->terminal->modify('+10 days'));
                }
            }
            for ($i = 0; $i < 30; ++$i) {
                $training->posting('TRAIN-C-'.$i, 'SKU', 40, 'cancelled');
            }
            $training->returnCoverage();
            $day = new \DateTimeImmutable($f->readAt->modify('-1 day')->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d'));
            $origin = $f->readAt->modify('-1 day');
            $f->delivered('D', 'SKU', 70, $origin, day: $day, amount: Money::ofMinor(100, 'RUB'));
            $f->sale('OPEN', 'SKU', 50, 'awaiting_packaging', $f->readAt->modify('-26 hours'), day: $day, amount: Money::ofMinor(100, 'RUB'));
            $f->daily($origin);
            $f->currentReturns();
            $plan = SalesPlanBuilder::aSalesPlan()->withCompanyId($company->id())->withMarketplaceAccountId($f->account)
                ->withPeriod($day, $day)->withMetric(SalesPlanMetric::RetainedSales)->build();
            $lines = SalesPlanLineBuilder::linesOf($plan)->withMarketplaceSku('SKU')->withTotalQuantity(100)->build();
            $plan->approve($user->id(), $f->readAt);
            $plans->add($plan, $lines);
            $clock = new MockClock($f->readAt);
            $e = $f->epoch;
            $epochs = new NetForecastCaptureEpochs(json_encode([['epochId' => $e->id, 'captureVersion' => $e->captureVersion, 'startUtc' => $e->start->format('Y-m-d\TH:i:s\Z'), 'endUtc' => null, 'cutoverRelease' => $e->cutoverRelease]], \JSON_THROW_ON_ERROR));
            $aggregate = self::getContainer()->get(AggregateNetForecastAction::class);
            self::assertInstanceOf(AggregateNetForecastAction::class, $aggregate);
            $reader = new ReadNetForecastAction($db, new ReadNetTrainingAction($db, new GrossTrainingAggregateQuery($db), new ReturnTrainingAggregateQuery($db), $epochs, $clock), new NetTargetQuery($db), $aggregate);
            self::getContainer()->set(IngestionFacade::class, new IngestionFacade(new ListingSnapshotsQuery($db), new AccountCatalogSkuSearchQuery($db), new AccountCatalogSkusPresentQuery($db),
                new SalesCohortSeriesQuery($db, new SalesCohortMetricsQuery($db), new SalesCohortCoverageQuery($db)), new BuyoutMaturityQuery($db), $clock,
                new NetForecastPublication($publication), $reader, new NetTrainingSnapshot($db)));
            $client->loginUser($user, 'api');
            $client->request('GET', '/api/companies/'.$company->id()->toRfc4122().'/sales-plans/'.$plan->id()->toRfc4122().'/performance', ['from' => $day->format('Y-m-d'), 'to' => $day->format('Y-m-d')]);
            self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
            $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            $cohort = self::path($body, 'skus', 0, 'points', 0, 'period', 'cohort');
            self::assertSame(70, self::path($cohort, 'observedNetSalesQuantity'));
            self::assertSame('' === $publication ? null : '90.000', self::path($cohort, 'expectedNetSalesQuantity'));
            self::assertSame('' === $publication ? null : ['minor' => '9000', 'currency' => 'RUB', 'fractionDigits' => 2], self::path($cohort, 'expectedNetRevenue'));
            self::assertSame('' === $publication ? 'validation_pending' : null, self::path($cohort, 'netForecastUnavailableReason'));
            self::assertSame('' === $publication ? 'not_assessed' : 'assessed', self::path($body, 'netForecast', 'assessmentStatus'));
            self::assertSame('' === $publication ? null : $f->readAt->format(\DateTimeInterface::ATOM), self::path($body, 'netForecast', 'readAt'));
            self::assertNull(self::path($body, 'skus', 0, 'points', 0, 'period', 'expectedGoalQuantity'));
        } finally {
            $f->cleanup();
            foreach (['sales_plan_day_claim', 'sales_plan_line', 'sales_plan', 'company_member', 'company'] as $table) {
                $writer->executeStatement('DELETE FROM '.$table.' WHERE '.('company' === $table ? 'id' : 'company_id').' = ?', [$company->id()->toRfc4122()]);
            }
            $writer->executeStatement('DELETE FROM "user" WHERE id = ?', [$user->id()->toRfc4122()]);
            $writer->close();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function publicationVersions(): iterable
    {
        yield 'closed' => [''];
        yield 'isolated exact version' => ['observed_retention_h60_v1'];
    }

    #[DataProvider('publicationVersions')]
    public function testOriginalGoalPayloadPagingValidationAndTrustBoundary(string $publication): void
    {
        $priorEnv = $_ENV['NET_FORECAST_PUBLISHED_MODEL_VERSION'] ?? null;
        $priorServer = $_SERVER['NET_FORECAST_PUBLISHED_MODEL_VERSION'] ?? null;
        $_ENV['NET_FORECAST_PUBLISHED_MODEL_VERSION'] = $_SERVER['NET_FORECAST_PUBLISHED_MODEL_VERSION'] = $publication;
        $client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $companies = self::getContainer()->get(CompanyRepository::class);
        self::assertInstanceOf(CompanyRepository::class, $companies);
        $plans = self::getContainer()->get(SalesPlanRepository::class);
        self::assertInstanceOf(SalesPlanRepository::class, $plans);
        $loads = self::getContainer()->get(MarketplaceDayLoadRepository::class);
        self::assertInstanceOf(MarketplaceDayLoadRepository::class, $loads);
        $company = CompanyBuilder::aCompany()->build();
        $user = UserBuilder::aUser()->build();
        $foreignCompanyEntity = CompanyBuilder::aCompany()->build();
        $foreignCompany = $foreignCompanyEntity->id();
        try {
            $companies->add($company);
            $companies->add($foreignCompanyEntity);
            $users = new DoctrineUserRepository($em);
            $users->add($user);
            CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($user)->persistWith($companies, $users, new DoctrineCompanyMemberRepository($em));
            CompanyMemberBuilder::aCompanyMember()->withCompany($foreignCompanyEntity)->withUser($user)->persistWith($companies, $users, new DoctrineCompanyMemberRepository($em));
            $base = SalesPlanBuilder::aSalesPlan()->withCompanyId($company->id())->withMetric(SalesPlanMetric::RetainedSales)->build();
            $lines = SalesPlanLineBuilder::linesOf($base)->withMarketplaceSku('BASE')->withTotalQuantity(31)->build();
            $base->approve($user->id(), new \DateTimeImmutable());
            $plans->add($base, $lines);
            $draft = SalesPlanBuilder::revisionOf($base)->build();
            $plans->add($draft, array_merge(
                SalesPlanLineBuilder::linesOf($draft)->withMarketplaceSku('NEW')->withTotalQuantity(999)->build(),
                SalesPlanLineBuilder::linesOf($draft)->withMarketplaceSku('BASE')->withTotalQuantity(999)->withPrice(Money::ofMinor(777, 'RUB'))->build(),
            ));
            MarketplaceDayLoadBuilder::aMarketplaceDayLoad()->withCompanyId($company->id())->withMarketplaceAccountId($base->marketplaceAccountId())
                ->withReportType(MarketplaceReportType::OzonPostingFboList)->withPeriod(new \DateTimeImmutable('2026-07-10'))->completed()->persistWith($loads);
            $draftOnly = SalesPlanBuilder::aSalesPlan()->withCompanyId($company->id())->withMetric(SalesPlanMetric::RetainedSales)->build();
            $plans->add($draftOnly, []);
            $stale = SalesPlanBuilder::aSalesPlan()->withCompanyId($company->id())->withMetric(SalesPlanMetric::RetainedSales)->build();
            $staleLines = SalesPlanLineBuilder::linesOf($stale)->withMarketplaceSku('STALE')->build();
            $stale->approve($user->id(), new \DateTimeImmutable());
            $stale->supersede(new \DateTimeImmutable());
            $plans->add($stale, $staleLines);
            $legacy = SalesPlanBuilder::aSalesPlan()->withCompanyId($company->id())->withMetric(SalesPlanMetric::RetainedSales)->build();
            $legacyLines = SalesPlanLineBuilder::linesOf($legacy)->build();
            $legacy->approve($user->id(), new \DateTimeImmutable());
            $plans->add($legacy, $legacyLines);
            // Deliberate pre-feature legacy fixture, no production status write.
            $connection->executeStatement('UPDATE sales_plan SET metric = NULL WHERE company_id = ? AND id = ?', [$company->id()->toRfc4122(), $legacy->id()->toRfc4122()]);
            $foreign = SalesPlanBuilder::aSalesPlan()->withCompanyId($foreignCompany)->build();
            $plans->add($foreign, SalesPlanLineBuilder::linesOf($foreign)->build());
            $client->loginUser($user, 'api');
            $url = '/api/companies/'.$company->id()->toRfc4122().'/sales-plans/'.$draft->id()->toRfc4122().'/performance';
            $parameters = ['from' => '2026-07-10', 'to' => '2026-07-10', 'limit' => '1'];
            $client->request('GET', $url, $parameters);
            self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
            $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertSame($base->id()->toRfc4122(), self::path($body, 'baselinePlanId'));
            self::assertSame($draft->id()->toRfc4122(), self::path($body, 'currentPlanId'));
            self::assertSame('2026-07-01', self::path($body, 'accumulationFrom'));
            self::assertSame('BASE', self::path($body, 'skus', 0, 'marketplaceSku'));
            $value = self::path($body, 'skus', 0, 'points', 0, 'period');
            self::assertSame(1, self::path($value, 'planQuantity'));
            self::assertSame(['minor' => 129900, 'currency' => 'RUB'], self::path($value, 'planRevenueRrp'));
            self::assertSame(0, self::path($value, 'actualGoalQuantity'));
            self::assertSame(-1, self::path($value, 'quantityDeviation'));
            self::assertSame(['minor' => 0, 'currency' => 'RUB'], self::path($value, 'cohort', 'observedRevenue'));
            self::assertNull(self::path($value, 'expectedGoalQuantity'));
            self::assertNull(self::path($value, 'expectedAttainmentBps'));
            self::assertSame('not_assessed', self::path($body, 'returnsCoverage'));
            self::assertSame('placed_order_cohorts', self::path($body, 'netForecast', 'scope'));
            self::assertNull(self::path($value, 'cohort', 'expectedNetRevenue'));
            self::assertSame('' === $publication ? 'validation_pending' : 'source_history_unavailable', self::path($value, 'cohort', 'netForecastMoneyUnavailableReason'));
            self::assertSame([], self::path($value, 'cohort', 'netForecastQuality', 'trainingScopes'));
            self::assertSame('' === $publication ? null : 'source_history_unavailable', self::path($value, 'cohort', 'netForecastQuality', 'sourceUnavailableReason'));
            self::assertNull(self::path($body, 'netForecast', 'epochId'));
            foreach (['sourceRowId', 'snapshotId', 'expectedNetNumerator', 'computeDeadlineNs', 'allocationBaselineBytes', 'Brick'] as $private) {
                self::assertStringNotContainsString($private, (string) $client->getResponse()->getContent());
            }
            self::assertSame('' === $publication ? 'not_assessed' : 'assessed', self::path($body, 'netForecast', 'assessmentStatus'));
            self::assertSame('' === $publication ? 'validation_pending' : 'release_approved', self::path($body, 'netForecast', 'validationStatus'));
            $validCursor = self::path($body, 'nextCursor');
            self::assertIsString($validCursor);
            $decodedCursor = base64_decode($validCursor, true);
            self::assertIsString($decodedCursor);
            $cursorPayload = json_decode($decodedCursor, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($cursorPayload);
            $cursorPayload['sku'] = "bad\0";
            $client->request('GET', $url, [...$parameters, 'cursor' => base64_encode(json_encode($cursorPayload, \JSON_THROW_ON_ERROR))]);
            self::assertSame(422, $client->getResponse()->getStatusCode(), 'A matching cursor scope must not allow a NUL SKU into PostgreSQL.');
            $client->request('GET', $url, [...$parameters, 'cursor' => self::path($body, 'nextCursor')]);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            $next = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame('NEW', self::path($next, 'skus', 0, 'marketplaceSku'));
            self::assertSame(0, self::path($next, 'skus', 0, 'points', 0, 'period', 'planQuantity'));
            self::assertNull(self::path($next, 'skus', 0, 'points', 0, 'period', 'attainmentBps'));
            self::assertNull(self::path($next, 'nextCursor'));
            $client->request('GET', $url, ['from' => '2026-07-10', 'to' => '2026-07-31', 'groupBy' => 'period']);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            $period = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            $periodPoints = self::path($period, 'skus', 0, 'points');
            self::assertIsArray($periodPoints);
            self::assertCount(1, $periodPoints);
            self::assertSame('2026-07-31', self::path($period, 'skus', 0, 'points', 0, 'bucketTo'));
            self::assertSame(31, self::path($period, 'skus', 0, 'points', 0, 'cumulative', 'planQuantity'));
            foreach ([
                ['from' => '2026-02-30'], ['from' => "2026-07-10\0"], ['from' => ['2026-07-10']], ['groupBy' => 'year'], ['limit' => '51'],
                ['from' => '2026-07-01', 'to' => '2026-08-01'], ['from' => '2026-03-01', 'to' => '2026-09-01', 'groupBy' => 'period'],
                ['limit' => '1.5'], ['limit' => ['1']], ['cursor' => 'garbage'], ['cursor' => ['bad']],
                ['accumulationFrom' => '2026-07-10'], ['marketplaceAccountId' => Uuid::v7()->toRfc4122()],
                ['accountId' => Uuid::v7()->toRfc4122()], ['modelVersion' => 'observed_retention_h60_v1'], ['readAt' => '2026-07-10T00:00:00Z'],
                ['baselinePlanId' => $draft->id()->toRfc4122()], ['from' => '2026-06-30'],
                ['to' => '2026-07-09'], ['from' => '2026-07-11', 'to' => '2026-07-11', 'cursor' => self::path($body, 'nextCursor')],
            ] as $bad) {
                $client->request('GET', $url, array_replace($parameters, $bad));
                self::assertSame(422, $client->getResponse()->getStatusCode(), json_encode($bad, \JSON_THROW_ON_ERROR));
            }
            foreach ([[$draftOnly->id(), 409], [$stale->id(), 409], [$legacy->id(), 422]] as [$id, $status]) {
                $client->request('GET', '/api/companies/'.$company->id()->toRfc4122().'/sales-plans/'.$id->toRfc4122().'/performance', $parameters);
                self::assertSame($status, $client->getResponse()->getStatusCode());
            }
            $client->request('GET', '/api/companies/'.Uuid::v7()->toRfc4122().'/sales-plans/'.$draft->id()->toRfc4122().'/performance', $parameters);
            self::assertSame(403, $client->getResponse()->getStatusCode());
            $client->request('GET', '/api/companies/'.$company->id()->toRfc4122().'/sales-plans/'.$foreign->id()->toRfc4122().'/performance', $parameters);
            self::assertSame(404, $client->getResponse()->getStatusCode());
        } finally {
            if (null === $priorEnv) {
                unset($_ENV['NET_FORECAST_PUBLISHED_MODEL_VERSION']);
            } else {
                $_ENV['NET_FORECAST_PUBLISHED_MODEL_VERSION'] = $priorEnv;
            }
            if (null === $priorServer) {
                unset($_SERVER['NET_FORECAST_PUBLISHED_MODEL_VERSION']);
            } else {
                $_SERVER['NET_FORECAST_PUBLISHED_MODEL_VERSION'] = $priorServer;
            }
            foreach (['sales_plan_day_claim', 'sales_plan_line', 'sales_plan', 'marketplace_day_load_receipt', 'marketplace_day_load', 'company_member', 'company'] as $table) {
                $column = 'company' === $table ? 'id' : 'company_id';
                foreach ([$company->id()->toRfc4122(), $foreignCompany->toRfc4122()] as $companyId) {
                    $connection->executeStatement('DELETE FROM '.$table.' WHERE '.$column.' = ?', [$companyId]);
                }
            }
            $connection->executeStatement('DELETE FROM "user" WHERE id = ?', [$user->id()->toRfc4122()]);
        }
    }

    private static function path(mixed $value, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }
}
