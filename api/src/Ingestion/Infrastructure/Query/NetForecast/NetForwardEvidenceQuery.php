<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;

/** Same source selectors as model; account population precedes requested SKU filtering. */
final readonly class NetForwardEvidenceQuery
{
    public const int PAGE_SIZE = 200;

    public function __construct(private Connection $connection)
    {
    }

    public function build(string $companyId, string $accountId, NetTrainingContext $context, NetTargetRequest $request, string $population, ?string $after = null): QueryBuilder
    {
        $context->assertScope($companyId, $accountId);
        $target = 'target' === $population;
        $evidence = $this->evidenceSql($population);
        // Body contains the selected relation's stable identity and full consumed values.
        // It orders evidence deterministically; this is never used as causal ordering.
        $source = $evidence.", page AS MATERIALIZED (SELECT relation, body, relation || ':' || body AS cursor FROM evidence WHERE CAST(:after AS text) IS NULL OR relation || ':' || body > :after ORDER BY cursor LIMIT ".self::PAGE_SIZE.'), sized AS MATERIALIZED (SELECT SUM(octet_length(body) + octet_length(cursor)) <= 1048576 AS ok FROM page) SELECT relation, CASE WHEN sized.ok THEN body END AS body, CASE WHEN sized.ok THEN cursor END AS cursor FROM page CROSS JOIN sized ORDER BY page.cursor';
        $query = $this->connection->createQueryBuilder()->select('e.relation', 'e.body', 'e.cursor')->from('('.$source.')', 'e')->setParameter('after', $after);

        return $target ? NetTargetSourceSql::bind($query, $context, $request, new NetTargetLimits()) : NetTrainingSourceSql::bind($query, $context, new NetTrainingRequest($request->marketplaceSkus), new NetTrainingLimits());
    }

    /** Build the bounded export; cursor lifecycle belongs to Application. */
    public function buildCursor(string $companyId, string $accountId, NetTrainingContext $context, NetTargetRequest $request, string $population): QueryBuilder
    {
        $context->assertScope($companyId, $accountId);
        $source = $this->evidenceSql($population).", ordered AS MATERIALIZED (SELECT relation, body, relation || ':' || body AS cursor, ROW_NUMBER() OVER (ORDER BY relation || ':' || body) AS ordinal FROM evidence), sized AS (SELECT relation, body, cursor, ordinal, SUM(octet_length(body) + octet_length(cursor)) OVER (PARTITION BY (ordinal - 1) / ".self::PAGE_SIZE.') <= 1048576 AS ok FROM ordered) SELECT relation, CASE WHEN ok THEN body END AS body, CASE WHEN ok THEN cursor END AS cursor FROM sized ORDER BY ordinal';
        $query = $this->connection->createQueryBuilder()->select('e.relation', 'e.body', 'e.cursor')->from('('.$source.')', 'e');
        $query = 'target' === $population ? NetTargetSourceSql::bind($query, $context, $request, new NetTargetLimits()) : NetTrainingSourceSql::bind($query, $context, new NetTrainingRequest($request->marketplaceSkus), new NetTrainingLimits());

        return $query;
    }

    private function evidenceSql(string $population): string
    {
        if (!\in_array($population, ['gross', 'returns', 'target'], true)) {
            throw new \InvalidArgumentException('Unknown evidence population.');
        }
        $target = 'target' === $population;
        $ctes = $target ? NetTargetSourceSql::ctes() : NetTrainingSourceSql::ctes('returns' === $population);
        $relations = ['sale_keys', 'sales_revisions', 'return_keys', 'return_revisions', 'status_inputs', 'receipt_inputs', 'input_budget', 'seed_history', 'candidate_groups'];
        if ('returns' === $population) {
            $ctes .= ', '.ReturnTrainingAggregateQuery::populationCtes();
            $relations = [...$relations, 'candidate_deliveries', 'return_members', 'return_groups', 'client_delta_bins', 'posting_capacities'];
        } elseif ('gross' === $population) {
            $ctes .= ', '.NetTrainingOutcomeSql::ctes().', '.GrossTrainingAggregateQuery::populationCtes();
            $relations = [...$relations, 'canonical_outcomes', 'gross_days'];
        }
        $segments = [];
        foreach ($relations as $relation) {
            $body = 'row_to_json(r)::jsonb';
            $join = '';
            if (\in_array($relation, ['sales_revisions', 'return_revisions'], true)) {
                $table = 'sales_revisions' === $relation ? 'sales_fact_revision' : 'marketplace_return_fact_revision';
                $join = ' JOIN '.$table.' original ON original.company_id = :companyId AND original.marketplace_account_id = :accountId AND original.source_row_id = r.source_row_id AND original.fact_revision = r.fact_revision';
                $body .= " || jsonb_build_object('id', original.id)";
            }
            $segments[] = "SELECT '$relation' AS relation, ($body)::text AS body FROM $relation r$join";
        }

        return 'WITH '.$ctes.', evidence AS MATERIALIZED ('.implode(' UNION ', $segments).')';
    }

    /** @return list<NetForwardEvidenceRow> */
    public static function mapRows(Result $result): array
    {
        $evidence = [];
        foreach ($result->fetchAllAssociative() as $row) {
            if (!\is_string($row['body'] ?? null) || !\is_string($row['cursor'] ?? null) || !\is_string($row['relation'] ?? null)) {
                throw new \OverflowException('Evidence page byte budget exceeded.');
            }
            $values = json_decode($row['body'], true, 40, \JSON_THROW_ON_ERROR);
            if (!\is_array($values)) {
                throw new \UnexpectedValueException('Evidence row must contain a JSON object.');
            }
            foreach ($values as $key => $_) {
                if (!\is_string($key)) {
                    throw new \UnexpectedValueException('Evidence object keys must be strings.');
                }
            }
            /* @var array<string, mixed> $values */
            $evidence[] = new NetForwardEvidenceRow($row['relation'], $values, $row['cursor']);
        }

        return $evidence;
    }
}
