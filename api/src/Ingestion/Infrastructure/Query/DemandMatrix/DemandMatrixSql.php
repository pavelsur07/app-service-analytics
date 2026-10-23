<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\DemandMatrix;

use App\Ingestion\Infrastructure\Query\Demand\DemandSql;

/**
 * Матрица спроса Ozon: SKU × кластер покупателя
 * (docs/plan/ozon-demand-matrix-report.md, этап 2). Что считается
 * спросом — DemandSql; здесь только окна и показатели. Все деления —
 * PostgreSQL на numeric, ROUND — половина от нуля; float нет.
 *
 * - Окна — 7, 14 и 28 полных дней, заканчивая вчерашним.
 * - Спрос в день — заказы 28 дней ÷ 28, в тысячных штуки.
 * - Тренд — (q7 / 7) ÷ (q28 / 28) в базисных пунктах, 10000 — без
 *   изменений; ниже MIN_SALES заказов за 28 дней — шум, не считается.
 */
final class DemandMatrixSql
{
    public const int SHORT_DAYS = 7;
    public const int MIDDLE_DAYS = 14;
    public const int WINDOW_DAYS = 28;
    public const int MIN_SALES = 10;
    public const string ROUNDING = 'half_away_from_zero';

    /**
     * CTE `demand_lines`, `by_cell` (SKU × кластер, кластер может быть
     * пуст) и `by_sku` (итоги SKU по всем кластерам).
     */
    public static function aggregatesCte(): string
    {
        return DemandSql::linesCte().', '.<<<'SQL'
            by_cell AS MATERIALIZED (
                SELECT marketplace_sku, cluster,
                       COALESCE(SUM(quantity) FILTER (WHERE business_date >= :shortFrom), 0)::bigint AS orders7,
                       COALESCE(SUM(quantity) FILTER (WHERE business_date >= :middleFrom), 0)::bigint AS orders14,
                       SUM(quantity)::bigint AS orders28
                FROM demand_lines
                GROUP BY marketplace_sku, cluster
            ),
            by_sku AS (
                SELECT marketplace_sku,
                       SUM(orders7)::bigint AS orders7,
                       SUM(orders14)::bigint AS orders14,
                       SUM(orders28)::bigint AS orders28,
                       COALESCE(SUM(orders28) FILTER (WHERE cluster IS NULL), 0)::bigint AS unknown_cluster_orders28
                FROM by_cell
                GROUP BY marketplace_sku
            )
            SQL;
    }

    /**
     * Спрос в день и тренд над колонками orders7/orders28 источника `$alias`.
     */
    public static function rateSelect(string $alias): string
    {
        $window = self::WINDOW_DAYS;
        $short = self::SHORT_DAYS;
        $minSales = self::MIN_SALES;

        // Константы класса, не ввод: подстановка литералом безопасна.
        return <<<SQL
            ROUND(1000::numeric * {$alias}.orders28 / {$window})::bigint AS demand_milli_per_day,
            CASE WHEN {$alias}.orders28 >= {$minSales}
                 THEN ROUND(10000::numeric * {$alias}.orders7 * {$window} / ({$short} * {$alias}.orders28))::int
            END AS trend_bps
            SQL;
    }

    /**
     * @return array<string, string>
     */
    public static function parameters(string $companyId, \DateTimeImmutable $today): array
    {
        return [
            'companyId' => $companyId,
            'demandFrom' => $today->modify('-'.self::WINDOW_DAYS.' days')->format('Y-m-d'),
            'middleFrom' => $today->modify('-'.self::MIDDLE_DAYS.' days')->format('Y-m-d'),
            'shortFrom' => $today->modify('-'.self::SHORT_DAYS.' days')->format('Y-m-d'),
            'windowEnd' => $today->modify('-1 day')->format('Y-m-d'),
        ];
    }

    public static function int(mixed $value): int
    {
        if (!\is_int($value) && !(\is_string($value) && is_numeric($value))) {
            throw new \UnexpectedValueException('Expected an integer in a demand matrix row.');
        }

        return (int) $value;
    }

    public static function nullableInt(mixed $value): ?int
    {
        return null === $value ? null : self::int($value);
    }

    public static function string(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string in a demand matrix row.');
        }

        return $value;
    }

    public static function nullableString(mixed $value): ?string
    {
        return null === $value ? null : self::string($value);
    }

    private function __construct()
    {
    }
}
