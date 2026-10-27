<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\SalesCohort;

use App\Shared\Domain\ValueObject\Money;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Заказы без подтверждённых отмен до передачи в доставку.
 * Использует количественные аллокации ADR-019/020, не повторяет классификатор.
 */
final readonly class SalesCohortMetricsQuery
{
    public const int MAX_SKUS = 50;
    public const int MAX_DAYS = 184;

    public function __construct(private Connection $connection)
    {
    }

    /** @param list<string> $marketplaceSkus */
    public function build(
        string $companyId,
        string $marketplaceAccountId,
        array $marketplaceSkus,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $currency = null,
    ): QueryBuilder {
        if ([] === $marketplaceSkus || \count($marketplaceSkus) > self::MAX_SKUS) {
            throw new \InvalidArgumentException('A cohort batch must contain between 1 and 50 SKUs.');
        }
        foreach ($marketplaceSkus as $sku) {
            if ('' === trim($sku) || \strlen($sku) > 64) {
                throw new \InvalidArgumentException('A marketplace SKU must contain between 1 and 64 characters.');
            }
        }

        // Аргументы — календарные даты, а не моменты времени: часы и DST не
        // должны менять включительное число бизнес-дней диапазона.
        $utc = new \DateTimeZone('UTC');
        $fromDay = new \DateTimeImmutable($from->format('Y-m-d'), $utc);
        $toDay = new \DateTimeImmutable($to->format('Y-m-d'), $utc);
        if ($fromDay > $toDay || $fromDay->diff($toDay)->days >= self::MAX_DAYS) {
            throw new \InvalidArgumentException('A cohort range must contain between 1 and 184 days.');
        }

        if (null !== $currency) {
            try {
                $currency = Money::ofMinor(0, $currency)->currency();
            } catch (\Exception $invalid) {
                throw new \InvalidArgumentException('Unknown valuation currency.', previous: $invalid);
            }
        }
        $money = null === $currency ? 'NULL::bigint AS observed_revenue_minor' : <<<'SQL'
            CASE WHEN COALESCE(BOOL_AND(
                          sf.currency = CAST(:currency AS text) AND sf.amount_minor IS NOT NULL
                      ) FILTER (WHERE o.outcome = 'D'), TRUE)
                 THEN COALESCE(SUM(o.quantity::numeric * sf.amount_minor) FILTER (WHERE o.outcome = 'D'), 0)::bigint
                 ELSE NULL END AS observed_revenue_minor
            SQL;
        $daily = $this->connection->createQueryBuilder()
            ->select(
                'o.marketplace_sku',
                'o.business_date',
                'SUM(o.quantity)::bigint AS raw_ordered_quantity',
                "COALESCE(SUM(o.quantity) FILTER (WHERE o.outcome = 'T1'), 0)::bigint AS pre_handover_cancelled_quantity",
                "COALESCE(SUM(o.quantity) FILTER (WHERE o.outcome IS DISTINCT FROM 'T1'), 0)::bigint AS effective_ordered_quantity",
                "COALESCE(SUM(o.quantity) FILTER (WHERE o.outcome = 'D'), 0)::bigint AS observed_net_sales_quantity",
                'CAST(:currency AS text) AS valuation_currency',
                $money,
            )
            ->from('buyout_outcome', 'o')
            ->where('o.company_id = :companyId')
            ->andWhere('o.marketplace_account_id = :accountId')
            ->andWhere('o.marketplace_sku IN (:skus)')
            ->andWhere('o.business_date >= :from')
            ->andWhere('o.business_date <= :to')
            ->groupBy('o.marketplace_sku', 'o.business_date');
        if (null !== $currency) {
            $daily->leftJoin('o', 'sales_fact', 'sf',
                'sf.company_id = o.company_id AND sf.marketplace_account_id = o.marketplace_account_id
                 AND sf.source_row_id = o.source_row_id AND sf.marketplace_sku = o.marketplace_sku
                 AND sf.business_date = o.business_date',
            );
        }

        // Экранная строка — SKU, дни — её ограниченные колонки. Ни список
        // SKU (≤50), ни вложенный ряд дней (≤184) не превышает лимит 200.
        return $this->connection->createQueryBuilder()
            ->select(
                'marketplace_sku',
                <<<'SQL'
                    jsonb_agg(jsonb_build_object(
                        'cohort_date', to_char(business_date, 'YYYY-MM-DD'),
                        'raw_ordered_quantity', raw_ordered_quantity,
                        'pre_handover_cancelled_quantity', pre_handover_cancelled_quantity,
                        'effective_ordered_quantity', effective_ordered_quantity,
                        'observed_net_sales_quantity', observed_net_sales_quantity,
                        'observed_revenue_minor', observed_revenue_minor,
                        'valuation_currency', valuation_currency
                    ) ORDER BY business_date)::text AS days
                    SQL,
            )
            ->from('('.$daily->getSQL().')', 'daily')
            ->setParameter('companyId', $companyId)
            ->setParameter('accountId', $marketplaceAccountId)
            ->setParameter('skus', $marketplaceSkus, ArrayParameterType::STRING)
            ->setParameter('from', $fromDay->format('Y-m-d'))
            ->setParameter('to', $toDay->format('Y-m-d'))
            ->setParameter('currency', $currency)
            ->groupBy('marketplace_sku')
            ->orderBy('marketplace_sku', 'ASC')
            ->setMaxResults(self::MAX_SKUS);
    }

    /** @param array<string, mixed> $row */
    public static function mapRow(array $row): SalesCohortSkuMetricsRow
    {
        $sku = $row['marketplace_sku'] ?? null;
        $json = $row['days'] ?? null;
        if (!\is_string($sku) || !\is_string($json)) {
            throw new \UnexpectedValueException('Invalid sales cohort SKU row.');
        }
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($data) || !array_is_list($data) || \count($data) > self::MAX_DAYS) {
            throw new \UnexpectedValueException('Invalid sales cohort day series.');
        }
        $days = [];
        foreach ($data as $day) {
            if (!\is_array($day)) {
                throw new \UnexpectedValueException('Invalid sales cohort day.');
            }
            $days[] = self::mapDay($sku, $day);
        }

        return new SalesCohortSkuMetricsRow($sku, $days);
    }

    /** @param array<array-key, mixed> $row */
    private static function mapDay(string $sku, array $row): SalesCohortMetricsRow
    {
        $date = $row['cohort_date'] ?? null;
        $raw = $row['raw_ordered_quantity'] ?? null;
        $cancelled = $row['pre_handover_cancelled_quantity'] ?? null;
        $effective = $row['effective_ordered_quantity'] ?? null;
        $observed = $row['observed_net_sales_quantity'] ?? null;
        if (!\is_string($date) || !\is_int($raw) || !\is_int($cancelled) || !\is_int($effective) || !\is_int($observed)) {
            throw new \UnexpectedValueException('Invalid sales cohort metrics row.');
        }

        $minor = $row['observed_revenue_minor'] ?? null;
        $currency = $row['valuation_currency'] ?? null;
        $revenue = null;
        if (null !== $minor) {
            if (!\is_int($minor) || !\is_string($currency)) {
                throw new \UnexpectedValueException('Invalid sales cohort monetary value.');
            }
            $revenue = Money::ofMinor($minor, $currency);
        }

        return new SalesCohortMetricsRow($sku, $date, $raw, $cancelled, $effective, $observed, $revenue);
    }
}
