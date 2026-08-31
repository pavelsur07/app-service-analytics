<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\MarketplacePostingStatusRepository;
use App\Ingestion\Domain\MarketplaceReturnFactRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Query\BuyoutDailyQuery;
use App\Ingestion\Infrastructure\Query\BuyoutForecastQuery;
use App\Ingestion\Infrastructure\Query\BuyoutRateDirection;
use App\Ingestion\Infrastructure\Query\BuyoutRateQuery;
use App\Ingestion\Infrastructure\Query\BuyoutRateSort;
use App\Tests\Support\Builder\MarketplacePostingStatusBuilder;
use App\Tests\Support\Builder\MarketplaceReturnFactBuilder;
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
