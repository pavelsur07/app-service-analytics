<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\MarketplaceListingRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Query\PlanningMarketplaceSkusQuery;
use App\Tests\Support\Builder\MarketplaceListingBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class PlanningMarketplaceSkusQueryPlanTest extends KernelTestCase
{
    private Connection $connection;
    private Uuid $companyId;
    private Uuid $accountId;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $this->connection = $connection;
        $this->companyId = Uuid::v7();
        $this->accountId = Uuid::v7();
        $foreignCompanyId = Uuid::v7();
        $foreignAccountId = Uuid::v7();

        $this->insertListings($this->companyId, $this->accountId, 10_000, 'IMPORT-SKU-');
        $this->insertListings($foreignCompanyId, $foreignAccountId, 50_000, 'FOREIGN-SKU-');
        $this->insertSales($foreignCompanyId, $foreignAccountId, 50_000);
        $this->connection->executeStatement('ANALYZE marketplace_listing');
        $this->connection->executeStatement('ANALYZE sales_fact');
    }

    public function testImportChecksRemainTenantScopedAtMaximumBatchSize(): void
    {
        $skus = array_map(static fn (int $index): string => 'IMPORT-SKU-'.$index, range(1, 10_000));
        $query = new PlanningMarketplaceSkusQuery($this->connection);

        foreach ([$query->known($this->companyId->toRfc4122(), $this->accountId->toRfc4122(), $skus), $query->knownDetails($this->companyId->toRfc4122(), $this->accountId->toRfc4122(), $skus)] as $statement) {
            $plan = $this->explain($statement);
            foreach (['marketplace_listing', 'sales_fact'] as $relation) {
                $scans = $this->relationScans($plan, $relation);
                self::assertNotSame([], $scans, self::planMessage($plan));
                foreach ($scans as $scan) {
                    self::assertLessThanOrEqual(1, $scan['Actual Loops'] ?? null, self::planMessage($plan));
                    self::assertNotSame('Seq Scan', $scan['Node Type'] ?? null, self::planMessage($plan));
                    $predicates = implode(' ', array_filter([
                        $scan['Index Cond'] ?? null,
                        $scan['Recheck Cond'] ?? null,
                        $scan['Filter'] ?? null,
                    ], 'is_string'));
                    self::assertStringContainsString('company_id', $predicates, self::planMessage($plan));
                    self::assertStringContainsString('marketplace_account_id', $predicates, self::planMessage($plan));
                }
            }
        }
    }

    private function insertListings(Uuid $companyId, Uuid $accountId, int $count, string $prefix): void
    {
        $listings = [];
        for ($index = 1; $index <= $count; ++$index) {
            $sku = $prefix.$index;
            $listings[] = MarketplaceListingBuilder::aMarketplaceListing()
                ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withMarketplaceSku($sku)->withOfferId($sku)->withName(null)->withPhotoUrl(null)->build();
        }
        $this->listings()->replaceForAccount($companyId->toRfc4122(), $accountId, $listings);
    }

    private function insertSales(Uuid $companyId, Uuid $accountId, int $count): void
    {
        for ($start = 1; $start <= $count; $start += 500) {
            $facts = [];
            $last = min($start + 499, $count);
            for ($index = $start; $index <= $last; ++$index) {
                $facts[] = SalesFactBuilder::aSalesFact()->withCompanyId($companyId)
                    ->withMarketplaceAccountId($accountId)->withSourceRowId('FOREIGN-ROW-'.$index)
                    ->withMarketplaceSku('FOREIGN-SKU-'.$index)->withPostingNumber('FOREIGN-POSTING-'.$index)
                    ->withOrderNumber('FOREIGN-ORDER-'.$index)->withBusinessDate(new \DateTimeImmutable('2026-09-01'))->build();
            }
            $this->sales()->upsertAll($facts);
        }
    }

    private function listings(): MarketplaceListingRepository
    {
        /** @var MarketplaceListingRepository $repository */
        $repository = self::getContainer()->get(MarketplaceListingRepository::class);

        return $repository;
    }

    private function sales(): SalesFactRepository
    {
        /** @var SalesFactRepository $repository */
        $repository = self::getContainer()->get(SalesFactRepository::class);

        return $repository;
    }

    /** @return array<string, mixed> */
    private function explain(QueryBuilder $query): array
    {
        $this->connection->executeStatement("SET LOCAL statement_timeout = '5s'");
        $this->connection->executeStatement('SET LOCAL jit = off');
        $json = $this->connection->fetchOne(
            'EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON, TIMING OFF, SUMMARY OFF) '.$query->getSQL(),
            $query->getParameters(),
            $query->getParameterTypes(),
        );
        self::assertIsString($json);
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $first = $decoded[0] ?? null;
        self::assertIsArray($first);
        $plan = $first['Plan'] ?? null;
        self::assertIsArray($plan);

        return $plan;
    }

    /**
     * @param array<string, mixed> $plan
     *
     * @return list<array<string, mixed>>
     */
    private function relationScans(array $plan, string $relation): array
    {
        $scans = ($plan['Relation Name'] ?? null) === $relation ? [$plan] : [];
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
    private static function planMessage(array $plan): string
    {
        $summary = [];
        $walk = static function (array $node) use (&$walk, &$summary): void {
            if (isset($node['Relation Name'])) {
                $summary[] = [
                    'node' => $node['Node Type'] ?? null,
                    'relation' => $node['Relation Name'],
                    'loops' => $node['Actual Loops'] ?? null,
                    'index_condition' => $node['Index Cond'] ?? null,
                    'recheck_condition' => $node['Recheck Cond'] ?? null,
                ];
            }
            foreach (($node['Plans'] ?? []) as $child) {
                if (\is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($plan);

        return (string) json_encode($summary, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }
}
