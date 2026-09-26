<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\StockPlacement;

use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedSql;

/**
 * Рекомендация раскладки остатков Ozon FBO по кластерам
 * (docs/plan/ozon-stock-placement-report.md, решение 6; ADR-034).
 * Все деления — в PostgreSQL на numeric, штуки — целые; float нет.
 *
 * - Остаток — последний полный снимок каждого подключения не старше
 *   вчерашнего (снимок раз в сутки), сумма складов кластера (ПВЗ входят).
 *   Остаток SKU известен, только если каждое подключение, где SKU
 *   продаётся или снимается, запросило его свежим полным снимком: иначе
 *   сумма по компании неполна, отсутствие строки — «неизвестно», а не ноль
 *   (ADR-034), и строка получает статус unknown_stock без остатка, товара
 *   в пути и рекомендации. Подключение без свежего снимка своих SKU не
 *   подтверждает — месячный остаток за текущий не идёт.
 * - Спрос — продажи по кластеру доставки за DEMAND_WINDOW_DAYS полных
 *   дней, заканчивая вчерашним (сегодняшний ещё не закончился), без
 *   отменённых. Поправка на дефицит — дни, когда полный снимок показывает
 *   ноль (SKU в запросе дня, строк с остатком в кластере нет), исключаются
 *   из знаменателя; только когда полных дней снимков — все DEMAND_WINDOW_DAYS,
 *   иначе без поправки (решение 6). День без полного снимка — неизвестный.
 * - Рекомендация = max(0, ⌈спрос в день × (целевое покрытие + срок поставки)
 *   − доступно − в пути − заявлено⌉), только при продажах от MIN_SALES.
 * - Приоритет — потерянные часы ожидания варианта B (DeliverySpeedSql::skuLostCte).
 */
final class StockPlacementSql
{
    public const int DEMAND_WINDOW_DAYS = 28;
    public const int MIN_SALES = 10;
    public const int SURPLUS_FACTOR = 2;
    public const int ABC_A_BPS = 8000;
    public const int ABC_B_BPS = 9500;

    public const int DEFAULT_TARGET_DAYS = 28;
    public const int DEFAULT_LEAD_DAYS = 7;
    public const int MIN_TARGET_DAYS = 7;
    public const int MAX_TARGET_DAYS = 90;
    public const int MIN_LEAD_DAYS = 0;
    public const int MAX_LEAD_DAYS = 60;

    /** Порядок статусов в ответе и допустимые значения фильтра. */
    public const array STATUSES = ['deficit', 'normal', 'surplus', 'insufficient_data', 'no_sales', 'unknown_stock'];

    /**
     * CTE до `measured` включительно. Параметры: :companyId, :today,
     * :demandFrom, :windowDays, :minSales, :abcA, :abcB.
     */
    public static function placementCte(): string
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
            last_run AS (
                SELECT DISTINCT ON (marketplace_account_id) marketplace_account_id, snapshot_date
                FROM stock_snapshot_run
                WHERE company_id = :companyId
                  AND started_at IS NOT NULL
                  AND snapshot_date BETWEEN :recentFrom AND :today
                ORDER BY marketplace_account_id, snapshot_date DESC
            ),
            fresh_requested AS (
                SELECT DISTINCT lr.marketplace_account_id, sku.value AS marketplace_sku
                FROM last_run lr
                JOIN stock_snapshot_run r
                  ON r.company_id = :companyId
                 AND r.marketplace_account_id = lr.marketplace_account_id
                 AND r.snapshot_date = lr.snapshot_date
                CROSS JOIN LATERAL jsonb_array_elements_text(r.requested_skus) AS sku(value)
            ),
            sku_accounts AS (
                SELECT DISTINCT marketplace_account_id, marketplace_sku
                FROM sales_fact
                WHERE company_id = :companyId
                  AND business_date BETWEEN :demandFrom AND :windowEnd
                  AND status <> 'cancelled'
                UNION
                SELECT DISTINCT r.marketplace_account_id, sku.value
                FROM stock_runs r
                CROSS JOIN LATERAL jsonb_array_elements_text(r.requested_skus) AS sku(value)
                UNION
                SELECT marketplace_account_id, marketplace_sku FROM fresh_requested
            ),
            known_skus AS (
                SELECT sa.marketplace_sku
                FROM sku_accounts sa
                LEFT JOIN fresh_requested fr
                  ON fr.marketplace_account_id = sa.marketplace_account_id
                 AND fr.marketplace_sku = sa.marketplace_sku
                GROUP BY sa.marketplace_sku
                HAVING BOOL_AND(fr.marketplace_sku IS NOT NULL)
            ),
            stock_now AS (
                SELECT f.marketplace_sku, f.cluster_name AS cluster,
                       SUM(f.available)::bigint AS available,
                       SUM(f.transit)::bigint AS transit,
                       SUM(f.requested)::bigint AS requested,
                       MAX(f.ads_cluster) AS ads_cluster,
                       MAX(f.idc_cluster) AS idc_cluster
                FROM stock_snapshot_fact f
                JOIN last_run r
                  ON r.marketplace_account_id = f.marketplace_account_id
                 AND r.snapshot_date = f.snapshot_date
                WHERE f.company_id = :companyId
                GROUP BY f.marketplace_sku, f.cluster_name
            ),
            demand_sales AS (
                SELECT marketplace_sku, cluster_to AS cluster, SUM(quantity)::bigint AS sold
                FROM sales_fact
                WHERE company_id = :companyId
                  AND business_date BETWEEN :demandFrom AND :windowEnd
                  AND status <> 'cancelled'
                  AND cluster_to IS NOT NULL
                GROUP BY marketplace_sku, cluster_to
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
            ),
            correction AS (
                SELECT COUNT(*) >= :windowDays::int AS applied, COUNT(*)::int AS complete_days
                FROM complete_days
            ),
            abc AS (
                SELECT marketplace_sku,
                       SUM(sold) AS total,
                       SUM(SUM(sold)) OVER (ORDER BY SUM(sold) DESC, marketplace_sku) AS running,
                       SUM(SUM(sold)) OVER () AS grand
                FROM demand_sales
                GROUP BY marketplace_sku
            ),
            pairs AS (
                SELECT COALESCE(s.marketplace_sku, d.marketplace_sku) AS marketplace_sku,
                       COALESCE(s.cluster, d.cluster) AS cluster,
                       (ks.marketplace_sku IS NOT NULL) AS stock_known,
                       COALESCE(s.available, 0) AS available,
                       COALESCE(s.transit, 0) AS transit,
                       COALESCE(s.requested, 0) AS requested,
                       s.ads_cluster, s.idc_cluster,
                       COALESCE(d.sold, 0) AS sold
                FROM stock_now s
                FULL OUTER JOIN demand_sales d
                  ON d.marketplace_sku = s.marketplace_sku
                 AND d.cluster = s.cluster
                LEFT JOIN known_skus ks
                  ON ks.marketplace_sku = COALESCE(s.marketplace_sku, d.marketplace_sku)
            ),
            measured AS (
                SELECT p.*,
                       c.applied AS correction_applied,
                       GREATEST(0, COALESCE(rc.days, 0) - COALESCE(pc.days, 0))::int AS zero_days,
                       p.sold::numeric / GREATEST(1, :windowDays::int
                           - CASE WHEN c.applied THEN GREATEST(0, COALESCE(rc.days, 0) - COALESCE(pc.days, 0)) ELSE 0 END) AS demand,
                       CASE WHEN a.grand IS NULL OR a.grand = 0 THEN 'C'
                            WHEN 10000::numeric * (a.running - a.total) / a.grand < :abcA::int THEN 'A'
                            WHEN 10000::numeric * (a.running - a.total) / a.grand < :abcB::int THEN 'B'
                            ELSE 'C' END AS abc_class
                FROM pairs p
                CROSS JOIN correction c
                LEFT JOIN requested_count rc ON rc.marketplace_sku = p.marketplace_sku
                LEFT JOIN positive_count pc ON pc.marketplace_sku = p.marketplace_sku AND pc.cluster = p.cluster
                LEFT JOIN abc a ON a.marketplace_sku = p.marketplace_sku
            )
            SQL;
    }

    /**
     * Показатели строки поверх `measured`. Параметры: :targetDays,
     * :leadDays, :minSales, :surplusFactor.
     */
    public static function rowSelect(): string
    {
        return <<<'SQL'
            m.marketplace_sku, m.cluster, m.stock_known,
            CASE WHEN m.stock_known THEN m.available END AS available,
            CASE WHEN m.stock_known THEN m.transit END AS transit,
            CASE WHEN m.stock_known THEN m.requested END AS requested,
            m.sold,
            m.zero_days, m.correction_applied, m.abc_class, m.ads_cluster, m.idc_cluster,
            ROUND(m.demand * 1000)::bigint AS demand_milli_per_day,
            CASE WHEN m.stock_known AND m.demand > 0 THEN FLOOR(m.available / m.demand)::int END AS cover_days,
            CASE WHEN m.stock_known AND m.sold >= :minSales::int
                 THEN GREATEST(0, CEIL(m.demand * (:targetDays::int + :leadDays::int) - m.available - m.transit - m.requested))::bigint
            END AS recommended,
            CASE WHEN NOT m.stock_known THEN 'unknown_stock'
                 WHEN m.sold = 0 THEN 'no_sales'
                 WHEN m.sold < :minSales::int THEN 'insufficient_data'
                 WHEN m.available / m.demand < :leadDays::int THEN 'deficit'
                 WHEN m.available / m.demand > :surplusFactor::int * :targetDays::int THEN 'surplus'
                 ELSE 'normal' END AS status
            SQL;
    }

    /**
     * @return array<string, int|string>
     */
    public static function parameters(string $companyId, \DateTimeImmutable $today, int $targetDays, int $leadDays): array
    {
        return [
            'companyId' => $companyId,
            'today' => $today->format('Y-m-d'),
            'recentFrom' => $today->modify('-1 day')->format('Y-m-d'),
            'windowEnd' => $today->modify('-1 day')->format('Y-m-d'),
            'demandFrom' => $today->modify('-'.self::DEMAND_WINDOW_DAYS.' days')->format('Y-m-d'),
            'windowDays' => self::DEMAND_WINDOW_DAYS,
            'minSales' => self::MIN_SALES,
            'abcA' => self::ABC_A_BPS,
            'abcB' => self::ABC_B_BPS,
            'targetDays' => $targetDays,
            'leadDays' => $leadDays,
            'surplusFactor' => self::SURPLUS_FACTOR,
        ];
    }

    /**
     * Окно варианта B для приоритета (DeliverySpeedSql): созревшие
     * 30 дней, заканчивающиеся за MATURITY_LAG_DAYS до сегодня.
     *
     * @return array<string, int|string>
     */
    public static function deliveryParameters(string $companyId, \DateTimeImmutable $today): array
    {
        $to = $today->modify('-'.DeliverySpeedSql::MATURITY_LAG_DAYS.' days');

        return DeliverySpeedSql::parameters($companyId, $to->modify('-29 days'), $to);
    }
}
