<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Infrastructure\Query;

use App\ProductResearchOzon\Domain\PeriodRange;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class ResearchProjectsQuery
{
    public const int DefaultLimit = 50;
    public const int MaxLimit = 200;
    private const string Columns = 'id, category_path, category_revision, name, include_current_month, period_count, version, created_by_user_id, created_at, updated_at';

    public function __construct(private Connection $db)
    {
    }

    public function page(string $companyId, ?string $cursor, int $limit): QueryBuilder
    {
        if ($limit < 1 || $limit > self::MaxLimit) {
            throw new \InvalidArgumentException('invalid_limit');
        }
        $query = $this->db->createQueryBuilder()
            ->select('p.id', 'p.category_path', 'p.name', 'p.period_count', 'p.version', 'p.created_at', ProjectStatusSql::expression('p').' AS status')
            ->from('product_research_ozon_project', 'p')
            ->where('company_id = :company')->setParameter('company', $companyId)
            ->orderBy('id', 'DESC')->setMaxResults($limit + 1);
        if (null !== $cursor) {
            $query->andWhere('id < :cursor')->setParameter('cursor', $cursor);
        }

        return $query;
    }

    public function detail(string $companyId, string $projectId): QueryBuilder
    {
        $columns = array_map(static fn (string $column): string => 'p.'.trim($column), explode(',', self::Columns));

        return $this->db->createQueryBuilder()->select(...[...$columns, ProjectStatusSql::expression('p').' AS status', 'p.comment', 'r.from_month', 'r.to_month', 'r.name AS period_name'])
            ->from('product_research_ozon_project', 'p')
            ->innerJoin('p', 'product_research_ozon_period', 'r', 'r.company_id = p.company_id AND r.project_id = p.id')
            ->where('p.company_id = :company')->andWhere('p.id = :id')
            ->setParameter('company', $companyId)->setParameter('id', $projectId)
            ->orderBy('r.position')->setMaxResults(24);
    }

    public function state(string $companyId, string $projectId): QueryBuilder
    {
        return $this->db->createQueryBuilder()->select(ProjectStatusSql::expression('p').' AS status')->from('product_research_ozon_project', 'p')->where('p.company_id=:company')->andWhere('p.id=:project')->setParameter('company', $companyId)->setParameter('project', $projectId)->setMaxResults(1);
    }

    public static function mapStatus(mixed $value): string
    {
        if (!\is_string($value) || !\in_array($value, ['draft', 'loading', 'loaded', 'partially_loaded', 'error'], true)) {
            throw new \UnexpectedValueException('invalid_research_status');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    public static function mapSummary(array $row): ResearchProjectSummaryRow
    {
        return new ResearchProjectSummaryRow(self::text($row, 'id'), self::text($row, 'category_path'), self::text($row, 'name'), self::integer($row, 'period_count'), self::integer($row, 'version'), self::text($row, 'created_at'), self::text($row, 'status'));
    }

    /** @param list<array<string, mixed>> $rows */
    public static function mapDetail(array $rows): ?ResearchProjectView
    {
        if ([] === $rows) {
            return null;
        }
        $project = self::map($rows[0]);
        if (\count($rows) !== $project->periodCount) {
            throw new \UnexpectedValueException('invalid_research_row');
        }
        $periods = array_map(static fn (array $row): PeriodRange => new PeriodRange(substr(self::text($row, 'from_month'), 0, 7), substr(self::text($row, 'to_month'), 0, 7), self::text($row, 'period_name')), $rows);

        return new ResearchProjectView($project, $periods);
    }

    /** @param array<string, mixed> $row */
    private static function map(array $row): ResearchProjectRow
    {
        if (!\is_bool($row['include_current_month'])) {
            throw new \UnexpectedValueException('invalid_research_row');
        }

        return new ResearchProjectRow(self::text($row, 'id'), self::text($row, 'category_path'), self::integer($row, 'category_revision'), self::text($row, 'name'), self::text($row, 'comment'), $row['include_current_month'], self::integer($row, 'period_count'), self::integer($row, 'version'), self::text($row, 'created_by_user_id'), self::text($row, 'created_at'), self::text($row, 'updated_at'), self::text($row, 'status'));
    }

    /** @param array<string, mixed> $row */
    private static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('invalid_research_row');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!\is_int($value)) {
            throw new \UnexpectedValueException('invalid_research_row');
        }

        return $value;
    }
}
