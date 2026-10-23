<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Demand;

/**
 * Дни нулевого остатка SKU в кластере за окно спроса — общие для «Остатков»
 * (поправка на дефицит) и матрицы спроса (пометка «спрос занижен»);
 * ADR-034, docs/plan/ozon-demand-matrix-2-query.md.
 *
 * - День известен, только если полный снимок есть у каждого подключения,
 *   снимавшего остатки в окне (`complete_days`); неполный день нулём
 *   не считается.
 * - День нулевого остатка SKU в кластере — полный день, когда SKU был
 *   в запросе снимка, а строки с доступным остатком в кластере нет:
 *   `requested_count.days − positive_count.days`. Нет строки в
 *   `positive_count` — товара в кластере не было ни одного дня.
 *
 * CTE `stock_runs`, `complete_days`, `requested_count`, `positive_count`.
 * Параметры: :companyId, :demandFrom, :windowEnd.
 */
final class StockoutSql
{
    public static function cte(): string
    {
        return <<<'SQL'
            stock_runs AS MATERIALIZED (
                SELECT marketplace_account_id, snapshot_date, requested_skus
                FROM stock_snapshot_run
                WHERE company_id = :companyId
                  AND started_at IS NOT NULL
                  AND snapshot_date BETWEEN :demandFrom AND :windowEnd
            ),
            complete_days AS (
                SELECT snapshot_date
                FROM stock_runs
                GROUP BY snapshot_date
                HAVING COUNT(DISTINCT marketplace_account_id) = (SELECT COUNT(DISTINCT marketplace_account_id) FROM stock_runs)
            ),
            requested_count AS (
                SELECT sku.value AS marketplace_sku, COUNT(DISTINCT r.snapshot_date) AS days
                FROM stock_runs r
                JOIN complete_days d ON d.snapshot_date = r.snapshot_date
                CROSS JOIN LATERAL jsonb_array_elements_text(r.requested_skus) AS sku(value)
                GROUP BY sku.value
            ),
            positive_count AS (
                SELECT f.marketplace_sku, f.cluster_name AS cluster, COUNT(DISTINCT f.snapshot_date) AS days
                FROM stock_snapshot_fact f
                JOIN complete_days d ON d.snapshot_date = f.snapshot_date
                WHERE f.company_id = :companyId
                  AND f.available > 0
                GROUP BY f.marketplace_sku, f.cluster_name
            )
            SQL;
    }

    private function __construct()
    {
    }
}
