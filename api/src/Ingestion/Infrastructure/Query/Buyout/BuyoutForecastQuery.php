<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Buyout;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Live forecast ADR-019: mature 30-day baseline, SKU sample >=30 или
 * account fallback. Не материализуется и меняется сразу с status history.
 */
final readonly class BuyoutForecastQuery
{
    public const int MIN_TRAINING_QUANTITY = 30;

    /** ADR-031: агрегат без прогноза, если штук без оценки больше 10% количества. */
    public const int MAX_UNESTIMATED_BPS = 1000;

    /** ADR-032: кривая поправки по дням в пути после передачи — до срока созревания. */
    public const int HANDOVER_CURVE_DAYS = 15;

    public function __construct(private Connection $connection)
    {
    }

    /** @param list<string>|null $marketplaceSkus */
    public function build(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $asOf,
        int $limit,
        ?string $cursor,
        ?array $marketplaceSkus = null,
    ): QueryBuilder {
        $maturityCtes = BuyoutMaturityQuery::maturityCtes();
        $trainingDays = BuyoutMaturityQuery::trainingDaysCte();
        $trainingSample = self::MIN_TRAINING_QUANTITY;
        $aggregates = self::forecastAggregatesSql();
        $handoverCurve = self::handoverCurveCtes();
        $unestimatedRate = self::unestimatedRateSql('unestimated_quantity', 'ordered_quantity');
        $summaryUnestimatedRate = self::unestimatedRateSql('SUM(unestimated_quantity) OVER ()', 'SUM(ordered_quantity) OVER ()');
        $handoverFactorJoin = self::handoverFactorJoinSql('o');
        $quantity = self::projectedQuantitySql('projected_quantity', 'ordered_quantity', 'unestimated_quantity');
        $rate = self::projectedRateSql('projected_quantity', 'projected_eligible_quantity', 'ordered_quantity', 'unestimated_quantity');
        $summaryQuantity = self::projectedQuantitySql('SUM(projected_quantity) OVER ()', 'SUM(ordered_quantity) OVER ()', 'SUM(unestimated_quantity) OVER ()');
        $summaryRate = self::projectedRateSql('SUM(projected_quantity) OVER ()', 'SUM(projected_eligible_quantity) OVER ()', 'SUM(ordered_quantity) OVER ()', 'SUM(unestimated_quantity) OVER ()');
        $source = <<<SQL
            WITH tenant_outcome AS MATERIALIZED (
                SELECT company_id, marketplace_account_id,
                       posting_number, marketplace_sku,
                       quantity, business_date, outcome,
                       handed_over_at, resolved_at, is_forecast_eligible,
                       resolution_observed, is_in_flight
                FROM buyout_outcome
                WHERE company_id = :companyId
            ),
            {$maturityCtes},
            {$trainingDays},
            training_rows AS (
                SELECT o.*
                FROM tenant_outcome o
                JOIN training_days d
                  ON d.marketplace_account_id = o.marketplace_account_id
                 AND d.business_date = o.business_date
                WHERE o.outcome IN ('T1', 'D', 'T2', 'P')
            ),
            sku_training AS (
                SELECT marketplace_account_id, marketplace_sku,
                       SUM(quantity)::bigint AS sample_quantity,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome = 'D'), 0)::bigint AS d_quantity,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome = 'T1'), 0)::bigint AS t1_quantity,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome = 'T2'), 0)::bigint AS t2_quantity,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome = 'P'), 0)::bigint AS p_quantity
                FROM training_rows
                GROUP BY marketplace_account_id, marketplace_sku
            ),
            account_training AS (
                SELECT marketplace_account_id,
                       SUM(quantity)::bigint AS sample_quantity,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome = 'D'), 0)::bigint AS d_quantity,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome = 'T1'), 0)::bigint AS t1_quantity,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome = 'T2'), 0)::bigint AS t2_quantity,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome = 'P'), 0)::bigint AS p_quantity
                FROM training_rows
                GROUP BY marketplace_account_id
            ),
            {$handoverCurve},
            current_rows AS (
                SELECT o.*,
                       CASE
                           WHEN s.sample_quantity >= {$trainingSample}
                               THEN s.d_quantity::numeric / NULLIF(s.t1_quantity + s.d_quantity + s.t2_quantity + s.p_quantity, 0)
                           WHEN a.sample_quantity >= {$trainingSample}
                               THEN a.d_quantity::numeric / NULLIF(a.t1_quantity + a.d_quantity + a.t2_quantity + a.p_quantity, 0)
                           ELSE NULL
                       END AS pre_handover_rate,
                       CASE
                           WHEN s.sample_quantity >= {$trainingSample}
                               THEN (s.d_quantity + s.t2_quantity + s.p_quantity)::numeric / NULLIF(s.t1_quantity + s.d_quantity + s.t2_quantity + s.p_quantity, 0)
                           WHEN a.sample_quantity >= {$trainingSample}
                               THEN (a.d_quantity + a.t2_quantity + a.p_quantity)::numeric / NULLIF(a.t1_quantity + a.d_quantity + a.t2_quantity + a.p_quantity, 0)
                           ELSE NULL
                       END AS pre_handover_eligible_rate,
                       CASE
                           WHEN s.sample_quantity >= {$trainingSample}
                               THEN s.d_quantity::numeric / NULLIF(s.d_quantity + s.t2_quantity + s.p_quantity, 0)
                           WHEN a.sample_quantity >= {$trainingSample}
                               THEN a.d_quantity::numeric / NULLIF(a.d_quantity + a.t2_quantity + a.p_quantity, 0)
                           ELSE NULL
                       END AS post_handover_rate,
                       COALESCE(hf.factor, 1::numeric) AS handover_factor
                FROM tenant_outcome o
                LEFT JOIN sku_training s
                  ON s.marketplace_account_id = o.marketplace_account_id
                 AND s.marketplace_sku = o.marketplace_sku
                LEFT JOIN account_training a
                  ON a.marketplace_account_id = o.marketplace_account_id
                {$handoverFactorJoin}
                WHERE o.business_date >= :from
                  AND o.business_date <= :to
            ),
            forecast AS (
                SELECT marketplace_sku,
                       SUM(quantity)::bigint AS ordered_quantity,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome IS NOT NULL), 0)::bigint AS resolved_quantity,
                       {$aggregates}
                FROM current_rows
                GROUP BY marketplace_sku
            )
            , forecast_rows AS (
                SELECT marketplace_sku,
                       ordered_quantity,
                       resolved_quantity,
                       projected_quantity,
                       projected_eligible_quantity,
                       unestimated_quantity,
                       {$quantity} AS projected_buyout_quantity,
                       {$rate} AS projected_buyout_rate_bps,
                       ROUND(10000::numeric * resolved_quantity / NULLIF(ordered_quantity, 0))::int AS resolution_rate_bps,
                       {$unestimatedRate} AS unestimated_rate_bps
                FROM forecast
            )
            SELECT forecast_rows.*,
                   SUM(ordered_quantity) OVER ()::bigint AS summary_ordered_quantity,
                   SUM(resolved_quantity) OVER ()::bigint AS summary_resolved_quantity,
                   {$summaryQuantity} AS summary_projected_buyout_quantity,
                   {$summaryRate} AS summary_projected_buyout_rate_bps,
                   ROUND(10000::numeric * SUM(resolved_quantity) OVER () / NULLIF(SUM(ordered_quantity) OVER (), 0))::int AS summary_resolution_rate_bps,
                   {$summaryUnestimatedRate} AS summary_unestimated_rate_bps
            FROM forecast_rows
            SQL;

        $utc = new \DateTimeZone('UTC');
        $moscow = new \DateTimeZone('Europe/Moscow');
        $query = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('('.$source.')', 'forecast')
            ->setParameter('companyId', $companyId)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->setParameter('asOf', $asOf->setTimezone($utc)->format('Y-m-d H:i:s'))
            ->setParameter('asOfMoscow', $asOf->setTimezone($moscow)->format('Y-m-d H:i:s'))
            ->orderBy('marketplace_sku', 'ASC');

        // limit=0 используется только полным summary aggregate поверх
        // этого SQL; наружный запрос всё равно возвращает одну строку.
        if ($limit > 0) {
            $query->setMaxResults($limit + 1);
        }

        if (null !== $cursor) {
            $query->andWhere('marketplace_sku > :cursor')->setParameter('cursor', $cursor);
        }

        if (null !== $marketplaceSkus) {
            if ([] === $marketplaceSkus) {
                $query->andWhere('1 = 0');
            } else {
                $query->andWhere('marketplace_sku IN (:pageSkus)')
                    ->setParameter('pageSkus', $marketplaceSkus, ArrayParameterType::STRING);
            }
        }

        return $query;
    }

    /**
     * Суммы по штукам агрегата над current_rows (ADR-031): заказано,
     * ожидаемые D и ожидаемый знаменатель по штукам с оценкой и количество
     * штук без оценки. Штука без оценки — без исхода и без права на прогноз
     * либо без обучающей ставки своей стадии; в суммы прогноза она не входит.
     */
    public static function forecastAggregatesSql(): string
    {
        return <<<'SQL'
            COALESCE(SUM(quantity) FILTER (
                WHERE outcome IS NULL AND (
                    NOT is_forecast_eligible
                    OR (handed_over_at IS NULL AND pre_handover_rate IS NULL)
                    OR (handed_over_at IS NOT NULL AND post_handover_rate IS NULL)
                )
            ), 0)::bigint AS unestimated_quantity,
            COALESCE(SUM(CASE
                WHEN outcome = 'D' THEN quantity::numeric
                WHEN outcome IS NULL AND is_forecast_eligible AND handed_over_at IS NULL THEN quantity * pre_handover_rate
                WHEN outcome IS NULL AND is_forecast_eligible AND handed_over_at IS NOT NULL
                     -- LEAST пропускает NULL: без ставки произведение обязано
                     -- остаться NULL, иначе штука без оценки дала бы выкуп целиком.
                     THEN quantity * CASE WHEN post_handover_rate IS NULL THEN NULL
                                          ELSE LEAST(1::numeric, post_handover_rate * handover_factor) END
                ELSE 0::numeric
            END), 0::numeric) AS projected_quantity,
            COALESCE(SUM(CASE
                WHEN outcome IN ('D', 'T2', 'P') THEN quantity::numeric
                WHEN outcome IS NULL AND is_forecast_eligible AND handed_over_at IS NULL THEN quantity * pre_handover_eligible_rate
                WHEN outcome IS NULL AND is_forecast_eligible AND handed_over_at IS NOT NULL
                     AND post_handover_rate IS NOT NULL THEN quantity::numeric
                ELSE 0::numeric
            END), 0::numeric) AS projected_eligible_quantity
            SQL;
    }

    /**
     * CTE `handover_curve` и `handover_factor` над `training_rows` (ADR-032).
     * handover_curve — выкуп D/(D+T2+P) среди закрытых штук окна, которые
     * закрылись позже, чем через e полных дней после передачи.
     * handover_factor — поправка кабинета для e = 0..HANDOVER_CURVE_DAYS:
     * выкуп ближайшей точки не позже e с выборкой не меньше
     * MIN_TRAINING_QUANTITY, делённый на выкуп в точке 0; без точки 0
     * с достаточной выборкой поправки нет.
     */
    public static function handoverCurveCtes(): string
    {
        $days = self::HANDOVER_CURVE_DAYS;
        $sample = self::MIN_TRAINING_QUANTITY;

        return <<<SQL
            handover_curve AS (
                SELECT t.marketplace_account_id, e.elapsed_days,
                       SUM(t.quantity)::bigint AS sample_quantity,
                       COALESCE(SUM(t.quantity) FILTER (WHERE t.outcome = 'D'), 0)::numeric / SUM(t.quantity) AS buyout_rate
                FROM training_rows t
                CROSS JOIN generate_series(0, {$days}) AS e(elapsed_days)
                WHERE t.outcome IN ('D', 'T2', 'P')
                  AND t.handed_over_at IS NOT NULL
                  AND t.resolved_at IS NOT NULL
                  AND t.resolution_observed
                  AND EXTRACT(EPOCH FROM (t.resolved_at - t.handed_over_at)) > e.elapsed_days * 86400
                GROUP BY t.marketplace_account_id, e.elapsed_days
            ),
            handover_factor AS (
                SELECT DISTINCT ON (base.marketplace_account_id, e.elapsed_days)
                       base.marketplace_account_id, e.elapsed_days,
                       point.buyout_rate / NULLIF(base.buyout_rate, 0) AS factor
                FROM handover_curve base
                CROSS JOIN generate_series(0, {$days}) AS e(elapsed_days)
                JOIN handover_curve point
                  ON point.marketplace_account_id = base.marketplace_account_id
                 AND point.elapsed_days <= e.elapsed_days
                 AND point.sample_quantity >= {$sample}
                WHERE base.elapsed_days = 0
                  AND base.sample_quantity >= {$sample}
                ORDER BY base.marketplace_account_id, e.elapsed_days, point.elapsed_days DESC
            )
            SQL;
    }

    /** LEFT JOIN поправки ADR-032 по полным дням от передачи штуки до :asOf. */
    public static function handoverFactorJoinSql(string $alias): string
    {
        $days = self::HANDOVER_CURVE_DAYS;

        return <<<SQL
            LEFT JOIN handover_factor hf
              ON hf.marketplace_account_id = {$alias}.marketplace_account_id
             AND hf.elapsed_days = LEAST({$days}, GREATEST(0, FLOOR(
                     EXTRACT(EPOCH FROM (:asOf::timestamp - {$alias}.handed_over_at)) / 86400
                 )))::int
            SQL;
    }

    /** Доля заказанного количества без оценки (ADR-031), bps; NULL без заказов. */
    public static function unestimatedRateSql(string $unestimated, string $ordered): string
    {
        return "ROUND(10000::numeric * {$unestimated} / NULLIF({$ordered}, 0))::int";
    }

    /**
     * Ставка агрегата по суммам ADR-031: NULL, если штук без оценки больше
     * порога или ожидаемый знаменатель пуст.
     */
    public static function projectedRateSql(string $projected, string $eligible, string $ordered, string $unestimated): string
    {
        $limit = self::MAX_UNESTIMATED_BPS;

        return "CASE WHEN {$eligible} > 0 AND 10000 * {$unestimated} <= {$limit} * {$ordered}"
            ." THEN ROUND(10000::numeric * {$projected} / {$eligible})::int ELSE NULL END";
    }

    /**
     * Ожидаемый выкуп в штуках: ожидаемые D штук с оценкой, масштабированные
     * на всё заказанное количество (ADR-031); NULL при превышении порога.
     * Пустой ожидаемый знаменатель (все штуки T1 или R) количество не
     * обнуляет: ожидаемый выкуп тогда 0 штук, NULL только у ставки.
     */
    public static function projectedQuantitySql(string $projected, string $ordered, string $unestimated): string
    {
        $limit = self::MAX_UNESTIMATED_BPS;

        return "CASE WHEN {$ordered} > {$unestimated} AND 10000 * {$unestimated} <= {$limit} * {$ordered}"
            ." THEN ROUND({$projected} * {$ordered} / ({$ordered} - {$unestimated}))::int ELSE NULL END";
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): BuyoutForecastRow
    {
        return new BuyoutForecastRow(
            marketplaceSku: self::string($row['marketplace_sku'] ?? null),
            orderedQuantity: self::integer($row['ordered_quantity'] ?? null),
            resolvedQuantity: self::integer($row['resolved_quantity'] ?? null),
            projectedBuyoutQuantity: self::nullableInteger($row['projected_buyout_quantity'] ?? null),
            projectedBuyoutRateBps: self::nullableInteger($row['projected_buyout_rate_bps'] ?? null),
            resolutionRateBps: self::integer($row['resolution_rate_bps'] ?? null),
            unestimatedRateBps: self::nullableInteger($row['unestimated_rate_bps'] ?? null),
        );
    }

    private static function string(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected string in buyout forecast row.');
        }

        return $value;
    }

    private static function integer(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && 1 === preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException('Expected integer in buyout forecast row.');
    }

    private static function nullableInteger(mixed $value): ?int
    {
        return null === $value ? null : self::integer($value);
    }
}
