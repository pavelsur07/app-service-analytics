<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\DeliverySpeed;

/**
 * Общая основа запросов отчёта «Скорость доставки»
 * (docs/plan/ozon-delivery-speed-report.md, решения 1–3).
 *
 * Единица — отправление: у него один маршрут. Доставка — от заказа
 * (sales_fact.ordered_at) до прибытия к покупателю: первое наблюдение
 * delivering/posting_in_pickup_point или delivered/* (курьер). Ozon точного
 * момента не отдаёт, известно лишь первое наблюдение нового статуса, поэтому
 * момент оценивается серединой между предыдущим опросом и наблюдением.
 *
 * В расчёт идут только отправления, которые синхронизация видела вживую —
 * первое наблюдение не позже LIVE_OBSERVATION_MAX_LAG после заказа. Заказы
 * начальной загрузки впервые «наблюдены» спустя недели, и их доставка
 * выглядела бы месячной.
 *
 * Длительности — целые секунды: EXTRACT(EPOCH) даёт numeric, percentile_disc
 * возвращает значение из выборки — float нет.
 */
final class DeliverySpeedSql
{
    /**
     * Шаг опроса отправлений: тик раз в SCHEDULER_INTERVAL перечитывает заказы
     * последних TICK_WINDOW_DAYS дат, более старые — только ночной рескан раз
     * в сутки. Должно совпадать с DispatchActiveOzonSyncsAction::postingWindowDays
     * и интервалом ScheduleOzonSyncCommand.
     */
    public const int TICK_WINDOW_DAYS = 3;
    public const int TICK_STEP_SECONDS = 900;
    public const int RESCAN_STEP_SECONDS = 86_400;

    public const int LIVE_OBSERVATION_MAX_LAG_HOURS = 24;

    /** Заказы моложе этого срока ещё доезжают — медиана была бы занижена. */
    public const int MATURITY_LAG_DAYS = 14;

    /** Ниже — медианы и доли не отдаются (как в «Локализации»). */
    public const int MIN_POSTINGS = 10;

    public const string START_EVENT = 'in_process_at';
    public const string ARRIVAL_EVENT = 'pickup_point_or_delivered';
    public const string ESTIMATE = 'midpoint_of_poll_gap';

    /**
     * CTE `postings`, `observations`, `timed`. Параметры: :companyId, :from, :to.
     */
    public static function timedCte(): string
    {
        $handover = self::estimate('o.handover_observed');
        $arrival = self::estimate('o.arrival_observed');
        $liveLag = self::LIVE_OBSERVATION_MAX_LAG_HOURS;

        return <<<SQL
            postings AS MATERIALIZED (
                SELECT marketplace_account_id, posting_number,
                       MIN(ordered_at) AS ordered_at,
                       MIN(business_date) AS business_date,
                       MIN(cluster_from) AS cluster_from,
                       MIN(cluster_to) AS cluster_to
                FROM sales_fact
                WHERE company_id = :companyId
                  AND business_date BETWEEN :from AND :to
                  AND posting_number IS NOT NULL
                GROUP BY marketplace_account_id, posting_number
            ),
            observations AS MATERIALIZED (
                SELECT st.marketplace_account_id, st.posting_number,
                       MIN(st.observed_at) AS first_observed,
                       MIN(st.observed_at) FILTER (WHERE st.status = 'delivering') AS handover_observed,
                       MIN(st.observed_at) FILTER (
                           WHERE (st.status = 'delivering' AND st.substatus = 'posting_in_pickup_point')
                              OR st.status = 'delivered'
                       ) AS arrival_observed
                FROM marketplace_posting_status st
                JOIN postings p
                  ON p.marketplace_account_id = st.marketplace_account_id
                 AND p.posting_number = st.posting_number
                WHERE st.company_id = :companyId
                GROUP BY st.marketplace_account_id, st.posting_number
            ),
            timed AS (
                SELECT p.marketplace_account_id, p.posting_number, p.cluster_from, p.cluster_to,
                       (p.cluster_from IS NOT NULL AND p.cluster_to IS NOT NULL) AS has_clusters,
                       COALESCE(p.cluster_from = p.cluster_to, false) AS is_local,
                       (p.ordered_at IS NOT NULL
                        AND o.first_observed IS NOT NULL
                        AND o.first_observed <= p.ordered_at + interval '{$liveLag} hours') AS live,
                       o.arrival_observed IS NOT NULL AS arrived,
                       o.handover_observed IS NOT NULL AS handed_over,
                       COALESCE({$arrival['byRescan']}, false) AS arrival_by_rescan,
                       GREATEST(0, EXTRACT(EPOCH FROM ({$arrival['moment']} - p.ordered_at)))::bigint AS delivery_seconds,
                       GREATEST(0, EXTRACT(EPOCH FROM ({$handover['moment']} - p.ordered_at)))::bigint AS assembly_seconds,
                       GREATEST(0, EXTRACT(EPOCH FROM ({$arrival['moment']} - {$handover['moment']})))::bigint AS transit_seconds
                FROM postings p
                LEFT JOIN observations o
                       ON o.marketplace_account_id = p.marketplace_account_id
                      AND o.posting_number = p.posting_number
            )
            SQL;
    }

    /**
     * Показатели группы строк `timed`, уже отобранных по `live`.
     */
    public static function metricsSelect(): string
    {
        $median = static fn (string $column, string $filter): string => "percentile_disc(0.5) WITHIN GROUP (ORDER BY {$column}) FILTER (WHERE {$filter})";

        return 'COUNT(*)::bigint AS postings,'
            .' COUNT(*) FILTER (WHERE arrived)::bigint AS arrived_postings,'
            .' COUNT(*) FILTER (WHERE arrived AND arrival_by_rescan)::bigint AS rescan_arrived_postings,'
            .' COUNT(*) FILTER (WHERE handed_over)::bigint AS handed_over_postings,'
            .' COUNT(*) FILTER (WHERE arrived AND handed_over)::bigint AS arrived_handed_over_postings,'
            .' COUNT(*) FILTER (WHERE arrived AND is_local)::bigint AS local_arrived_postings,'
            .' COUNT(*) FILTER (WHERE arrived AND has_clusters AND NOT is_local)::bigint AS nonlocal_arrived_postings,'
            .' '.$median('delivery_seconds', 'arrived').' AS median_delivery_seconds,'
            .' percentile_disc(0.9) WITHIN GROUP (ORDER BY delivery_seconds) FILTER (WHERE arrived) AS p90_delivery_seconds,'
            .' '.$median('assembly_seconds', 'handed_over').' AS median_assembly_seconds,'
            .' '.$median('transit_seconds', 'arrived AND handed_over').' AS median_transit_seconds,'
            .' '.$median('delivery_seconds', 'arrived AND is_local').' AS median_local_seconds,'
            .' '.$median('delivery_seconds', 'arrived AND has_clusters AND NOT is_local').' AS median_nonlocal_seconds';
    }

    /**
     * Потерянные часы ожидания группы: нелокальные прибывшие отправления ×
     * max(0, медиана нелокальных − медиана локальных). Считаются, только
     * когда обе медианы опираются на MIN_POSTINGS отправлений. Округление
     * вверх: положительная потеря — хотя бы час, ноль — только честный ноль.
     */
    public static function lostHours(string $alias): string
    {
        $min = self::MIN_POSTINGS;

        return "CASE WHEN {$alias}.local_arrived_postings >= {$min} AND {$alias}.nonlocal_arrived_postings >= {$min}"
            ." THEN CEIL({$alias}.nonlocal_arrived_postings::numeric"
            ." * GREATEST(0, {$alias}.median_nonlocal_seconds - {$alias}.median_local_seconds) / 3600)::bigint END";
    }

    /**
     * CTE `sku_lost` поверх `timed`: потерянные часы ожидания по паре
     * SKU × кластер доставки — нелокальные прибывшие отправления SKU ×
     * разница медиан кластера (DeliverySpeedSkuQuery). Общая для отчёта
     * «Доставка» и приоритета рекомендаций по остаткам (ADR-034, план C):
     * одна формула потери, а не две копии. Параметры — те же, что у timedCte.
     */
    public static function skuLostCte(): string
    {
        $min = self::MIN_POSTINGS;

        return <<<SQL
            by_cluster AS (
                SELECT cluster_to,
                       COUNT(*) FILTER (WHERE arrived AND is_local) AS local_arrived,
                       COUNT(*) FILTER (WHERE arrived AND NOT is_local) AS nonlocal_arrived,
                       percentile_disc(0.5) WITHIN GROUP (ORDER BY delivery_seconds) FILTER (WHERE arrived AND is_local) AS median_local_seconds,
                       percentile_disc(0.5) WITHIN GROUP (ORDER BY delivery_seconds) FILTER (WHERE arrived AND NOT is_local) AS median_nonlocal_seconds
                FROM timed
                WHERE live AND has_clusters
                GROUP BY cluster_to
            ),
            cluster_gap AS (
                SELECT cluster_to, median_local_seconds, median_nonlocal_seconds,
                       GREATEST(0, median_nonlocal_seconds - median_local_seconds) AS gap_seconds
                FROM by_cluster
                WHERE local_arrived >= {$min} AND nonlocal_arrived >= {$min}
            ),
            lines AS (
                SELECT marketplace_account_id, posting_number, marketplace_sku, quantity
                FROM sales_fact
                WHERE company_id = :companyId
                  AND business_date BETWEEN :from AND :to
                  AND posting_number IS NOT NULL
            ),
            by_sku AS (
                SELECT l.marketplace_sku, t.cluster_to,
                       SUM(l.quantity)::bigint AS quantity,
                       COALESCE(SUM(l.quantity) FILTER (WHERE NOT t.is_local), 0)::bigint AS nonlocal_quantity,
                       COUNT(DISTINCT (t.marketplace_account_id, t.posting_number)) FILTER (WHERE t.arrived AND NOT t.is_local) AS nonlocal_arrived_postings
                FROM lines l
                JOIN timed t
                  ON t.marketplace_account_id = l.marketplace_account_id
                 AND t.posting_number = l.posting_number
                WHERE t.live AND t.has_clusters
                GROUP BY l.marketplace_sku, t.cluster_to
            ),
            sku_lost AS (
                SELECT b.*, g.median_local_seconds, g.median_nonlocal_seconds,
                       CEIL(b.nonlocal_arrived_postings::numeric * g.gap_seconds / 3600)::bigint AS lost_hours
                FROM by_sku b
                JOIN cluster_gap g ON g.cluster_to = b.cluster_to
                WHERE g.gap_seconds > 0 AND b.nonlocal_arrived_postings > 0
            )
            SQL;
    }

    /**
     * @return array<string, int|string>
     */
    public static function parameters(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return [
            'companyId' => $companyId,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'rescanStep' => self::RESCAN_STEP_SECONDS,
            'tickStep' => self::TICK_STEP_SECONDS,
            'tickWindowDays' => self::TICK_WINDOW_DAYS,
        ];
    }

    /**
     * Оценка момента события по первому наблюдению: середина между ним и
     * предыдущим опросом. Предыдущий опрос определяется по дате наблюдения
     * относительно даты заказа (часовой пояс площадки):
     * - в окне тика — наблюдение минус шаг тика;
     * - первые сутки после окна — конец окна тика (последний тик прошлого
     *   вечера), а не прошлый рескан: иначе оценка ушла бы раньше опроса,
     *   на котором статус был ещё прежним;
     * - дальше — прошлый ночной рескан, наблюдение минус сутки.
     *
     * @return array{moment: string, byRescan: string}
     */
    private static function estimate(string $observed): array
    {
        $ageDays = "((({$observed} AT TIME ZONE 'UTC') AT TIME ZONE 'Europe/Moscow')::date - p.business_date)";
        $tickWindowEnd = "(((p.business_date + :tickWindowDays::int)::timestamp AT TIME ZONE 'Europe/Moscow') AT TIME ZONE 'UTC')";
        $previousPoll = "(CASE WHEN {$ageDays} < :tickWindowDays::int THEN {$observed} - interval '1 second' * :tickStep::int"
            ." WHEN {$ageDays} = :tickWindowDays::int THEN LEAST({$observed}, {$tickWindowEnd})"
            ." ELSE {$observed} - interval '1 second' * :rescanStep::int END)";

        return [
            'moment' => "({$previousPoll} + ({$observed} - {$previousPoll}) / 2)",
            'byRescan' => "({$ageDays} >= :tickWindowDays::int)",
        ];
    }
}
