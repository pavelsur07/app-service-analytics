<?php

declare(strict_types=1);

namespace App\Planning\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class DailyPlansQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function build(string $companyId, string $marketplaceAccountId, string $marketplaceSku, \DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('days.day::date::text AS business_date', 'plan.quantity', 'COALESCE(plan.version, 0) AS version')
            ->from("(SELECT generate_series(CAST(:from AS date), CAST(:to AS date), INTERVAL '1 day') AS day)", 'days')
            ->leftJoin('days', 'planning_daily_plan', 'plan', <<<'SQL'
                plan.company_id = :company AND plan.marketplace_account_id = :account
                AND plan.marketplace_sku = :sku AND plan.business_date = days.day::date
                SQL)
            ->orderBy('days.day', 'ASC')
            ->setMaxResults(90)
            ->setParameters([
                'company' => $companyId,
                'account' => $marketplaceAccountId,
                'sku' => $marketplaceSku,
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ]);
    }

    /** @param array{business_date: string, quantity: int|string|null, version: int|string} $row */
    public static function mapRow(array $row): DailyPlanRow
    {
        return new DailyPlanRow(
            $row['business_date'],
            null === $row['quantity'] ? null : (int) $row['quantity'],
            (int) $row['version'],
        );
    }
}
