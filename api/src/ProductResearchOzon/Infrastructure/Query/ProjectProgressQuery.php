<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Infrastructure\Query;

use App\ProductResearchOzon\Domain\MonthSlice;
use App\ProductResearchOzon\Domain\ValueObject\RunMonthState;
use App\ProductResearchOzon\Domain\ValueObject\RunProvenance;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/** All frozen requirements and their latest attempt in one MVCC statement. */
final readonly class ProjectProgressQuery
{
    public function __construct(private Connection $db)
    {
    }

    public function forProject(string $companyId, string $projectId): QueryBuilder
    {
        return $this->db->createQueryBuilder()->select('p.id', 'p.version', ProjectStatusSql::expression('p').' AS project_status', 'last.id AS last_run_id', 'last.revision AS run_revision', "req->>'month' AS month", "req->>'date_from' AS date_from", "req->>'date_to' AS date_to", "(req->>'days')::int AS days", "(req->>'partial')::boolean AS partial", 'latest.status', 'latest.snapshot_id', 'latest.row_count', 'latest.error_code', 'latest.origin', 'latest.collected_at', 'latest.used_at', "(latest.date_from::text=req->>'date_from' AND latest.date_to::text=req->>'date_to' AND latest.partial=(req->>'partial')::boolean) AS matches")
            ->from('product_research_ozon_project', 'p')
            ->leftJoin('p', 'product_research_ozon_run', 'last', 'last.company_id=p.company_id AND last.project_id=p.id AND last.id=p.last_run_id')
            ->leftJoin('p', 'LATERAL jsonb_array_elements(p.requirements)', 'req', 'TRUE')
            ->leftJoin('p', "LATERAL (SELECT m.status,m.snapshot_id,m.row_count,m.error_code,m.origin,m.collected_at,m.used_at,m.date_from,m.date_to,m.partial FROM product_research_ozon_run r JOIN product_research_ozon_run_month m ON m.company_id=r.company_id AND m.run_id=r.id WHERE r.company_id=p.company_id AND r.project_id=p.id AND r.source_id=last.source_id AND m.month=req->>'month' ORDER BY r.id DESC LIMIT 1)", 'latest', 'TRUE')
            ->where('p.company_id=:company')->andWhere('p.id=:project')->setParameter('company', $companyId)->setParameter('project', $projectId)->orderBy("req->>'month'")->setMaxResults(25);
    }

    /** @param list<array<string, mixed>> $rows */
    public static function map(array $rows): ?ProjectProgressView
    {
        if ([] === $rows) {
            return null;
        }
        if (\count($rows) > 24) {
            throw new \UnexpectedValueException('invalid_project_progress');
        }
        $first = $rows[0];
        $months = [];
        foreach ($rows as $row) {
            if (null === $row['month']) {
                continue;
            }
            if (!\is_int($row['days']) || !\is_bool($row['partial']) || (null !== $row['row_count'] && !\is_int($row['row_count']))) {
                throw new \UnexpectedValueException('invalid_project_progress');
            }
            $period = new MonthSlice(self::text($row, 'month'), self::text($row, 'date_from'), self::text($row, 'date_to'), $row['days'], $row['partial']);
            $attempt = null;
            if (true === $row['matches']) {
                $provenance = null === $row['origin'] ? null : new RunProvenance(self::text($row, 'origin'), new \DateTimeImmutable(self::text($row, 'collected_at')), null === $row['used_at'] ? null : new \DateTimeImmutable(self::text($row, 'used_at')));
                $attempt = new RunMonthState($period, self::text($row, 'status'), null === $row['snapshot_id'] ? null : self::text($row, 'snapshot_id'), $row['row_count'], null === $row['error_code'] ? null : self::text($row, 'error_code'), $provenance);
            }
            $months[] = new ProjectMonthRow($period, $attempt);
        }
        if (!\is_int($first['version']) || (null !== $first['run_revision'] && !\is_int($first['run_revision']))) {
            throw new \UnexpectedValueException('invalid_project_progress');
        }

        return new ProjectProgressView(self::text($first, 'id'), $first['version'], ResearchProjectsQuery::mapStatus($first['project_status']), null === $first['last_run_id'] ? null : self::text($first, 'last_run_id'), $first['run_revision'], $months);
    }

    /** @param array<string, mixed> $row */
    private static function text(array $row, string $key): string
    {
        return ResearchRunsQuery::text($row, $key);
    }
}
