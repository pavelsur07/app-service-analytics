<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\UnitEconomics;

use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\OzonFeeTypeNames;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Сверка рекламы по SKU со списанием `by-day` (ADR-035 п. 5): по паре
 * «подключение × кампания × день» — итог «Оплаты за клик»
 * (`unit_number` = кампания), итог рекламы по SKU и число строк SKU.
 *
 * Только суммы: разницу и допуск («копейка на строку SKU») считает
 * Money в сценарии, а не база.
 *
 * Сверяются только подключения, у которых загружался расход рекламы
 * (`ozon_ad_expense` в raw): у кабинета без рекламного ключа разбивки
 * нет по построению, и каждый его день выглядел бы несошедшимся.
 *
 * Строк — подключения × кампании × дни окна: сотни. Потолок защитный,
 * и упор в него — исключение в сценарии, а не молча обрезанная сверка.
 */
final readonly class AdvertisingReconciliationQuery
{
    public const int MAX_ROWS = 20_000;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function pairs(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        $pairs = <<<'SQL'
            (
                SELECT marketplace_account_id, campaign_id, business_date, currency,
                       SUM(by_day_minor) AS by_day_minor,
                       SUM(sku_minor) AS sku_minor,
                       SUM(sku_rows) AS sku_rows
                FROM (
                    SELECT marketplace_account_id, unit_number AS campaign_id, business_date, currency,
                           amount_minor AS by_day_minor, 0 AS sku_minor, 0 AS sku_rows
                    FROM marketplace_expense_fact
                    WHERE company_id = :companyId AND business_date >= :from AND business_date <= :to
                      AND marketplace_sku = '' AND fee_type_id = :payPerClick
                    UNION ALL
                    SELECT marketplace_account_id, campaign_id, business_date, currency,
                           0, amount_minor, 1
                    FROM ad_sku_expense_fact
                    WHERE company_id = :companyId AND business_date >= :from AND business_date <= :to
                ) AS sides
                WHERE marketplace_account_id IN (
                    SELECT DISTINCT marketplace_account_id
                    FROM marketplace_raw_document
                    WHERE company_id = :companyId AND report_type = :adExpense
                )
                GROUP BY marketplace_account_id, campaign_id, business_date, currency
            ) AS pairs
            SQL;

        return $this->connection->createQueryBuilder()
            ->select('business_date', 'currency', 'by_day_minor', 'sku_minor', 'sku_rows')
            ->from($pairs)
            ->setParameter('companyId', $companyId)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->setParameter('payPerClick', OzonFeeTypeNames::PAY_PER_CLICK)
            ->setParameter('adExpense', MarketplaceReportType::OzonAdExpense)
            ->orderBy('business_date')
            ->setMaxResults(self::MAX_ROWS);
    }
}
