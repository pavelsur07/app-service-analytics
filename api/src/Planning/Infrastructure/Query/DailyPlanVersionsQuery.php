<?php

declare(strict_types=1);

namespace App\Planning\Infrastructure\Query;

use App\Planning\Infrastructure\Import\PlanImportRow;
use Doctrine\DBAL\Connection;

final readonly class DailyPlanVersionsQuery
{
    public function __construct(private Connection $connection) {}

    /**
     * @param list<PlanImportRow> $rows
     *
     * @return array<string, array{quantity: ?int, version: int}>
     */
    public function forRows(string $companyId, string $marketplaceAccountId, array $rows): array
    {
        if ([] === $rows) {
            return [];
        }
        $requested = array_map(static fn (PlanImportRow $row): array => [
            'marketplace_sku' => $row->marketplaceSku,
            'business_date' => $row->businessDate,
        ], $rows);
        /** @var list<array{marketplace_sku: string, business_date: string, quantity: int|string|null, version: int|string}> $result */
        $result = $this->connection->executeQuery(<<<'SQL'
            WITH requested AS (
              SELECT marketplace_sku, business_date
              FROM jsonb_to_recordset(:rows::jsonb)
                   AS input(marketplace_sku TEXT, business_date DATE)
            )
            SELECT requested.marketplace_sku,
                   requested.business_date::text AS business_date,
                   plan.quantity,
                   COALESCE(plan.version, 0) AS version
            FROM requested
            LEFT JOIN planning_daily_plan plan
              ON plan.company_id = :company
             AND plan.marketplace_account_id = :account
             AND plan.marketplace_sku = requested.marketplace_sku
             AND plan.business_date = requested.business_date
            SQL, [
                'rows' => json_encode($requested, \JSON_THROW_ON_ERROR),
                'company' => $companyId,
                'account' => $marketplaceAccountId,
            ])->fetchAllAssociative();

        $versions = [];
        foreach ($result as $row) {
            $versions[self::key($row['marketplace_sku'], $row['business_date'])] = [
                'quantity' => null === $row['quantity'] ? null : (int) $row['quantity'],
                'version' => (int) $row['version'],
            ];
        }

        return $versions;
    }

    public static function key(string $sku, string $date): string
    {
        return $sku."\0".$date;
    }
}
