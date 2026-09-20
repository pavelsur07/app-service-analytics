<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\Facade\PlanningObservationDateAxis;
use App\Ingestion\Domain\MarketplaceListingRepository;
use App\Ingestion\Domain\MarketplacePostingStatusRepository;
use App\Ingestion\Domain\MarketplaceReturnFactRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Query\BuyoutDailyQuery;
use App\Ingestion\Infrastructure\Query\BuyoutForecastQuery;
use App\Ingestion\Infrastructure\Query\BuyoutRateDirection;
use App\Ingestion\Infrastructure\Query\BuyoutRateQuery;
use App\Ingestion\Infrastructure\Query\BuyoutRateSort;
use App\Ingestion\Infrastructure\Query\PlanningMarketplaceSkusQuery;
use App\Ingestion\Infrastructure\Query\PlanningOrderCohortsQuery;
use App\Ingestion\Infrastructure\Query\PlanningOutcomeQueryGuard;
use App\Ingestion\Infrastructure\Query\PlanningResolutionsQuery;
use App\Ingestion\Infrastructure\Query\PlanningUnitOutcomeSql;
use App\Ingestion\Infrastructure\Repository\PlanningSourceStateWriter;
use App\Tests\Support\Builder\MarketplaceListingBuilder;
use App\Tests\Support\Builder\MarketplacePostingStatusBuilder;
use App\Tests\Support\Builder\MarketplaceReturnFactBuilder;
use App\Tests\Support\Builder\PlanningIngestionAccountStateBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Type;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** Регрессия production SQL N+1: fact/history scans не повторяются по sale. */
final class BuyoutQueryPlanTest extends KernelTestCase
{
    private Uuid $companyId;
    private Uuid $accountId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->companyId = Uuid::v7();
        $this->accountId = Uuid::v7();
        $this->seedProductionShapedCohort();
    }

    public function testPlanningWriteDoesNotApplyReadOnlyPlannerLimits(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $connection->executeStatement("SET LOCAL statement_timeout = '30s'");
            $connection->executeStatement('SET LOCAL enable_nestloop = on');
            $connection->executeStatement('SET LOCAL jit = on');
            $seen = [];
            (new PlanningOutcomeQueryGuard($connection))->write(static function () use ($connection, &$seen): void {
                $seen = $connection->fetchAssociative("SELECT current_setting('statement_timeout') AS timeout, current_setting('enable_nestloop') AS nested, current_setting('jit') AS jit");
            });
            self::assertSame(['timeout' => '30s', 'nested' => 'on', 'jit' => 'on'], $seen);
        } finally {
            $connection->rollBack();
        }
    }

    public function testOutcomeViewDoesNotRescanFactTablesForEverySale(): void
    {
        $plan = $this->explainSql(
            <<<'SQL'
                SELECT marketplace_sku, SUM(quantity)::bigint
                FROM buyout_outcome
                WHERE company_id = :companyId
                  AND marketplace_account_id = :accountId
                GROUP BY marketplace_sku
                SQL,
            [
                'companyId' => $this->companyId->toRfc4122(),
                'accountId' => $this->accountId->toRfc4122(),
            ],
        );

        self::assertSame([], $this->repeatedBaseTableScans($plan), self::planMessage($plan));
        $this->assertTenantPredicateIsPushedIntoBaseScans($plan, expectAccountPredicate: true);
    }

    public function testPlanningCohortKeepsTenantPredicateInBaseScans(): void
    {
        $query = (new PlanningOrderCohortsQuery($this->connection()))->build(
            $this->companyId->toRfc4122(), $this->accountId->toRfc4122(), ['PLAN-SKU-0'],
            new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-30'), 50, null, null,
        );
        $plan = $this->explainQuery($query);

        self::assertSame([], $this->repeatedBaseTableScans($plan), self::planMessage($plan));
        $this->assertTenantPredicateIsPushedIntoBaseScans($plan, expectAccountPredicate: true);
    }

    public function testPlanningCohortMaterializesOnlyRequestedSkuAndDate(): void
    {
        $sales = [];
        $statuses = [];
        for ($index = 0; $index < 12; ++$index) {
            $posting = 'COHORT-OLD-'.$index;
            $sales[] = SalesFactBuilder::aSalesFact()->withCompanyId($this->companyId)
                ->withMarketplaceAccountId($this->accountId)->withSourceRowId($posting.'|PLAN-SKU-0')
                ->withPostingNumber($posting)->withOrderNumber($posting)
                ->withMarketplaceSku('PLAN-SKU-0')->withBusinessDate(new \DateTimeImmutable('2026-07-01'))->build();
            $statuses[] = MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
                ->withCompanyId($this->companyId)->withMarketplaceAccountId($this->accountId)
                ->withPostingNumber($posting)->withOrderNumber($posting)->withStatus('delivered')->build();
        }
        $this->sales()->upsertAll($sales);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), $statuses);

        $query = (new PlanningOrderCohortsQuery($this->connection()))->build(
            $this->companyId->toRfc4122(), $this->accountId->toRfc4122(), ['PLAN-SKU-0'],
            new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-01'), 50, null, null,
        );
        $result = $query->executeQuery()->fetchAllAssociative();
        self::assertCount(1, $result);
        self::assertEquals(6, $result[0]['ordered']);
        $plan = $this->explainQuery($query);
        $scans = $this->subplanScans($plan, 'CTE tenant_outcome');
        self::assertNotSame([], $scans, self::planMessage($plan));
        foreach ($scans as $scan) {
            self::assertLessThanOrEqual(6, $scan['Actual Rows'] ?? null);
        }
    }

    public function testPlanningResolutionQueriesKeepTenantPredicateInBaseScans(): void
    {
        $connection = $this->connection();
        $baseline = new \DateTimeImmutable('2026-06-30 10:00:00');
        $connection->insert('planning_ingestion_account_state', PlanningIngestionAccountStateBuilder::anAccountState()
            ->withCompanyId($this->companyId)->withMarketplaceAccountId($this->accountId)
            ->withUpdatedAt($baseline)->withBaselineCompletedAt($baseline)->row());
        (new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection)))->recordCompleted(
            $this->companyId->toRfc4122(), $this->accountId->toRfc4122(), 'postings',
            '2026-08-01', '2026-08-01', hash('sha256', 'query-plan-postings'), 'regular', [],
            array_map(static fn (int $index): string => 'PLAN-POSTING-'.$index.'|PLAN-SKU-'.intdiv($index, 6), range(0, 23)),
        );
        $query = new PlanningResolutionsQuery($connection);
        $company = $this->companyId->toRfc4122();
        $account = $this->accountId->toRfc4122();
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow'));
        $from = $today->modify('-1 day');
        $to = $today->modify('+1 day');
        foreach ([
            $query->totals($company, $account, ['PLAN-SKU-0'], $from, $to, PlanningObservationDateAxis::FIRST_KNOWN_OUTCOME->value),
            $query->observations($company, $account, ['PLAN-SKU-0'], $from, $to, PlanningObservationDateAxis::FIRST_KNOWN_OUTCOME->value, 50, null, null, null),
        ] as $statement) {
            $plan = $this->explainQuery($statement);
            self::assertSame([], $this->repeatedBaseTableScans($plan), self::planMessage($plan));
            $this->assertTenantPredicateIsPushedIntoBaseScans($plan, expectAccountPredicate: true);
        }
    }

    public function testUndatedQualityQueryScopesCandidatesBeforeExpandingUnits(): void
    {
        $connection = $this->connection();
        $company = $this->companyId->toRfc4122();
        $account = $this->accountId->toRfc4122();
        $baseline = new \DateTimeImmutable('2026-06-30 10:00:00');
        $connection->insert('planning_ingestion_account_state', PlanningIngestionAccountStateBuilder::anAccountState()
            ->withCompanyId($this->companyId)->withMarketplaceAccountId($this->accountId)
            ->withUpdatedAt($baseline)->withBaselineCompletedAt($baseline)->row());
        (new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection)))->recordCompleted(
            $company, $account, 'postings', '2026-08-01', '2026-08-01', hash('sha256', 'undated-plan'), 'rescan', [],
            ['PLAN-POSTING-0|PLAN-SKU-0'],
        );
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow'));
        $query = (new PlanningResolutionsQuery($connection))->undatedCurrentOutcomeQuery(
            $company, $account, ['PLAN-SKU-0'], $today, $today,
            PlanningObservationDateAxis::FIRST_REGULAR_OBSERVATION->value,
        );
        $plan = $this->explainQuery($query);

        self::assertSame([], $this->repeatedBaseTableScans($plan), self::planMessage($plan));
        $this->assertTenantPredicateIsPushedIntoBaseScans($plan, expectAccountPredicate: true);
        $unitScans = $this->functionScans($plan, 'unit');
        self::assertNotSame([], $unitScans, self::planMessage($plan));
        foreach ($unitScans as $scan) {
            self::assertLessThanOrEqual(10, $scan['Actual Loops'] ?? null, self::planMessage($plan));
        }
    }

    public function testPlanningObservationCalculationLimitsSalesBeforeUnitExpansion(): void
    {
        $unitCte = PlanningUnitOutcomeSql::cte('order_number IN (:affectedOrders)');
        $plan = $this->explainSql("WITH {$unitCte} SELECT COUNT(*) FROM unit_outcome", [
            'company' => $this->companyId->toRfc4122(),
            'account' => $this->accountId->toRfc4122(),
            'affectedOrders' => ['PLAN-ORDER-0'],
        ], ['affectedOrders' => ArrayParameterType::STRING]);

        $salesScans = $this->relationScans($plan, 'sales_fact');
        self::assertNotSame([], $salesScans, self::planMessage($plan));
        foreach ($salesScans as $scan) {
            self::assertLessThanOrEqual(3, $scan['Actual Rows'] ?? null, self::planMessage($plan));
        }
        self::assertSame([], $this->repeatedBaseTableScans($plan), self::planMessage($plan));
    }

    public function testResolutionWindowExpandsOnlyOrdersWithRelevantObservations(): void
    {
        $sales = [];
        $statuses = [];
        for ($index = 0; $index < 24; ++$index) {
            $posting = 'HISTORY-POSTING-'.$index;
            $sales[] = SalesFactBuilder::aSalesFact()->withCompanyId($this->companyId)
                ->withMarketplaceAccountId($this->accountId)->withSourceRowId($posting.'|PLAN-SKU-0')
                ->withPostingNumber($posting)->withOrderNumber($posting)
                ->withMarketplaceSku('PLAN-SKU-0')->withStatus('delivered')->build();
            $statuses[] = MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
                ->withCompanyId($this->companyId)->withMarketplaceAccountId($this->accountId)
                ->withPostingNumber($posting)->withOrderNumber($posting)
                ->withStatus('delivered')->build();
        }
        $this->sales()->upsertAll($sales);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), $statuses);
        $connection = $this->connection();
        $baseline = new \DateTimeImmutable('2026-06-30 10:00:00');
        $connection->insert('planning_ingestion_account_state', PlanningIngestionAccountStateBuilder::anAccountState()
            ->withCompanyId($this->companyId)->withMarketplaceAccountId($this->accountId)
            ->withUpdatedAt($baseline)->withBaselineCompletedAt($baseline)->row());
        (new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection)))->recordCompleted(
            $this->companyId->toRfc4122(), $this->accountId->toRfc4122(), 'postings',
            '2026-08-01', '2026-08-01', hash('sha256', 'one relevant outcome'), 'regular', [],
            ['HISTORY-POSTING-23|PLAN-SKU-0'],
        );
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow'));
        $query = (new PlanningResolutionsQuery($connection))->totals(
            $this->companyId->toRfc4122(), $this->accountId->toRfc4122(), ['PLAN-SKU-0'],
            $today, $today, PlanningObservationDateAxis::FIRST_KNOWN_OUTCOME->value,
        );
        $result = $query->executeQuery()->fetchAssociative();
        self::assertNotFalse($result);
        self::assertEquals(1, $result['delivered']);
        $plan = $this->explainQuery($query);
        $unitScans = $this->functionScans($plan, 'unit');
        self::assertNotSame([], $unitScans, self::planMessage($plan));
        foreach ($unitScans as $scan) {
            self::assertLessThanOrEqual(10, $scan['Actual Loops'] ?? null);
        }
    }

    public function testPlanningSkuSearchUsesTenantIndexOnHistory(): void
    {
        $query = (new PlanningMarketplaceSkusQuery($this->connection()))->search(
            $this->companyId->toRfc4122(), $this->accountId->toRfc4122(), 'PLAN-SKU', 50, null,
        );
        $plan = $this->explainQuery($query);
        $salesScans = $this->relationScans($plan, 'sales_fact');
        self::assertNotSame([], $salesScans, self::planMessage($plan));
        foreach ($salesScans as $scan) {
            self::assertNotSame('Seq Scan', $scan['Node Type'] ?? null, self::planMessage($plan));
            self::assertSame(1, $scan['Actual Loops'] ?? null, self::planMessage($plan));
            $predicate = $scan['Index Cond'] ?? $scan['Recheck Cond'] ?? null;
            self::assertIsString($predicate, self::planMessage($plan));
            self::assertStringContainsString('company_id', $predicate, self::planMessage($plan));
        }
    }

    public function testCaseInsensitiveHistoricalPrefixHasTenantTrigramIndex(): void
    {
        $this->connection()->executeStatement('ANALYZE sales_fact');
        $this->connection()->executeStatement('SET LOCAL enable_seqscan = off');
        $this->connection()->executeStatement('SET LOCAL enable_indexscan = off');
        $plan = $this->explainSql('SELECT source_row_id FROM sales_fact WHERE marketplace_sku ILIKE :prefix LIMIT 50', [
            'prefix' => 'plan-sku-0%',
        ]);
        self::assertNotSame([], $this->indexScans($plan, 'idx_sales_fact_planning_sku_trgm'), self::planMessage($plan));
        $definition = $this->connection()->fetchOne('SELECT pg_get_indexdef(i.indexrelid) FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?', ['idx_sales_fact_planning_sku_trgm']);
        self::assertIsString($definition);
        self::assertStringContainsString('(company_id, marketplace_account_id, marketplace_sku gin_trgm_ops)', $definition);
    }

    public function testPlanningSkuSearchScopesOfferAndNameToTenant(): void
    {
        /** @var MarketplaceListingRepository $listings */
        $listings = self::getContainer()->get(MarketplaceListingRepository::class);
        $foreignCompany = Uuid::v7();
        $foreignAccount = Uuid::v7();
        $foreign = [];
        $own = [];
        for ($index = 0; $index < 2400; ++$index) {
            $foreign[] = MarketplaceListingBuilder::aMarketplaceListing()
                ->withCompanyId($foreignCompany)->withMarketplaceAccountId($foreignAccount)
                ->withMarketplaceSku('FOREIGN-LISTING-'.$index)->withOfferId('MATCH-OFFER-'.$index)
                ->withName('MATCH-NAME-'.$index)->build();
            if ($index < 24) {
                $own[] = MarketplaceListingBuilder::aMarketplaceListing()
                    ->withCompanyId($this->companyId)->withMarketplaceAccountId($this->accountId)
                    ->withMarketplaceSku('OWN-LISTING-'.$index)->withOfferId('MATCH-OFFER-'.$index)
                    ->withName('MATCH-NAME-'.$index)->build();
            }
        }
        $listings->replaceForAccount($foreignCompany->toRfc4122(), $foreignAccount, $foreign);
        $listings->replaceForAccount($this->companyId->toRfc4122(), $this->accountId, $own);

        foreach (['MATCH-OFFER', 'MATCH-NAME'] as $search) {
            $query = (new PlanningMarketplaceSkusQuery($this->connection()))->search(
                $this->companyId->toRfc4122(), $this->accountId->toRfc4122(), $search, 50, null,
            );
            $plan = $this->explainQuery($query);
            $listingScans = $this->relationScans($plan, 'marketplace_listing');
            self::assertNotSame([], $listingScans, self::planMessage($plan));
            foreach ($listingScans as $scan) {
                self::assertContains($scan['Actual Loops'] ?? null, [0, 1], self::planMessage($plan));
                $predicate = implode(' ', array_filter([$scan['Index Cond'] ?? null, $scan['Recheck Cond'] ?? null, $scan['Filter'] ?? null], 'is_string'));
                self::assertStringContainsString('company_id', $predicate, self::planMessage($plan));
                self::assertStringContainsString('marketplace_account_id', $predicate, self::planMessage($plan));
            }
        }
    }

    public function testRareSubstringPredicatesCanUseTrigramIndexes(): void
    {
        /** @var MarketplaceListingRepository $listings */
        $listings = self::getContainer()->get(MarketplaceListingRepository::class);
        $own = [];
        for ($index = 0; $index < 3600; ++$index) {
            $own[] = MarketplaceListingBuilder::aMarketplaceListing()
                ->withCompanyId($this->companyId)->withMarketplaceAccountId($this->accountId)
                ->withMarketplaceSku('OWN-LARGE-'.$index)
                ->withOfferId(1 === $index ? 'RAREOFFERNEEDLE' : 'COMMON-OFFER-'.$index)
                ->withName(2 === $index ? 'RARENAMEFIND' : 'Common name '.$index)->build();
        }
        $listings->replaceForAccount($this->companyId->toRfc4122(), $this->accountId, $own);
        $this->connection()->executeStatement('ANALYZE marketplace_listing');

        $this->connection()->executeStatement('SET LOCAL enable_seqscan = off');
        $this->connection()->executeStatement('SET LOCAL enable_indexscan = off');
        foreach (['offer_id' => ['RAREOFFERNEEDLE', 'idx_planning_listing_offer_trgm'], 'name' => ['RARENAMEFIND', 'idx_planning_listing_name_trgm']] as $column => [$search, $expectedIndex]) {
            $plan = $this->explainSql("SELECT marketplace_sku FROM marketplace_listing WHERE {$column} ILIKE :search LIMIT 50", ['search' => '%'.$search.'%']);
            $scans = $this->indexScans($plan, $expectedIndex);
            self::assertNotSame([], $scans, self::planMessage($plan));
            $definition = $this->connection()->fetchOne('SELECT pg_get_indexdef(i.indexrelid) FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?', [$expectedIndex]);
            self::assertIsString($definition);
            self::assertStringContainsString("(company_id, marketplace_account_id, {$column} gin_trgm_ops)", $definition);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function reportQueries(): iterable
    {
        yield 'rate list' => ['rate'];
        yield 'rate list sorted by actual buyout' => ['rate_actual'];
        yield 'forecast list' => ['forecast'];
        yield 'daily series' => ['daily'];
    }

    #[DataProvider('reportQueries')]
    public function testReportQueryEvaluatesTenantOutcomeOnlyOnce(string $queryName): void
    {
        $query = $this->reportQuery($queryName);
        $plan = $this->explainQuery($query);
        $salesFactScans = $this->relationScans($plan, 'sales_fact');

        self::assertSame([], $this->repeatedBaseTableScans($plan), self::planMessage($plan));
        self::assertCount(1, $salesFactScans, self::planMessage($plan));
        self::assertSame(1, $salesFactScans[0]['Actual Loops'] ?? null, self::planMessage($plan));
        $this->assertTenantPredicateIsPushedIntoBaseScans($plan);
    }

    private function seedProductionShapedCohort(): void
    {
        $sales = [];
        $statuses = [];
        for ($index = 0; $index < 24; ++$index) {
            $posting = 'PLAN-POSTING-'.$index;
            $order = 'PLAN-ORDER-'.intdiv($index, 3);
            $sku = 'PLAN-SKU-'.intdiv($index, 6);
            $sales[] = SalesFactBuilder::aSalesFact()
                ->withCompanyId($this->companyId)
                ->withMarketplaceAccountId($this->accountId)
                ->withSourceRowId($posting.'|'.$sku)
                ->withPostingNumber($posting)
                ->withOrderNumber($order)
                ->withMarketplaceSku($sku)
                ->withStatus('delivered')
                ->withBusinessDate(new \DateTimeImmutable('2026-08-01'))
                ->build();
            $statuses[] = MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
                ->withCompanyId($this->companyId)
                ->withMarketplaceAccountId($this->accountId)
                ->withPostingNumber($posting)
                ->withOrderNumber($order)
                ->withStatus('delivering')
                ->withObservedAt(new \DateTimeImmutable('2026-08-01 10:00:00'))
                ->build();
            $statuses[] = MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
                ->withCompanyId($this->companyId)
                ->withMarketplaceAccountId($this->accountId)
                ->withPostingNumber($posting)
                ->withOrderNumber($order)
                ->withStatus('delivered')
                ->withObservedAt(new \DateTimeImmutable('2026-08-01 11:00:00'))
                ->build();
        }

        $this->sales()->upsertAll($sales);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), $statuses);

        // The much larger foreign cohort makes a missing company_id pushdown visible in EXPLAIN.
        $foreignCompanyId = Uuid::v7();
        $foreignAccountId = Uuid::v7();
        $foreignSales = [];
        $foreignStatuses = [];
        $foreignReturns = [];
        for ($index = 0; $index < 240; ++$index) {
            $posting = 'FOREIGN-PLAN-POSTING-'.$index;
            $order = 'FOREIGN-PLAN-ORDER-'.$index;
            $sku = 'FOREIGN-PLAN-SKU-'.$index;
            $foreignSales[] = SalesFactBuilder::aSalesFact()
                ->withCompanyId($foreignCompanyId)
                ->withMarketplaceAccountId($foreignAccountId)
                ->withSourceRowId($posting.'|'.$sku)
                ->withPostingNumber($posting)
                ->withOrderNumber($order)
                ->withMarketplaceSku($sku)
                ->withStatus('delivered')
                ->withBusinessDate(new \DateTimeImmutable('2026-08-01'))
                ->build();
            $foreignStatuses[] = MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
                ->withCompanyId($foreignCompanyId)
                ->withMarketplaceAccountId($foreignAccountId)
                ->withPostingNumber($posting)
                ->withOrderNumber($order)
                ->withStatus('delivering')
                ->withObservedAt(new \DateTimeImmutable('2026-08-01 10:00:00'))
                ->build();
            $foreignStatuses[] = MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
                ->withCompanyId($foreignCompanyId)
                ->withMarketplaceAccountId($foreignAccountId)
                ->withPostingNumber($posting)
                ->withOrderNumber($order)
                ->withStatus('delivered')
                ->withObservedAt(new \DateTimeImmutable('2026-08-01 11:00:00'))
                ->build();
            $foreignReturns[] = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
                ->withCompanyId($foreignCompanyId)
                ->withMarketplaceAccountId($foreignAccountId)
                ->withSourceRowId((string) (900_000 + $index))
                ->withSourceId(900_000 + $index)
                ->withPostingNumber($posting)
                ->withOrderNumber($order)
                ->withMarketplaceSku($sku)
                ->withReturnType('ClientReturn')
                ->build();
        }

        $this->sales()->upsertAll($foreignSales);
        $this->postingStatuses()->recordChanged($foreignCompanyId->toRfc4122(), $foreignStatuses);
        $this->returns()->upsertAll($foreignReturns);
    }

    private function reportQuery(string $queryName): QueryBuilder
    {
        $connection = $this->connection();
        $from = new \DateTimeImmutable('2026-08-01');
        $to = new \DateTimeImmutable('2026-08-30');
        $asOf = new \DateTimeImmutable('2026-08-31 00:00:00 UTC');

        return match ($queryName) {
            'rate' => (new BuyoutRateQuery($connection))->build(
                $this->companyId->toRfc4122(),
                $from,
                $to,
                $asOf,
                50,
                null,
            ),
            'rate_actual' => (new BuyoutRateQuery($connection))->build(
                $this->companyId->toRfc4122(),
                $from,
                $to,
                $asOf,
                50,
                null,
                BuyoutRateSort::ActualBuyout,
                BuyoutRateDirection::Desc,
            ),
            'forecast' => (new BuyoutForecastQuery($connection))->build(
                $this->companyId->toRfc4122(),
                $from,
                $to,
                $asOf,
                50,
                null,
            ),
            'daily' => (new BuyoutDailyQuery($connection))->build(
                $this->companyId->toRfc4122(),
                'PLAN-SKU-0',
                $from,
                $to,
                $asOf,
            ),
            default => throw new \InvalidArgumentException("Unknown buyout query {$queryName}."),
        };
    }

    /** @return array<string, mixed> */
    private function explainQuery(QueryBuilder $query): array
    {
        return $this->explainSql($query->getSQL(), $query->getParameters(), $query->getParameterTypes());
    }

    /**
     * @param array<int<0, max>|string, mixed>                                        $parameters
     * @param array<int<0, max>|string, ArrayParameterType|ParameterType|Type|string> $types
     *
     * @return array<string, mixed>
     */
    private function explainSql(string $sql, array $parameters, array $types = []): array
    {
        $connection = $this->connection();
        $connection->executeStatement("SET LOCAL statement_timeout = '5s'");
        $connection->executeStatement('SET LOCAL jit = off');
        $connection->executeStatement('SET LOCAL enable_nestloop = off');
        $json = $connection->fetchOne(
            'EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON, TIMING OFF, SUMMARY OFF) '.$sql,
            $parameters,
            $types,
        );
        self::assertIsString($json);
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded[0] ?? null);
        self::assertIsArray($decoded[0]['Plan'] ?? null);

        return $decoded[0]['Plan'];
    }

    /** @param array<string, mixed> $plan
     * @return list<string>
     */
    private function repeatedBaseTableScans(array $plan): array
    {
        $repeated = [];
        foreach (['sales_fact', 'marketplace_posting_status', 'marketplace_return_fact'] as $relation) {
            foreach ($this->relationScans($plan, $relation) as $scan) {
                $loops = $scan['Actual Loops'] ?? null;
                if (\is_int($loops) && $loops > 1) {
                    $nodeType = $scan['Node Type'] ?? 'scan';
                    $repeated[] = (\is_string($nodeType) ? $nodeType : 'scan').' '.$relation.' loops='.$loops;
                }
            }
        }

        return $repeated;
    }

    /**
     * @param array<string, mixed> $plan
     *
     * @return list<array<string, mixed>>
     */
    private function relationScans(array $plan, string $relation): array
    {
        $scans = [];
        if (($plan['Relation Name'] ?? null) === $relation) {
            $scans[] = $plan;
        }
        $children = $plan['Plans'] ?? [];
        if (!\is_array($children)) {
            return $scans;
        }
        foreach ($children as $child) {
            if (\is_array($child)) {
                /** @var array<string, mixed> $child */
                $scans = [...$scans, ...$this->relationScans($child, $relation)];
            }
        }

        return $scans;
    }

    /** @param array<string, mixed> $plan
     * @return list<array<string, mixed>>
     */
    private function indexScans(array $plan, string $indexName): array
    {
        $scans = ($plan['Index Name'] ?? null) === $indexName ? [$plan] : [];
        $children = $plan['Plans'] ?? [];
        if (!\is_array($children)) {
            return $scans;
        }
        foreach ($children as $child) {
            if (\is_array($child)) {
                /** @var array<string, mixed> $child */
                $scans = [...$scans, ...$this->indexScans($child, $indexName)];
            }
        }

        return $scans;
    }

    /** @param array<string, mixed> $plan
     * @return list<array<string, mixed>>
     */
    private function functionScans(array $plan, string $alias): array
    {
        $scans = ('Function Scan' === ($plan['Node Type'] ?? null) && $alias === ($plan['Alias'] ?? null)) ? [$plan] : [];
        $children = $plan['Plans'] ?? [];
        if (!\is_array($children)) {
            return $scans;
        }
        foreach ($children as $child) {
            if (\is_array($child)) {
                /** @var array<string, mixed> $child */
                $scans = [...$scans, ...$this->functionScans($child, $alias)];
            }
        }

        return $scans;
    }

    /** @param array<string, mixed> $plan
     * @return list<array<string, mixed>>
     */
    private function subplanScans(array $plan, string $subplanName): array
    {
        $scans = ($plan['Subplan Name'] ?? null) === $subplanName ? [$plan] : [];
        $children = $plan['Plans'] ?? [];
        if (!\is_array($children)) {
            return $scans;
        }
        foreach ($children as $child) {
            if (\is_array($child)) {
                /** @var array<string, mixed> $child */
                $scans = [...$scans, ...$this->subplanScans($child, $subplanName)];
            }
        }

        return $scans;
    }

    /** @param array<string, mixed> $plan */
    private function assertTenantPredicateIsPushedIntoBaseScans(
        array $plan,
        bool $expectAccountPredicate = false,
    ): void {
        foreach (['sales_fact', 'marketplace_posting_status', 'marketplace_return_fact'] as $relation) {
            $scans = $this->relationScans($plan, $relation);
            self::assertNotSame([], $scans, self::planMessage($plan));

            foreach ($scans as $scan) {
                self::assertSame(1, $scan['Actual Loops'] ?? null, self::planMessage($plan));
                self::assertNotSame('Seq Scan', $scan['Node Type'] ?? null, self::planMessage($plan));
                $indexPredicates = implode(' ', array_filter([
                    $scan['Index Cond'] ?? null,
                    $scan['Recheck Cond'] ?? null,
                ], 'is_string'));
                self::assertStringContainsString('company_id', $indexPredicates, self::planMessage($plan));
                if ($expectAccountPredicate) {
                    $allScanPredicates = $indexPredicates.' '.(\is_string($scan['Filter'] ?? null) ? $scan['Filter'] : '');
                    self::assertStringContainsString('marketplace_account_id', $allScanPredicates, self::planMessage($plan));
                }
            }
        }
    }

    /** @param array<string, mixed> $plan */
    private static function planMessage(array $plan): string
    {
        $summary = [];
        $walk = static function (array $node) use (&$walk, &$summary): void {
            if (isset($node['Relation Name'])) {
                $summary[] = [
                    'node' => $node['Node Type'] ?? null,
                    'relation' => $node['Relation Name'],
                    'alias' => $node['Alias'] ?? null,
                    'loops' => $node['Actual Loops'] ?? null,
                    'rows' => $node['Actual Rows'] ?? null,
                    'index_condition' => $node['Index Cond'] ?? null,
                    'recheck_condition' => $node['Recheck Cond'] ?? null,
                    'filter' => $node['Filter'] ?? null,
                ];
            }
            $children = $node['Plans'] ?? [];
            if (!\is_array($children)) {
                return;
            }
            foreach ($children as $child) {
                if (\is_array($child)) {
                    /* @var array<string, mixed> $child */
                    $walk($child);
                }
            }
        };
        $walk($plan);

        return (string) json_encode($summary, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
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
