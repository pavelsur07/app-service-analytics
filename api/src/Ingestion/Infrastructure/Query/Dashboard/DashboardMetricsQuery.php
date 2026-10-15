<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Dashboard;

use App\Ingestion\Domain\OzonDashboardFeeTypes;
use App\Ingestion\Domain\OzonFeeTypeNames;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Показатели главной за выбранный период и период сравнения — одним
 * проходом по каждой таблице фактов (docs/plan/dashboard-widgets.md).
 *
 * Два источника и две даты, и это не непоследовательность, а два разных
 * вопроса. Заказы — `sales_fact` по дате заказа (ADR-009), все статусы,
 * включая отмены: так считает «Заказано» кабинет и экран выкупа.
 * Продажи, возвраты, реклама и логистика — лента начислений `by-day`
 * по дате начисления (ADR-036): это деньги, и сверяют их с отчётом
 * «Начисления».
 *
 * Периоды не пересекаются — сравнение кончается раньше, чем начинается
 * выбранный, это проверяет граница HTTP. Поэтому принадлежность строки
 * периоду определяется одним сравнением с началом выбранного.
 *
 * Расходы в ленте отрицательные; наружу суммы рекламы, возвратов
 * и логистики уходят модулем — сколько потрачено. Сторно площадки
 * (строка того же типа с обратным знаком) уменьшает сумму: итог
 * нетто, как в кабинете. Количество логистики — отправления, по которым
 * в периоде было списание.
 *
 * Отношения считает PostgreSQL над numeric: ДРР — в базисных пунктах,
 * цена заказа и продажи — в минорных единицах. `ROUND(numeric)` —
 * половина от нуля, как у удельной логистики (`LocalizationSql`).
 * Ноль в знаменателе — NULL, а не ноль: «рекламы на заказ не было»
 * и «заказов не было» — разные ответы.
 *
 * Индексы — `(company_id, business_date)` обеих таблиц; условие по двум
 * диапазонам планировщик разворачивает в BitmapOr по одному индексу.
 */
final readonly class DashboardMetricsQuery
{
    /**
     * Строк итогов — два периода на валюту: при одной валюте две.
     * Подневных — дни обоих периодов (по 366 максимум) на валюту.
     * Потолки защитные (CLAUDE.md §5): упор в них — исключение
     * в сценарии, а не молча обрезанный ряд.
     */
    public const int MAX_TOTAL_ROWS = 20;

    public const int MAX_DAILY_ROWS = 4000;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function totals(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $compareFrom,
        \DateTimeImmutable $compareTo,
    ): QueryBuilder {
        $orders = <<<'SQL'
            SELECT CASE WHEN business_date >= :from THEN 'current' ELSE 'comparison' END AS period,
                   currency,
                   SUM(quantity) AS orders_quantity,
                   SUM(amount_minor) AS orders_amount_minor
            FROM sales_fact
            WHERE company_id = :companyId
              AND ((business_date >= :from AND business_date <= :to)
                OR (business_date >= :compareFrom AND business_date <= :compareTo))
            GROUP BY period, currency
            SQL;

        $accruals = \sprintf(<<<'SQL'
            SELECT CASE WHEN business_date >= :from THEN 'current' ELSE 'comparison' END AS period,
                   currency,
                   %s
            FROM marketplace_expense_fact
            WHERE company_id = :companyId
              AND ((business_date >= :from AND business_date <= :to)
                OR (business_date >= :compareFrom AND business_date <= :compareTo))
            GROUP BY period, currency
            SQL, self::accrualColumns(withCounts: true));

        $joined = <<<SQL
            (
                SELECT period,
                       currency,
                       COALESCE(o.orders_quantity, 0) AS orders_quantity,
                       COALESCE(o.orders_amount_minor, 0) AS orders_amount_minor,
                       COALESCE(a.sales_quantity, 0) AS sales_quantity,
                       COALESCE(a.sales_amount_minor, 0) AS sales_amount_minor,
                       COALESCE(a.returns_quantity, 0) AS returns_quantity,
                       COALESCE(a.returns_amount_minor, 0) AS returns_amount_minor,
                       COALESCE(a.advertising_minor, 0) AS advertising_minor,
                       COALESCE(a.delivery_logistics_minor, 0) AS delivery_logistics_minor,
                       COALESCE(a.delivery_count, 0) AS delivery_count,
                       COALESCE(a.return_logistics_minor, 0) AS return_logistics_minor,
                       COALESCE(a.return_logistics_count, 0) AS return_logistics_count
                FROM ({$orders}) AS o
                FULL OUTER JOIN ({$accruals}) AS a USING (period, currency)
            ) AS t
            SQL;

        return $this->withParameters($this->connection->createQueryBuilder(), $companyId, $from, $to, $compareFrom, $compareTo)
            ->select(
                't.*',
                'ROUND(t.advertising_minor::numeric / NULLIF(t.orders_quantity, 0))::bigint AS cost_per_order_minor',
                'ROUND(10000::numeric * t.advertising_minor / NULLIF(t.orders_amount_minor, 0))::bigint AS drr_orders_bps',
                'ROUND(t.advertising_minor::numeric / NULLIF(t.sales_quantity, 0))::bigint AS cost_per_sale_minor',
                'ROUND(10000::numeric * t.advertising_minor / NULLIF(t.sales_amount_minor, 0))::bigint AS drr_sales_bps',
            )
            ->from($joined)
            ->orderBy('t.period')
            ->addOrderBy('t.currency')
            ->setMaxResults(self::MAX_TOTAL_ROWS);
    }

    /**
     * Подневные суммы обоих периодов — ряды спарклайнов. Дни без
     * единого факта здесь не появляются; нулями их заполняет сценарий.
     */
    public function daily(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $compareFrom,
        \DateTimeImmutable $compareTo,
    ): QueryBuilder {
        $orders = <<<'SQL'
            SELECT business_date, currency, SUM(amount_minor) AS orders_amount_minor
            FROM sales_fact
            WHERE company_id = :companyId
              AND ((business_date >= :from AND business_date <= :to)
                OR (business_date >= :compareFrom AND business_date <= :compareTo))
            GROUP BY business_date, currency
            SQL;

        $accruals = \sprintf(<<<'SQL'
            SELECT business_date, currency, %s
            FROM marketplace_expense_fact
            WHERE company_id = :companyId
              AND ((business_date >= :from AND business_date <= :to)
                OR (business_date >= :compareFrom AND business_date <= :compareTo))
            GROUP BY business_date, currency
            SQL, self::accrualColumns(withCounts: false));

        $joined = <<<SQL
            (
                SELECT business_date,
                       currency,
                       COALESCE(o.orders_amount_minor, 0) AS orders_amount_minor,
                       COALESCE(a.sales_amount_minor, 0) AS sales_amount_minor,
                       COALESCE(a.returns_amount_minor, 0) AS returns_amount_minor,
                       COALESCE(a.advertising_minor, 0) AS advertising_minor,
                       COALESCE(a.delivery_logistics_minor, 0) AS delivery_logistics_minor,
                       COALESCE(a.return_logistics_minor, 0) AS return_logistics_minor
                FROM ({$orders}) AS o
                FULL OUTER JOIN ({$accruals}) AS a USING (business_date, currency)
            ) AS t
            SQL;

        return $this->withParameters($this->connection->createQueryBuilder(), $companyId, $from, $to, $compareFrom, $compareTo)
            ->select('t.*')
            ->from($joined)
            ->orderBy('t.business_date')
            ->addOrderBy('t.currency')
            ->setMaxResults(self::MAX_DAILY_ROWS);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapTotalsRow(array $row): DashboardTotalsRow
    {
        $period = $row['period'] ?? null;
        if ('current' !== $period && 'comparison' !== $period) {
            throw new \UnexpectedValueException('Expected current or comparison period in a dashboard totals row.');
        }

        return new DashboardTotalsRow(
            current: 'current' === $period,
            currency: self::string($row['currency'] ?? null),
            ordersQuantity: self::int($row['orders_quantity'] ?? null),
            ordersAmountMinor: self::int($row['orders_amount_minor'] ?? null),
            salesQuantity: self::int($row['sales_quantity'] ?? null),
            salesAmountMinor: self::int($row['sales_amount_minor'] ?? null),
            returnsQuantity: self::int($row['returns_quantity'] ?? null),
            returnsAmountMinor: self::int($row['returns_amount_minor'] ?? null),
            advertisingMinor: self::int($row['advertising_minor'] ?? null),
            costPerOrderMinor: self::nullableInt($row['cost_per_order_minor'] ?? null),
            drrOrdersBps: self::nullableInt($row['drr_orders_bps'] ?? null),
            costPerSaleMinor: self::nullableInt($row['cost_per_sale_minor'] ?? null),
            drrSalesBps: self::nullableInt($row['drr_sales_bps'] ?? null),
            deliveryLogisticsMinor: self::int($row['delivery_logistics_minor'] ?? null),
            deliveryCount: self::int($row['delivery_count'] ?? null),
            returnLogisticsMinor: self::int($row['return_logistics_minor'] ?? null),
            returnLogisticsCount: self::int($row['return_logistics_count'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapDailyRow(array $row): DashboardDailyRow
    {
        return new DashboardDailyRow(
            date: self::string($row['business_date'] ?? null),
            currency: self::string($row['currency'] ?? null),
            ordersAmountMinor: self::int($row['orders_amount_minor'] ?? null),
            salesAmountMinor: self::int($row['sales_amount_minor'] ?? null),
            returnsAmountMinor: self::int($row['returns_amount_minor'] ?? null),
            advertisingMinor: self::int($row['advertising_minor'] ?? null),
            deliveryLogisticsMinor: self::int($row['delivery_logistics_minor'] ?? null),
            returnLogisticsMinor: self::int($row['return_logistics_minor'] ?? null),
        );
    }

    /**
     * Колонки ленты начислений, общие для итогов и подневного ряда.
     * Выручка делится по знаку: `+` — продажа, `−` — возврат, штука —
     * строка (ADR-036). Расходы — модулем.
     */
    private static function accrualColumns(bool $withCounts): string
    {
        $columns = [
            'COALESCE(SUM(amount_minor) FILTER (WHERE fee_type_id = :revenueType AND amount_minor > 0), 0) AS sales_amount_minor',
            '-COALESCE(SUM(amount_minor) FILTER (WHERE fee_type_id = :revenueType AND amount_minor < 0), 0) AS returns_amount_minor',
            '-COALESCE(SUM(amount_minor) FILTER (WHERE fee_type_id IN (:advertisingTypes)), 0) AS advertising_minor',
            '-COALESCE(SUM(amount_minor) FILTER (WHERE fee_type_id IN (:deliveryTypes)), 0) AS delivery_logistics_minor',
            '-COALESCE(SUM(amount_minor) FILTER (WHERE fee_type_id IN (:returnLogisticsTypes)), 0) AS return_logistics_minor',
        ];

        if ($withCounts) {
            // Отправление — пара «подключение, номер»: номер уникален
            // в пределах кабинета, а у компании их бывает несколько.
            // Строка без номера (начисление на кабинет) в сумму входит,
            // а отправлением не считается.
            $columns[] = 'COUNT(*) FILTER (WHERE fee_type_id = :revenueType AND amount_minor > 0) AS sales_quantity';
            $columns[] = 'COUNT(*) FILTER (WHERE fee_type_id = :revenueType AND amount_minor < 0) AS returns_quantity';
            $columns[] = "COUNT(DISTINCT marketplace_account_id::text || '|' || unit_number)"
                ." FILTER (WHERE fee_type_id IN (:deliveryTypes) AND amount_minor < 0 AND unit_number <> '') AS delivery_count";
            $columns[] = "COUNT(DISTINCT marketplace_account_id::text || '|' || unit_number)"
                ." FILTER (WHERE fee_type_id IN (:returnLogisticsTypes) AND amount_minor < 0 AND unit_number <> '') AS return_logistics_count";
        }

        return implode(",\n", $columns);
    }

    private function withParameters(
        QueryBuilder $builder,
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $compareFrom,
        \DateTimeImmutable $compareTo,
    ): QueryBuilder {
        // Принадлежность строки периоду — одно сравнение с началом
        // выбранного; пересекающиеся периоды разнесли бы общие дни
        // в выбранный молча.
        if ($compareTo >= $from || $from > $to || $compareFrom > $compareTo) {
            throw new \InvalidArgumentException('Comparison period must end before the selected period starts.');
        }

        return $builder
            ->setParameter('companyId', $companyId)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->setParameter('compareFrom', $compareFrom->format('Y-m-d'))
            ->setParameter('compareTo', $compareTo->format('Y-m-d'))
            ->setParameter('revenueType', OzonFeeTypeNames::REVENUE)
            ->setParameter('advertisingTypes', OzonDashboardFeeTypes::ADVERTISING, ArrayParameterType::INTEGER)
            ->setParameter('deliveryTypes', OzonDashboardFeeTypes::DELIVERY_LOGISTICS, ArrayParameterType::INTEGER)
            ->setParameter('returnLogisticsTypes', OzonDashboardFeeTypes::RETURN_LOGISTICS, ArrayParameterType::INTEGER);
    }

    private static function string(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string value in a dashboard row.');
        }

        return $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return null === $value ? null : self::int($value);
    }

    private static function int(mixed $value): int
    {
        // SUM в PostgreSQL возвращает numeric, и DBAL отдаёт его строкой.
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && 1 === preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException('Expected an integer value in a dashboard row.');
    }
}
