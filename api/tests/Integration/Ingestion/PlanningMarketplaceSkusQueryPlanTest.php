<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Infrastructure\Query\PlanningMarketplaceSkusQuery;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class PlanningMarketplaceSkusQueryPlanTest extends KernelTestCase
{
    private Connection $connection;
    private string $companyId;
    private string $accountId;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $this->connection = $connection;
        $this->companyId = Uuid::v7()->toRfc4122();
        $this->accountId = Uuid::v7()->toRfc4122();
        $foreignCompanyId = Uuid::v7()->toRfc4122();
        $foreignAccountId = Uuid::v7()->toRfc4122();

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

        foreach ([$query->known($this->companyId, $this->accountId, $skus), $query->knownDetails($this->companyId, $this->accountId, $skus)] as $statement) {
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

    private function insertListings(string $companyId, string $accountId, int $count, string $prefix): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO marketplace_listing (
                company_id, marketplace_account_id, marketplace_sku, offer_id, name, photo_url, first_seen_at
            )
            SELECT :company, :account, :prefix || value::text, :prefix || value::text, NULL, NULL, NOW()
            FROM generate_series(1, :count) AS value
            SQL, ['company' => $companyId, 'account' => $accountId, 'prefix' => $prefix, 'count' => $count], [
            'count' => ParameterType::INTEGER,
        ]);
    }

    private function insertSales(string $companyId, string $accountId, int $count): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO sales_fact (
                company_id, marketplace_account_id, source_row_id, business_date, status, marketplace_sku,
                quantity, amount_minor, commission_amount_minor, currency, raw_document_id, row_hash,
                first_loaded_at, last_updated_at, posting_number, order_number
            )
            SELECT :company, :account, 'FOREIGN-ROW-' || value::text, DATE '2026-09-01', 'delivered',
                   'FOREIGN-SKU-' || value::text, 1, 0, 0, 'RUB', md5('foreign-raw-' || value::text)::uuid,
                   repeat('a', 64), NOW(), NOW(), 'FOREIGN-POSTING-' || value::text, 'FOREIGN-ORDER-' || value::text
            FROM generate_series(1, :count) AS value
            SQL, ['company' => $companyId, 'account' => $accountId, 'count' => $count], [
            'count' => ParameterType::INTEGER,
        ]);
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
