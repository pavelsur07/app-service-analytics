<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Юнит-экономика за период: по товару — выручка, комиссия и расходы
 * площадки; отдельно — расходы кабинета, к товару не привязанные.
 *
 * Считает PostgreSQL, не PHP (CLAUDE.md §5): наружу уходят агрегаты,
 * а не выборка фактов.
 *
 * Продажи и расходы складываются двумя отдельными запросами, а не одним
 * с JOIN: у них разные бизнес-даты по своей природе — расход начисляется
 * позже продажи, иногда на недели (ADR-012). Соединять их по дате
 * значило бы приписывать июльской продаже только те расходы, которые
 * успели начислиться в июле, и получать разную картину при каждом
 * повторном открытии экрана.
 *
 * Группировка по валюте — требование §3: суммы разных валют
 * не складываются. Сегодня строка будет одна (Ozon, RUB).
 */
final readonly class UnitEconomicsQuery
{
    /**
     * Потолок строк выдачи. Артикулов у продавца сотни, и экран за период
     * обязан иметь предел (§5); превышение — сигнал вызывающему коду,
     * а не тихая обрезка.
     */
    public const int MAX_ROWS = 200;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Продажи по артикулам за период: доставленное и заказанное отдельно
     * (ADR-009 требует от витрины явного счёта по статусу).
     */
    public function sales(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'marketplace_sku',
                'currency',
                "COALESCE(SUM(quantity) FILTER (WHERE status = 'delivered'), 0) AS delivered_quantity",
                "COALESCE(SUM(amount_minor) FILTER (WHERE status = 'delivered'), 0) AS delivered_amount_minor",
                "COALESCE(SUM(commission_amount_minor) FILTER (WHERE status = 'delivered'), 0) AS commission_amount_minor",
                "COALESCE(SUM(quantity) FILTER (WHERE status <> 'cancelled'), 0) AS ordered_quantity",
            )
            ->from('sales_fact')
            ->where('company_id = :companyId')
            ->andWhere('business_date >= :from')
            ->andWhere('business_date <= :to')
            ->setParameter('companyId', $companyId)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->groupBy('marketplace_sku')
            ->addGroupBy('currency')
            ->orderBy('delivered_amount_minor', 'DESC')
            ->setMaxResults(self::MAX_ROWS + 1);
    }

    /**
     * Расходы за период по артикулу и типу. Пустой артикул — расход
     * кабинета: реклама, хранение, досрочная выплата. Он не размазывается
     * по товарам: базис распределения — продуктовое решение, которое
     * захочется менять, а показанная и объяснимая строка «расходы
     * кабинета» честнее, чем доля, происхождение которой клиент
     * не проверит (ADR-012).
     */
    public function expenses(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'marketplace_sku',
                'fee_type_id',
                'currency',
                'SUM(amount_minor) AS amount_minor',
            )
            ->from('marketplace_expense_fact')
            ->where('company_id = :companyId')
            ->andWhere('business_date >= :from')
            ->andWhere('business_date <= :to')
            ->setParameter('companyId', $companyId)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->groupBy('marketplace_sku')
            ->addGroupBy('fee_type_id')
            ->addGroupBy('currency')
            // Типов начислений у кабинета порядка десятка, артикулов —
            // сотни: потолок общий на выдачу, как у продаж.
            ->setMaxResults(self::MAX_ROWS * 20);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapSalesRow(array $row): UnitEconomicsSalesRow
    {
        return new UnitEconomicsSalesRow(
            marketplaceSku: self::stringValue($row['marketplace_sku']),
            currency: self::stringValue($row['currency']),
            deliveredQuantity: self::intValue($row['delivered_quantity']),
            deliveredAmountMinor: self::intValue($row['delivered_amount_minor']),
            commissionAmountMinor: self::intValue($row['commission_amount_minor']),
            orderedQuantity: self::intValue($row['ordered_quantity']),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapExpenseRow(array $row): UnitEconomicsExpenseRow
    {
        return new UnitEconomicsExpenseRow(
            marketplaceSku: self::stringValue($row['marketplace_sku']),
            feeTypeId: self::intValue($row['fee_type_id']),
            currency: self::stringValue($row['currency']),
            amountMinor: self::intValue($row['amount_minor']),
        );
    }

    private static function stringValue(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string value in a unit economics row.');
        }

        return $value;
    }

    private static function intValue(mixed $value): int
    {
        // SUM в PostgreSQL возвращает numeric, и DBAL отдаёт его строкой:
        // приводим явно, а не полагаемся на то, что драйвер угадает.
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && 1 === preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException('Expected an integer value in a unit economics row.');
    }
}
