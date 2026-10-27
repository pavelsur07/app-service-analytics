<?php

declare(strict_types=1);

namespace App\SalesPlanning\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Планы компании с итогами — DBAL в readonly DTO (CLAUDE.md §5).
 * build() отдаёт QueryBuilder, выполнение — у вызывающего.
 *
 * Итоги считает PostgreSQL: подзапрос по строкам каждого плана
 * страницы, а не запрос на план из PHP. Выручка — `quantity * price_minor`
 * в bigint, без float (ADR-004).
 *
 * Keyset по id по убыванию: id — UUIDv7, порядок совпадает с порядком
 * создания, свежие планы — сверху. Список растёт с работой клиента,
 * offset не годится (patterns.md, «Пагинация»).
 *
 * Тот же запрос отдаёт один план (`withPlanId`) — заголовок экрана плана:
 * одна форма строки на список и на заголовок.
 */
final readonly class SalesPlansQuery
{
    public const int DEFAULT_LIMIT = 50;
    public const int MAX_LIMIT = 200;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function build(string $companyId, ?string $marketplaceAccountId, ?string $cursor, int $limit): QueryBuilder
    {
        $qb = $this->base($companyId)
            ->orderBy('p.id', 'DESC')
            // +1 — признак следующей страницы без COUNT(*) (§5).
            ->setMaxResults($limit + 1);

        if (null !== $marketplaceAccountId) {
            $qb->andWhere('p.marketplace_account_id = :accountId')->setParameter('accountId', $marketplaceAccountId);
        }

        if (null !== $cursor) {
            $qb->andWhere('p.id < :cursor')->setParameter('cursor', $cursor);
        }

        return $qb;
    }

    public function withPlanId(string $companyId, string $planId): QueryBuilder
    {
        return $this->base($companyId)
            ->andWhere('p.id = :planId')
            ->setParameter('planId', $planId)
            ->setMaxResults(1);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): SalesPlanRow
    {
        return new SalesPlanRow(
            id: self::string($row, 'id'),
            marketplaceAccountId: self::string($row, 'marketplace_account_id'),
            name: self::string($row, 'name'),
            dateFrom: self::string($row, 'date_from'),
            dateTo: self::string($row, 'date_to'),
            currency: self::string($row, 'currency'),
            status: self::string($row, 'status'),
            lineageId: self::string($row, 'lineage_id'),
            versionNumber: self::int($row, 'version_number'),
            version: self::int($row, 'version'),
            createdAt: self::string($row, 'created_at'),
            skuCount: self::int($row, 'sku_count'),
            totalQuantity: self::int($row, 'total_quantity'),
            revenueMinor: self::int($row, 'revenue_minor'),
            metric: null === $row['metric'] ? null : self::string($row, 'metric'),
        );
    }

    private function base(string $companyId): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'p.id',
                'p.marketplace_account_id',
                'p.name',
                'p.date_from',
                'p.date_to',
                'p.currency',
                'p.status',
                'p.metric',
                'p.lineage_id',
                'p.version_number',
                'p.version',
                "to_char(p.created_at, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS created_at",
                't.sku_count',
                't.total_quantity',
                't.revenue_minor',
            )
            ->from('sales_plan', 'p')
            // LATERAL по уникальному индексу строк (company_id, sales_plan_id, …):
            // итог одного плана читает только его строки.
            ->join('p', '
                LATERAL (
                    SELECT COUNT(DISTINCT l.marketplace_sku) AS sku_count,
                           COALESCE(SUM(l.quantity), 0)::bigint AS total_quantity,
                           COALESCE(SUM(l.quantity::bigint * l.price_minor), 0)::bigint AS revenue_minor
                      FROM sales_plan_line l
                     WHERE l.company_id = p.company_id AND l.sales_plan_id = p.id
                )', 't', 'TRUE')
            ->where('p.company_id = :companyId')
            ->setParameter('companyId', $companyId);
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
