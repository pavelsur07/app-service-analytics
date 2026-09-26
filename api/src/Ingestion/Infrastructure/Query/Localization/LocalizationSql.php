<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Localization;

/**
 * Общая основа запросов отчёта «Локализация» (docs/plan/ozon-localization-report.md,
 * решения 2 и 3). Все суммы, доли и деления выполняет PostgreSQL на
 * integer/numeric — float нет ни в SQL, ни в PHP.
 *
 * Строка отчёта — строка продажи (отправление × SKU) с датой заказа
 * в периоде. Штуки, доли и прямая логистика — без отменённых; обратная
 * логистика — по всем, включая отменённые: у Ozon FBO невыкуп тоже
 * cancelled, и именно на него она и начисляется (решение владельца
 * 2026-09-26). Локальная — cluster_from = cluster_to.
 * Логистика привязана к продаже по номеру отправления и SKU
 * (marketplace_expense_fact.unit_number = sales_fact.posting_number, ADR-012).
 * Расходы отбираются с начала периода: начисление по продаже не бывает
 * раньше её даты, а индекс (company_id, business_date) уже есть.
 */
final class LocalizationSql
{
    /** Логистика, Доставка до места выдачи, Доставка до места выдачи силами Ozon. */
    public const array FORWARD_FEE_TYPES = [32, 29, 98];

    /** Обратная логистика; Обработка возвратов, отмен и невыкупов партнёрами. */
    public const array REVERSE_FEE_TYPES = [59, 45];

    /**
     * Ниже этого числа штук доля и логистика на штуку шумят и не
     * показываются (решение 3).
     */
    public const int MIN_QUANTITY = 10;

    public const string LOCAL_SALE = 'cluster_from_equals_cluster_to';
    public const string PERIOD_BASIS = 'order_date_europe_moscow';
    public const array EXCLUDED_STATUSES = ['cancelled'];
    public const string PER_UNIT_BASIS = 'charged_units_only';

    /** Округление логистики на штуку — половина от нуля до копейки (решение 2). */
    public const string ROUNDING = 'half_away_from_zero';

    /**
     * CTE `lines`. Параметры: :companyId, :from, :to (даты Y-m-d).
     */
    public static function linesCte(): string
    {
        $forward = implode(', ', self::FORWARD_FEE_TYPES);
        $reverse = implode(', ', self::REVERSE_FEE_TYPES);
        $all = implode(', ', [...self::FORWARD_FEE_TYPES, ...self::REVERSE_FEE_TYPES]);
        // Константы класса, не ввод: подстановка литералом безопасна и держит
        // SQL и определение в ответе API на одном источнике.
        $excluded = implode(', ', array_map(static fn (string $status): string => "'{$status}'", self::EXCLUDED_STATUSES));

        return <<<SQL
            sales AS MATERIALIZED (
                SELECT marketplace_account_id, posting_number, marketplace_sku, quantity,
                       cluster_from, cluster_to
                       , status NOT IN ({$excluded}) AS counted
                FROM sales_fact
                WHERE company_id = :companyId
                  AND business_date BETWEEN :from AND :to
            ),
            logistics AS MATERIALIZED (
                SELECT marketplace_account_id, unit_number, marketplace_sku,
                       -SUM(amount_minor) FILTER (WHERE fee_type_id IN ({$forward})) AS forward_cost_minor,
                       -SUM(amount_minor) FILTER (WHERE fee_type_id IN ({$reverse})) AS reverse_cost_minor,
                       MIN(currency) AS currency_min,
                       MAX(currency) AS currency_max
                FROM marketplace_expense_fact
                WHERE company_id = :companyId
                  AND business_date >= :from
                  AND fee_type_id IN ({$all})
                GROUP BY marketplace_account_id, unit_number, marketplace_sku
            ),
            lines AS (
                SELECT s.marketplace_sku, s.quantity, s.cluster_from, s.cluster_to, s.counted,
                       (s.cluster_from IS NOT NULL AND s.cluster_to IS NOT NULL) AS in_cluster,
                       (s.counted AND s.cluster_from IS NOT NULL AND s.cluster_to IS NOT NULL) AS has_clusters,
                       (s.counted AND COALESCE(s.cluster_from = s.cluster_to, false)) AS is_local,
                       CASE WHEN s.counted THEN l.forward_cost_minor END AS forward_cost_minor,
                       l.reverse_cost_minor, l.currency_min, l.currency_max
                FROM sales s
                LEFT JOIN logistics l
                       ON l.marketplace_account_id = s.marketplace_account_id
                      AND l.unit_number = s.posting_number
                      AND l.marketplace_sku = s.marketplace_sku
            )
            SQL;
    }

    /**
     * Агрегаты группы строк `lines`. Логистика на штуку — только по строкам,
     * где прямая логистика уже начислена: иначе свежие продажи без
     * начислений занижали бы её.
     */
    public static function metricsSelect(): string
    {
        return <<<'SQL'
            COALESCE(SUM(quantity) FILTER (WHERE counted), 0)::bigint AS quantity,
            COALESCE(SUM(quantity) FILTER (WHERE has_clusters), 0)::bigint AS clustered_quantity,
            COALESCE(SUM(quantity) FILTER (WHERE is_local), 0)::bigint AS local_quantity,
            COALESCE(SUM(quantity) FILTER (WHERE has_clusters AND NOT is_local), 0)::bigint AS nonlocal_quantity,
            COALESCE(SUM(quantity) FILTER (WHERE forward_cost_minor IS NOT NULL), 0)::bigint AS charged_quantity,
            COALESCE(SUM(quantity) FILTER (WHERE is_local AND forward_cost_minor IS NOT NULL), 0)::bigint AS local_charged_quantity,
            COALESCE(SUM(quantity) FILTER (WHERE has_clusters AND NOT is_local AND forward_cost_minor IS NOT NULL), 0)::bigint AS nonlocal_charged_quantity,
            ROUND(SUM(forward_cost_minor) FILTER (WHERE is_local)::numeric
                  / NULLIF(SUM(quantity) FILTER (WHERE is_local AND forward_cost_minor IS NOT NULL), 0))::bigint
                AS local_forward_cost_per_unit_minor,
            ROUND(SUM(forward_cost_minor) FILTER (WHERE has_clusters AND NOT is_local)::numeric
                  / NULLIF(SUM(quantity) FILTER (WHERE has_clusters AND NOT is_local AND forward_cost_minor IS NOT NULL), 0))::bigint
                AS nonlocal_forward_cost_per_unit_minor,
            ROUND(10000::numeric * SUM(quantity) FILTER (WHERE is_local)
                  / NULLIF(SUM(quantity) FILTER (WHERE has_clusters), 0))::int AS local_share_bps,
            ROUND(10000::numeric * SUM(quantity) FILTER (WHERE forward_cost_minor IS NOT NULL)
                  / NULLIF(SUM(quantity) FILTER (WHERE counted), 0))::int AS charged_share_bps,
            SUM(reverse_cost_minor)::bigint AS reverse_cost_minor,
            MIN(currency_min) AS currency_min,
            MAX(currency_max) AS currency_max
            SQL;
    }
}
