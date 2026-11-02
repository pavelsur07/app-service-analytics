<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Infrastructure\Query;

use App\ProductResearchOzon\Domain\MonthSlice;
use App\ProductResearchOzon\Domain\ValueObject\RunMonthState;
use App\ProductResearchOzon\Domain\ValueObject\RunProgress;
use App\ProductResearchOzon\Domain\ValueObject\RunProvenance;
use App\ProductResearchOzon\Domain\ValueObject\RunView;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class ResearchRunsQuery
{
    public function __construct(private Connection $db)
    {
    }

    public function detail(string $companyId, string $projectId, string $runId): QueryBuilder
    {
        return $this->db->createQueryBuilder()->select('r.company_id', 'r.id', 'r.project_id', 'r.source_id', 'r.status AS run_status', 'r.revision', 'r.created_at', 'r.delivered_at', 'm.month', 'm.date_from', 'm.date_to', 'm.days', 'm.partial', 'm.status', 'm.snapshot_id', 'm.row_count', 'm.error_code', 'm.origin', 'm.collected_at', 'm.used_at')
            ->from('product_research_ozon_run', 'r')->innerJoin('r', 'product_research_ozon_run_month', 'm', 'm.company_id = r.company_id AND m.run_id = r.id')
            ->where('r.company_id = :company')->andWhere('r.project_id = :project')->andWhere('r.id = :run')
            ->setParameter('company', $companyId)->setParameter('project', $projectId)->setParameter('run', $runId)->orderBy('m.month')->setMaxResults(25);
    }

    public function readyMonths(string $companyId, string $projectId, string $sourceId): QueryBuilder
    {
        return $this->db->createQueryBuilder()->select('DISTINCT m.month', 'm.date_from', 'm.date_to')
            ->from('product_research_ozon_run', 'r')->innerJoin('r', 'product_research_ozon_run_month', 'm', 'm.company_id = r.company_id AND m.run_id = r.id')
            ->where('r.company_id = :company')->andWhere('r.project_id = :project')->andWhere('r.source_id = :source')->andWhere("m.status = 'ready'")->andWhere('m.partial = FALSE')
            ->setParameter('company', $companyId)->setParameter('project', $projectId)->setParameter('source', $sourceId)->orderBy('m.month')->setMaxResults(24);
    }

    public function due(string $companyId, bool $delivery, \DateTimeImmutable $at, int $limit = 20): QueryBuilder
    {
        if ($limit < 1 || $limit > 200) {
            throw new \InvalidArgumentException('invalid_limit');
        }
        $column = $delivery ? 'next_delivery_at' : 'next_poll_at';

        return $this->db->createQueryBuilder()->select('id')->from('product_research_ozon_run')->where('company_id = :company')->andWhere('finished_at IS NULL')
            ->andWhere($delivery ? 'delivered_at IS NULL' : 'delivered_at IS NOT NULL')->andWhere($column.' <= :at')->andWhere('(lease_until IS NULL OR lease_until <= clock_timestamp())')
            ->setParameter('company', $companyId)->setParameter('at', $at->format('Y-m-d H:i:s.uP'))->orderBy($column)->addOrderBy('id')->setMaxResults($limit);
    }

    public function reconcile(string $companyId, ?string $after, int $limit): QueryBuilder
    {
        if ($limit < 1 || $limit > 200) {
            throw new \InvalidArgumentException('invalid_limit');
        }
        $q = $this->db->createQueryBuilder()->select('id')->from('product_research_ozon_run')->where('company_id=:company')->andWhere('delivered_at IS NOT NULL')->setParameter('company', $companyId)->orderBy('id')->setMaxResults($limit);
        if (null !== $after) {
            $q->andWhere('id > :after')->setParameter('after', $after);
        }

        return $q;
    }

    /** @param list<array<string, mixed>> $rows */
    public static function map(array $rows): ?RunView
    {
        if ([] === $rows) {
            return null;
        }
        $first = $rows[0];
        $months = [];
        foreach ($rows as $row) {
            if (!\is_bool($row['partial']) || !\is_int($row['days']) || (null !== $row['row_count'] && !\is_int($row['row_count']))) {
                throw new \UnexpectedValueException('invalid_run_row');
            }
            $months[] = new RunMonthState(new MonthSlice(self::text($row, 'month'), self::text($row, 'date_from'), self::text($row, 'date_to'), $row['days'], $row['partial']), self::text($row, 'status'), self::nullableText($row, 'snapshot_id'), $row['row_count'], self::nullableText($row, 'error_code'), null === $row['origin'] ? null : new RunProvenance(self::text($row, 'origin'), new \DateTimeImmutable(self::text($row, 'collected_at')), null === $row['used_at'] ? null : new \DateTimeImmutable(self::text($row, 'used_at'))));
        }
        if (!\is_int($first['revision'])) {
            throw new \UnexpectedValueException('invalid_run_row');
        }

        return new RunView(self::text($first, 'project_id'), new RunProgress(self::text($first, 'company_id'), self::text($first, 'id'), self::text($first, 'source_id'), self::text($first, 'run_status'), $first['revision'], $months), null !== $first['delivered_at'], new \DateTimeImmutable(self::text($first, 'created_at')));
    }

    /** @param array<string, mixed> $row */
    public static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('invalid_run_row');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function nullableText(array $row, string $key): ?string
    {
        return null === $row[$key] ? null : self::text($row, $key);
    }
}
