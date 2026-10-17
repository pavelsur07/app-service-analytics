<?php

declare(strict_types=1);

namespace App\SalesPlanning\Infrastructure\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * SKU плана с итогами и корзинами периода — DBAL в readonly DTO
 * (CLAUDE.md §5), агрегации в PostgreSQL, выручка в bigint без float.
 *
 * Два запроса на страницу, не запрос на SKU: страница SKU с итогами
 * (keyset по артикулу, лимит), затем корзины всех SKU страницы одним
 * запросом по списку (§6).
 *
 * Оба идут по уникальному индексу строк `(company_id, sales_plan_id,
 * marketplace_sku, plan_date)`: компания первым столбцом (§1).
 */
final readonly class SalesPlanSkusQuery
{
    public const int DEFAULT_LIMIT = 50;
    public const int MAX_LIMIT = 200;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function page(string $companyId, string $planId, ?string $cursor, int $limit): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder()
            ->select(
                'marketplace_sku',
                'SUM(quantity)::bigint AS total_quantity',
                'SUM(quantity::bigint * price_minor)::bigint AS revenue_minor',
            )
            ->from('sales_plan_line')
            ->where('company_id = :companyId')
            ->andWhere('sales_plan_id = :planId')
            ->setParameter('companyId', $companyId)
            ->setParameter('planId', $planId)
            ->groupBy('marketplace_sku')
            ->orderBy('marketplace_sku', 'ASC')
            // +1 — признак следующей страницы без COUNT(*) (§5).
            ->setMaxResults($limit + 1);

        if (null !== $cursor) {
            $qb->andWhere('marketplace_sku > :cursor')->setParameter('cursor', $cursor);
        }

        return $qb;
    }

    /**
     * @param non-empty-list<string> $skus SKU одной страницы
     */
    public function buckets(string $companyId, string $planId, array $skus, SalesPlanGrouping $grouping): QueryBuilder
    {
        $bucket = $grouping->bucketExpression();
        if (null === $bucket) {
            throw new \LogicException('У группировки по всему периоду корзин нет.');
        }

        return $this->connection->createQueryBuilder()
            ->select(
                'marketplace_sku',
                "to_char(MIN(plan_date), 'YYYY-MM-DD') AS date_from",
                "to_char(MAX(plan_date), 'YYYY-MM-DD') AS date_to",
                'SUM(quantity)::bigint AS quantity',
                'SUM(quantity::bigint * price_minor)::bigint AS revenue_minor',
            )
            ->from('sales_plan_line')
            ->where('company_id = :companyId')
            ->andWhere('sales_plan_id = :planId')
            ->andWhere('marketplace_sku IN (:skus)')
            ->setParameter('companyId', $companyId)
            ->setParameter('planId', $planId)
            ->setParameter('skus', $skus, ArrayParameterType::STRING)
            ->groupBy('marketplace_sku', $bucket)
            ->orderBy('marketplace_sku', 'ASC')
            ->addOrderBy('date_from', 'ASC');
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapSku(array $row): SalesPlanSkuRow
    {
        return new SalesPlanSkuRow(
            self::string($row, 'marketplace_sku'),
            self::int($row, 'total_quantity'),
            self::int($row, 'revenue_minor'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapBucket(array $row): SalesPlanBucketRow
    {
        return new SalesPlanBucketRow(
            self::string($row, 'marketplace_sku'),
            self::string($row, 'date_from'),
            self::string($row, 'date_to'),
            self::int($row, 'quantity'),
            self::int($row, 'revenue_minor'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (!\is_string($value)) {
            throw new \UnexpectedValueException(\sprintf('Expected string in %s.', $column));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function int(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if (!\is_int($value)) {
            throw new \UnexpectedValueException(\sprintf('Expected integer in %s.', $column));
        }

        return $value;
    }
}
