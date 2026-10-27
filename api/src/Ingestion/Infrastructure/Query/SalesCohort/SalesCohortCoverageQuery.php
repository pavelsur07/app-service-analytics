<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\SalesCohort;

use App\Ingestion\Domain\MarketplaceReportType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/** Дневное покрытие кабинета независимо от наличия SKU или фактов. */
final readonly class SalesCohortCoverageQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function build(
        string $companyId,
        string $marketplaceAccountId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): QueryBuilder {
        $utc = new \DateTimeZone('UTC');
        $fromDay = new \DateTimeImmutable($from->format('Y-m-d'), $utc);
        $toDay = new \DateTimeImmutable($to->format('Y-m-d'), $utc);
        if ($fromDay > $toDay || $fromDay->diff($toDay)->days >= SalesCohortMetricsQuery::MAX_DAYS) {
            throw new \InvalidArgumentException('A cohort range must contain between 1 and 184 days.');
        }

        return $this->connection->createQueryBuilder()
            ->select(
                "to_char(day.period, 'YYYY-MM-DD') AS cohort_date",
                <<<'SQL'
                    CASE
                        WHEN l.failed_at IS NOT NULL THEN 'partial'
                        WHEN l.completed_at IS NOT NULL THEN 'complete'
                        WHEN EXISTS (
                            SELECT 1 FROM marketplace_raw_document d
                            WHERE d.company_id = :companyId
                              AND d.marketplace_account_id = :accountId
                              AND d.report_type = :reportType
                              AND d.period = day.period::date
                        ) THEN 'partial'
                        ELSE 'missing'
                    END AS orders_coverage
                    SQL,
            )
            // timestamp without time zone makes this a calendar-day series,
            // independent of the connection's time zone and DST transitions.
            ->from("(SELECT generate_series(CAST(:from AS timestamp), CAST(:to AS timestamp), INTERVAL '1 day') AS period)", 'day')
            ->leftJoin('day', 'marketplace_day_load', 'l',
                'l.company_id = :companyId AND l.marketplace_account_id = :accountId
                 AND l.report_type = :reportType AND l.period = day.period::date',
            )
            ->setParameter('companyId', $companyId)
            ->setParameter('accountId', $marketplaceAccountId)
            ->setParameter('reportType', MarketplaceReportType::OzonPostingFboList)
            ->setParameter('from', $fromDay->format('Y-m-d'))
            ->setParameter('to', $toDay->format('Y-m-d'))
            ->orderBy('day.period')
            ->setMaxResults(SalesCohortMetricsQuery::MAX_DAYS);
    }

    /** @param array<string, mixed> $row */
    public static function mapRow(array $row): SalesCohortCoverageRow
    {
        $date = $row['cohort_date'] ?? null;
        $coverage = $row['orders_coverage'] ?? null;
        if (!\is_string($date) || !\in_array($coverage, ['complete', 'missing', 'partial'], true)) {
            throw new \UnexpectedValueException('Invalid sales cohort coverage row.');
        }

        return new SalesCohortCoverageRow($date, $coverage);
    }
}
