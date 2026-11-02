<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Infrastructure\Query;

use App\ProductResearchOzon\Domain\MonthSlice;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class ResearchCacheEstimateQuery
{
    public function __construct(private Connection $db)
    {
    }

    /** @param list<MonthSlice> $months */
    public function months(string $companyId, string $sourceId, string $categoryPath, array $months): QueryBuilder
    {
        if ([] === $months || \count($months) > 24) {
            throw new \InvalidArgumentException('month_limit');
        }
        $values = json_encode(array_map(static fn (MonthSlice $m): array => ['date_from' => $m->dateFrom, 'date_to' => $m->dateTo, 'partial' => $m->partial], $months), \JSON_THROW_ON_ERROR);

        return $this->db->createQueryBuilder()
            ->select("COALESCE(SUM(CASE WHEN NOT wanted.partial AND EXISTS(SELECT 1 FROM product_research_ozon_run r JOIN product_research_ozon_project p ON p.company_id=r.company_id AND p.id=r.project_id JOIN product_research_ozon_run_month m ON m.company_id=r.company_id AND m.run_id=r.id WHERE r.company_id=:company AND r.source_id=:source AND p.category_path=:category AND m.status='ready' AND NOT m.partial AND m.date_from=wanted.date_from AND m.date_to=wanted.date_to) THEN 1 ELSE 0 END),0)::int AS ready_count")
            ->from('jsonb_to_recordset(CAST(:months AS jsonb))', 'wanted(date_from date,date_to date,partial boolean)')
            ->setParameter('months', $values)->setParameter('company', $companyId)->setParameter('source', $sourceId)->setParameter('category', $categoryPath)->setMaxResults(1);
    }
}
