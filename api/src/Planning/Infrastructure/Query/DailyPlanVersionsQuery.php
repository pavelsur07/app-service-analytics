<?php

declare(strict_types=1);

namespace App\Planning\Infrastructure\Query;

use App\Planning\Infrastructure\Import\PlanImportRow;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class DailyPlanVersionsQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<PlanImportRow> $rows
     */
    public function build(string $companyId, string $marketplaceAccountId, array $rows): QueryBuilder
    {
        if ([] === $rows) {
            throw new \InvalidArgumentException('Для запроса версий нужен непустой набор строк.');
        }
        $requested = array_map(static fn (PlanImportRow $row): array => [
            'marketplace_sku' => $row->marketplaceSku,
            'business_date' => $row->businessDate,
        ], $rows);

        return $this->connection->createQueryBuilder()
            ->select('requested.marketplace_sku', 'requested.business_date::text AS business_date', 'plan.quantity', 'COALESCE(plan.version, 0) AS version')
            ->from(<<<'SQL'
                (
                  SELECT marketplace_sku, business_date
                  FROM jsonb_to_recordset(:rows::jsonb)
                       AS input(marketplace_sku TEXT, business_date DATE)
                )
                SQL, 'requested')
            ->leftJoin('requested', 'planning_daily_plan', 'plan', <<<'SQL'
                plan.company_id = :company
                AND plan.marketplace_account_id = :account
                AND plan.marketplace_sku = requested.marketplace_sku
                AND plan.business_date = requested.business_date
                SQL)
            ->setParameter('rows', json_encode($requested, \JSON_THROW_ON_ERROR))
            ->setParameter('company', $companyId)
            ->setParameter('account', $marketplaceAccountId)
            ->setMaxResults(\count($rows));
    }

    public static function key(string $sku, string $date): string
    {
        return $sku."\0".$date;
    }
}
