<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;

/** Independent simple mature observed R/G, never the model's selected return curve. */
final readonly class NetForwardBaselineQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function build(string $companyId, string $accountId, NetTrainingContext $context, NetTrainingRequest $request): QueryBuilder
    {
        $context->assertScope($companyId, $accountId);
        $ctes = NetTrainingSourceSql::ctes(true).', '.ReturnTrainingAggregateQuery::populationCtes();
        $sql = <<<SQL
            WITH {$ctes}, baseline_groups AS MATERIALIZED (
                SELECT g.order_number, g.marketplace_sku, g.gross_quantity,
                       COALESCE(SUM(b.quantity), 0) AS returned_quantity
                FROM eligible_return_groups g LEFT JOIN client_delta_bins b USING (order_number, marketplace_sku)
                GROUP BY g.order_number, g.marketplace_sku, g.gross_quantity
            ), baseline_rates AS MATERIALIZED (
                SELECT CASE WHEN GROUPING(marketplace_sku) = 1 THEN NULL ELSE marketplace_sku END AS marketplace_sku,
                       SUM(gross_quantity)::text AS gross_quantity, SUM(returned_quantity)::text AS returned_quantity
                FROM baseline_groups GROUP BY GROUPING SETS ((), (marketplace_sku))
            ), baseline_postings AS MATERIALIZED (
                SELECT CASE WHEN GROUPING(marketplace_sku) = 1 THEN NULL ELSE marketplace_sku END AS marketplace_sku,
                       COUNT(DISTINCT posting_number)::int AS postings
                FROM posting_capacities GROUP BY GROUPING SETS ((), (marketplace_sku))
            )
            SELECT scope.marketplace_sku, budget.ok AS input_complete, rates.gross_quantity, rates.returned_quantity,
                   COALESCE(postings.postings, 0) AS postings
            FROM (SELECT NULL::text AS marketplace_sku UNION ALL SELECT UNNEST(ARRAY[:requestedSkus]::text[])) scope
            CROSS JOIN input_budget budget
            LEFT JOIN baseline_rates rates ON rates.marketplace_sku IS NOT DISTINCT FROM scope.marketplace_sku
            LEFT JOIN baseline_postings postings ON postings.marketplace_sku IS NOT DISTINCT FROM scope.marketplace_sku
            ORDER BY scope.marketplace_sku NULLS FIRST LIMIT 51
            SQL;

        return NetTrainingSourceSql::bind($this->connection->createQueryBuilder()->select('b.marketplace_sku', 'b.input_complete', 'b.gross_quantity', 'b.returned_quantity', 'b.postings')->from('('.$sql.')', 'b'), $context, $request, new NetTrainingLimits());
    }

    /** @return list<NetForwardBaselineRate> */
    public static function mapRows(Result $result): array
    {
        $rates = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $rates[] = new NetForwardBaselineRate(NetTrainingValue::nullableString($row['marketplace_sku'] ?? null), NetTrainingValue::boolean($row['input_complete'] ?? null), NetTrainingValue::nullableString($row['gross_quantity'] ?? null), NetTrainingValue::nullableString($row['returned_quantity'] ?? null), NetTrainingValue::integer($row['postings'] ?? null));
        }

        return $rates;
    }
}
