<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\UnitEconomics;

use App\Ingestion\Domain\OzonFeeTypeNames;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Начисления компании за период, свёрнутые по типу: вся лента `by-day`
 * (ADR-036) по дате начисления, без разбивки по товарам — как отчёт
 * «Начисления» кабинета.
 *
 * Выручка (тип 0) сворачивается отдельно по знаку: продажи и возвраты —
 * разные группы кабинета, и сумма одной строкой их бы смешала.
 *
 * У остальных типов строка одна, но с суммами по знаку рядом с итогом:
 * площадка возвращает затрату строкой того же типа с обратным знаком
 * (возврат комиссии, эквайринга, отмена логистики), и сверка показывает
 * «начислено» и «возвращено» раздельно, как кабинет.
 *
 * Считает PostgreSQL (CLAUDE.md §5); наружу — строка на тип, знак
 * выручки и валюту. Типов у площадки 119, и только выручка даёт две
 * строки — при одной валюте их не больше 121. Потолок — максимум
 * списка по §5.
 */
final readonly class AccrualCategoriesQuery
{
    private const int MAX_ROWS = 200;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function byType(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'fee_type_id',
                '(fee_type_id = :revenueType AND amount_minor < 0) AS negative_revenue',
                'currency',
                'SUM(amount_minor) AS amount_minor',
                'COALESCE(SUM(amount_minor) FILTER (WHERE amount_minor > 0), 0) AS positive_minor',
                'COALESCE(SUM(amount_minor) FILTER (WHERE amount_minor < 0), 0) AS negative_minor',
            )
            ->from('marketplace_expense_fact')
            ->where('company_id = :companyId')
            ->andWhere('business_date >= :from')
            ->andWhere('business_date <= :to')
            ->setParameter('companyId', $companyId)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->setParameter('revenueType', OzonFeeTypeNames::REVENUE)
            ->groupBy('fee_type_id')
            ->addGroupBy('negative_revenue')
            ->addGroupBy('currency')
            ->orderBy('fee_type_id')
            ->addOrderBy('negative_revenue')
            ->setMaxResults(self::MAX_ROWS);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): AccrualCategoryRow
    {
        $negative = $row['negative_revenue'];
        if (!\is_bool($negative)) {
            throw new \UnexpectedValueException('Expected a boolean negative_revenue in an accrual categories row.');
        }

        $currency = $row['currency'];
        if (!\is_string($currency)) {
            throw new \UnexpectedValueException('Expected a string currency in an accrual categories row.');
        }

        return new AccrualCategoryRow(
            feeTypeId: self::intValue($row['fee_type_id']),
            negativeRevenue: $negative,
            currency: $currency,
            amountMinor: self::intValue($row['amount_minor']),
            positiveMinor: self::intValue($row['positive_minor']),
            negativeMinor: self::intValue($row['negative_minor']),
        );
    }

    private static function intValue(mixed $value): int
    {
        // SUM в PostgreSQL возвращает numeric, и DBAL отдаёт его строкой.
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && 1 === preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException('Expected an integer value in an accrual categories row.');
    }
}
