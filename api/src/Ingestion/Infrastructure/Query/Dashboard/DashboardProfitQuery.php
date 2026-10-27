<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Dashboard;

use App\Ingestion\Domain\OzonFeeTypeNames;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/** Прибыль по полной ленте начислений и исторической закупке (ADR-013/036/038). */
final readonly class DashboardProfitQuery
{
    public const int MAX_TOTAL_ROWS = 20;
    public const int MAX_DAILY_ROWS = 4000;

    public function __construct(private Connection $connection)
    {
    }

    public function totals(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $compareFrom, \DateTimeImmutable $compareTo): QueryBuilder
    {
        return $this->aggregate($companyId, $from, $to, $compareFrom, $compareTo, false);
    }

    public function daily(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $compareFrom, \DateTimeImmutable $compareTo): QueryBuilder
    {
        return $this->aggregate($companyId, $from, $to, $compareFrom, $compareTo, true);
    }

    /** @param array<string, mixed> $row */
    public static function mapTotalsRow(array $row): DashboardProfitTotalsRow
    {
        $period = self::string($row['period'] ?? null);
        if ('current' !== $period && 'comparison' !== $period) {
            throw new \UnexpectedValueException('Expected a dashboard profit period.');
        }

        return new DashboardProfitTotalsRow(
            current: 'current' === $period,
            currency: self::string($row['currency'] ?? null),
            accrualsTotalMinor: self::integer($row['accruals_total_minor'] ?? null),
            costTotalMinor: self::integer($row['cost_total_minor'] ?? null),
            quantityWithoutCost: self::integer($row['quantity_without_cost'] ?? null),
            costCurrencyMismatchQuantity: self::integer($row['cost_currency_mismatch_quantity'] ?? null),
            costCorrectedAt: self::correctedAt($row['cost_corrected_at'] ?? null),
        );
    }

    /** @param array<string, mixed> $row */
    public static function mapDailyRow(array $row): DashboardProfitDailyRow
    {
        return new DashboardProfitDailyRow(
            date: self::string($row['business_date'] ?? null),
            currency: self::string($row['currency'] ?? null),
            accrualsTotalMinor: self::integer($row['accruals_total_minor'] ?? null),
            costTotalMinor: self::integer($row['cost_total_minor'] ?? null),
            quantityWithoutCost: self::integer($row['quantity_without_cost'] ?? null),
            costCurrencyMismatchQuantity: self::integer($row['cost_currency_mismatch_quantity'] ?? null),
            costCorrectedAt: self::correctedAt($row['cost_corrected_at'] ?? null),
        );
    }

    private function aggregate(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $compareFrom, \DateTimeImmutable $compareTo, bool $daily): QueryBuilder
    {
        if ($from > $to || $compareFrom > $compareTo || $compareTo >= $from) {
            throw new \InvalidArgumentException('Comparison period must end before the selected period starts.');
        }

        // Возврат ищет исходную продажу во всей истории подключения, а не
        // только в окнах отчёта. Правило самой ранней даты — ADR-036 п. 6.
        // LEFT JOIN сохраняет все общие расходы и не размножает факты.
        $source = <<<'SQL'
            marketplace_expense_fact AS f
            LEFT JOIN LATERAL (
                SELECT MIN(s.business_date) AS sale_date
                FROM marketplace_expense_fact AS s
                WHERE f.fee_type_id = :revenueType AND f.amount_minor < 0 AND f.unit_number <> ''
                  AND s.company_id = f.company_id
                  AND s.marketplace_account_id = f.marketplace_account_id
                  AND s.marketplace_sku = f.marketplace_sku AND s.unit_number = f.unit_number
                  AND s.fee_type_id = :revenueType AND s.amount_minor > 0
            ) AS o ON TRUE
            LEFT JOIN LATERAL (
                SELECT lc.unit_cost_minor, lc.currency, lc.recorded_at, lc.updated_at
                FROM marketplace_listing_cost AS lc
                WHERE f.fee_type_id = :revenueType
                  AND lc.company_id = f.company_id
                  AND lc.marketplace_account_id = f.marketplace_account_id
                  AND lc.marketplace_sku = f.marketplace_sku
                  AND lc.effective_from <= COALESCE(o.sale_date, f.business_date)
                ORDER BY lc.effective_from DESC
                LIMIT 1
            ) AS c ON TRUE
            SQL;
        $group = $daily ? 'f.business_date' : "CASE WHEN f.business_date >= :from THEN 'current' ELSE 'comparison' END";
        $alias = $daily ? 'business_date' : 'period';

        return $this->connection->createQueryBuilder()
            ->select(
                $group.' AS '.$alias,
                'f.currency',
                'SUM(f.amount_minor) AS accruals_total_minor',
                'COALESCE(SUM(CASE WHEN f.amount_minor > 0 THEN -c.unit_cost_minor::numeric ELSE c.unit_cost_minor::numeric END * f.quantity) FILTER (WHERE f.fee_type_id = :revenueType), 0) AS cost_total_minor',
                'COALESCE(SUM(f.quantity) FILTER (WHERE f.fee_type_id = :revenueType AND c.unit_cost_minor IS NULL), 0) AS quantity_without_cost',
                'COALESCE(SUM(f.quantity) FILTER (WHERE f.fee_type_id = :revenueType AND c.currency <> f.currency), 0) AS cost_currency_mismatch_quantity',
                'MAX(c.updated_at) FILTER (WHERE f.fee_type_id = :revenueType AND c.updated_at > c.recorded_at) AS cost_corrected_at',
            )
            ->from($source)
            ->where('f.company_id = :companyId')
            ->andWhere('((f.business_date >= :from AND f.business_date <= :to) OR (f.business_date >= :compareFrom AND f.business_date <= :compareTo))')
            ->groupBy($alias)->addGroupBy('f.currency')
            ->orderBy($alias)->addOrderBy('f.currency')
            ->setParameter('companyId', $companyId)
            ->setParameter('from', $from->format('Y-m-d'))->setParameter('to', $to->format('Y-m-d'))
            ->setParameter('compareFrom', $compareFrom->format('Y-m-d'))->setParameter('compareTo', $compareTo->format('Y-m-d'))
            ->setParameter('revenueType', OzonFeeTypeNames::REVENUE)
            ->setMaxResults($daily ? self::MAX_DAILY_ROWS : self::MAX_TOTAL_ROWS);
    }

    private static function integer(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && 1 === preg_match('/^-?\d+$/', $value)) {
            $parsed = filter_var($value, \FILTER_VALIDATE_INT);
            if (false !== $parsed) {
                return $parsed;
            }
        }

        throw new \UnexpectedValueException('Dashboard profit integer is invalid or exceeds the supported range.');
    }

    private static function string(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string in a dashboard profit row.');
        }

        return $value;
    }

    private static function correctedAt(mixed $value): ?string
    {
        return null === $value ? null : new \DateTimeImmutable(self::string($value))->format(\DateTimeInterface::RFC3339);
    }
}
