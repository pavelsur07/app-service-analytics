<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class PlanningOrderCohortsQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /** @param list<string> $marketplaceSkus */
    public function build(
        string $companyId,
        string $marketplaceAccountId,
        array $marketplaceSkus,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $limit,
        ?string $cursorSku,
        ?string $cursorDate,
    ): QueryBuilder {
        $source = <<<'SQL'
            (
                WITH tenant_outcome AS MATERIALIZED (
                    SELECT marketplace_sku, business_date, quantity, outcome, is_forecast_eligible
                    FROM buyout_outcome
                    WHERE company_id = :companyId
                      AND marketplace_account_id = :accountId
                      AND marketplace_sku IN (:skus)
                      AND business_date >= :from AND business_date <= :to
                )
                SELECT marketplace_sku, business_date,
                       SUM(quantity)::bigint AS ordered,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome IN ('D', 'R')), 0)::bigint AS bought,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome IN ('T1', 'T2', 'P')), 0)::bigint AS terminal_no_buy,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome IS NULL AND is_forecast_eligible), 0)::bigint AS open_eligible,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome IS NULL AND NOT is_forecast_eligible), 0)::bigint AS unknown
                FROM tenant_outcome
                GROUP BY marketplace_sku, business_date
            ) cohort
            SQL;

        $query = $this->connection->createQueryBuilder()
            ->select('marketplace_sku', 'business_date', 'ordered', 'bought', 'terminal_no_buy', 'open_eligible', 'unknown')
            ->from($source)
            ->setParameter('companyId', $companyId)
            ->setParameter('accountId', $marketplaceAccountId)
            ->setParameter('skus', $marketplaceSkus, ArrayParameterType::STRING)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('marketplace_sku', 'ASC')
            ->addOrderBy('business_date', 'ASC')
            ->setMaxResults($limit + 1);

        if (null !== $cursorSku && null !== $cursorDate) {
            $query->andWhere('(marketplace_sku, business_date) > (:cursorSku, :cursorDate::date)')
                ->setParameter('cursorSku', $cursorSku)
                ->setParameter('cursorDate', $cursorDate);
        }

        return $query;
    }
}
