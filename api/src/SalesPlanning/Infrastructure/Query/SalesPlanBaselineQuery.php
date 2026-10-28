<?php

declare(strict_types=1);

namespace App\SalesPlanning\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/** База сравнения не следует за текущим approved и не зависит от пагинации. */
final readonly class SalesPlanBaselineQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function build(string $companyId, string $planId): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('b.id', 'b.lineage_id', 'b.marketplace_account_id', 'b.version_number', 'b.metric', 'b.currency', 'b.date_from', 'b.date_to')
            ->from('sales_plan', 'p')
            ->join('p', 'sales_plan', 'b',
                'b.company_id = :companyId AND b.company_id = p.company_id
                 AND b.lineage_id = p.lineage_id AND b.marketplace_account_id = p.marketplace_account_id',
            )
            ->where('p.company_id = :companyId')
            ->andWhere('p.id = :planId')
            // Nullable approval columns of legacy versions are not evidence
            // that a superseded/cancelled version was never approved.
            ->andWhere("b.status IN ('approved', 'superseded', 'cancelled')")
            ->setParameter('companyId', $companyId)
            ->setParameter('planId', $planId)
            ->orderBy('b.version_number')
            ->setMaxResults(1);
    }

    /** @param array<string, mixed> $row */
    public static function mapRow(array $row): SalesPlanBaselineRow
    {
        $version = $row['version_number'] ?? null;
        $metric = $row['metric'] ?? null;
        if (!\is_int($version) || (null !== $metric && !\is_string($metric))) {
            throw new \UnexpectedValueException('Invalid sales plan baseline metadata.');
        }

        return new SalesPlanBaselineRow(
            self::string($row, 'id'), self::string($row, 'lineage_id'), self::string($row, 'marketplace_account_id'),
            $version, $metric, self::string($row, 'currency'), self::string($row, 'date_from'), self::string($row, 'date_to'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Invalid sales plan baseline '.$key.'.');
        }

        return $value;
    }
}
