<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Demand;

/**
 * Единственное определение спроса Ozon — для «Остатков» и матрицы спроса
 * (docs/plan/ozon-demand-matrix-report.md, решения 1, 2 и 4).
 *
 * - Регион спроса — кластер покупателя (`cluster_to`), а не склада
 *   отгрузки: иначе где товара нет, туда не отгружают, и там «нет
 *   продаж» — прогноз подтверждал бы сам себя. `cluster_from` в CTE
 *   не выбирается, чтобы его нельзя было взять по ошибке.
 * - Без отменённых. У Ozon FBO невыкуп тоже `cancelled`, поэтому спрос
 *   ближе к выкупленным заказам.
 * - Дата — день заказа (`business_date`, Europe/Moscow).
 */
final class DemandSql
{
    public const array EXCLUDED_STATUSES = ['cancelled'];
    public const string REGION_BASIS = 'buyer_cluster';
    public const string PERIOD_BASIS = 'order_date_europe_moscow';

    /**
     * CTE `demand_lines`: строки продаж компании за окно, включая обе
     * границы. Параметры: :companyId, :demandFrom, :windowEnd (даты Y-m-d).
     * `cluster` пуст, если Ozon кластер покупателя не прислал.
     */
    public static function linesCte(): string
    {
        // Константы класса, не ввод: подстановка литералом безопасна.
        $excluded = implode(', ', array_map(static fn (string $status): string => "'{$status}'", self::EXCLUDED_STATUSES));

        return <<<SQL
            demand_lines AS MATERIALIZED (
                SELECT marketplace_account_id, marketplace_sku, cluster_to AS cluster,
                       business_date, quantity
                FROM sales_fact
                WHERE company_id = :companyId
                  AND business_date BETWEEN :demandFrom AND :windowEnd
                  AND status NOT IN ({$excluded})
            )
            SQL;
    }

    /**
     * CTE `ever_sold`: пары SKU × кластер покупателя страницы, где SKU
     * продавался за всю историю компании (по тому же определению продажи).
     * Параметры: :companyId, :skus (список SKU страницы).
     */
    public static function everSoldClustersCte(): string
    {
        $excluded = implode(', ', array_map(static fn (string $status): string => "'{$status}'", self::EXCLUDED_STATUSES));

        return <<<SQL
            ever_sold AS (
                SELECT DISTINCT marketplace_sku, cluster_to AS cluster
                FROM sales_fact
                WHERE company_id = :companyId
                  AND marketplace_sku IN (:skus)
                  AND cluster_to IS NOT NULL
                  AND status NOT IN ({$excluded})
            )
            SQL;
    }

    private function __construct()
    {
    }
}
