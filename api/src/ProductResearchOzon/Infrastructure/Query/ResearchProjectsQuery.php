<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Infrastructure\Query;

use App\ProductResearchOzon\Domain\PeriodRange;
use Doctrine\DBAL\Connection;

final readonly class ResearchProjectsQuery
{
    public const int DefaultLimit = 50;
    public const int MaxLimit = 200;
    private const string Columns = 'id, category_path, category_revision, name, include_current_month, period_count, version, created_by_user_id, created_at, updated_at';

    public function __construct(private Connection $db)
    {
    }

    /** @return list<ResearchProjectRow> */
    public function page(string $companyId, ?string $cursor, int $limit): array
    {
        if ($limit < 1 || $limit > self::MaxLimit) {
            throw new \InvalidArgumentException('invalid_limit');
        }
        $parameters = ['company' => $companyId];
        $where = '';
        if (null !== $cursor) {
            $where = ' AND id < :cursor';
            $parameters['cursor'] = $cursor;
        }
        $rows = $this->db->fetchAllAssociative('SELECT '.self::Columns.", '' AS comment FROM product_research_ozon_project WHERE company_id = :company".$where.' ORDER BY id DESC LIMIT '.($limit + 1), $parameters);

        return array_map(self::map(...), $rows);
    }

    public function detail(string $companyId, string $projectId): ?ResearchProjectView
    {
        $columns = implode(', ', array_map(static fn (string $column): string => 'p.'.trim($column), explode(',', self::Columns)));
        $rows = $this->db->fetchAllAssociative('SELECT '.$columns.', p.comment, r.from_month, r.to_month, r.name AS period_name FROM product_research_ozon_project p JOIN product_research_ozon_period r ON r.company_id = p.company_id AND r.project_id = p.id WHERE p.company_id = :company AND p.id = :id ORDER BY r.position LIMIT 24', ['company' => $companyId, 'id' => $projectId]);
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

        return new ResearchProjectRow(self::text($row, 'id'), self::text($row, 'category_path'), self::integer($row, 'category_revision'), self::text($row, 'name'), self::text($row, 'comment'), $row['include_current_month'], self::integer($row, 'period_count'), self::integer($row, 'version'), self::text($row, 'created_by_user_id'), self::text($row, 'created_at'), self::text($row, 'updated_at'));
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
