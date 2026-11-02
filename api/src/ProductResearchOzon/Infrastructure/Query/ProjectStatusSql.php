<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Infrastructure\Query;

/** Derived from one MVCC view of local run projection and <=24 frozen requirements. */
final class ProjectStatusSql
{
    public static function expression(string $alias): string
    {
        if (!\in_array($alias, ['p'], true)) {
            throw new \InvalidArgumentException('invalid_alias');
        }

        return "(CASE WHEN p.last_run_id IS NULL THEN 'draft'
          WHEN EXISTS(SELECT 1 FROM product_research_ozon_run active WHERE active.company_id=p.company_id AND active.project_id=p.id AND active.finished_at IS NULL) THEN 'loading'
          ELSE (SELECT CASE WHEN SUM(ready)=jsonb_array_length(p.requirements) AND jsonb_array_length(p.requirements)>0 THEN 'loaded' WHEN SUM(ready)>0 THEN 'partially_loaded' ELSE 'error' END
            FROM (SELECT CASE WHEN latest.status='ready' AND latest.date_from::text=req->>'date_from' AND latest.date_to::text=req->>'date_to' AND latest.partial=(req->>'partial')::boolean THEN 1 ELSE 0 END AS ready
              FROM jsonb_array_elements(p.requirements) req
              LEFT JOIN LATERAL (SELECT m.status,m.date_from,m.date_to,m.partial FROM product_research_ozon_run r JOIN product_research_ozon_run_month m ON m.company_id=r.company_id AND m.run_id=r.id
                WHERE r.company_id=p.company_id AND r.project_id=p.id AND m.month=req->>'month'
                AND r.source_id=(SELECT source_id FROM product_research_ozon_run last WHERE last.company_id=p.company_id AND last.project_id=p.id AND last.id=p.last_run_id)
                ORDER BY r.id DESC LIMIT 1) latest ON TRUE) covered)
          END)";
    }
}
