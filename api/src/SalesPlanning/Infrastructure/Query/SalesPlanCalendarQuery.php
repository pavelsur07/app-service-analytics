<?php

declare(strict_types=1);

namespace App\SalesPlanning\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/** Header and keyset page belong to one PostgreSQL statement/snapshot. */
final readonly class SalesPlanCalendarQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function build(string $companyId, string $planId, int $limit = 50, ?string $afterDate = null, ?string $afterId = null): QueryBuilder
    {
        if ($limit < 1 || $limit > 200 || (null === $afterDate) !== (null === $afterId)) {
            throw new \InvalidArgumentException('Некорректные параметры страницы календаря.');
        }
        $condition = null === $afterDate ? '' : ' AND (e.date_from, e.id) > (CAST(:afterDate AS date), CAST(:afterId AS uuid))';
        $query = $this->connection->createQueryBuilder()
            ->select('p.version', 'p.status', 'p.date_from', 'p.date_to', 'e.id AS entry_id', 'e.marketplace_sku', 'e.kind', 'e.date_from AS entry_from', 'e.date_to AS entry_to')
            ->from('sales_plan', 'p')
            ->leftJoin('p', 'LATERAL (
                SELECT e.id, e.marketplace_sku, e.kind, e.date_from, e.date_to
                FROM sales_plan_calendar_entry e
                WHERE e.company_id = p.company_id AND e.sales_plan_id = p.id'.$condition.'
                ORDER BY e.date_from, e.id LIMIT '.($limit + 1).'
             )', 'e', 'TRUE')
            ->where('p.company_id = :companyId AND p.id = :planId')
            ->orderBy('e.date_from')->addOrderBy('e.id')
            ->setParameter('companyId', $companyId)->setParameter('planId', $planId);
        if (null !== $afterDate) {
            $query->setParameter('afterDate', $afterDate)->setParameter('afterId', $afterId);
        }

        return $query;
    }
}
