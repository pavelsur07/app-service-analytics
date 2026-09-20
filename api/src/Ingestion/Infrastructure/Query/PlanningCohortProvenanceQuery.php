<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/** Bounded raw links for the cohort rows on one already limited page. */
final readonly class PlanningCohortProvenanceQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /** @param list<array{sku: string, date: string}> $keys */
    public function build(string $companyId, string $accountId, array $keys): QueryBuilder
    {
        $source = <<<'SQL'
            (
                WITH selected AS MATERIALIZED (
                    SELECT DISTINCT sku, business_date::date
                    FROM jsonb_to_recordset(:keys::jsonb) AS k(sku text, business_date text)
                ), sales AS MATERIALIZED (
                    SELECT s.marketplace_sku AS sku, s.business_date, s.posting_number,
                           s.order_number, s.raw_document_id
                    FROM sales_fact s
                    JOIN selected k ON k.sku = s.marketplace_sku AND k.business_date = s.business_date
                    WHERE s.company_id = :company AND s.marketplace_account_id = :account
                ), raw_refs AS (
                    SELECT sku, business_date, raw_document_id FROM sales
                    UNION ALL
                    SELECT s.sku, s.business_date, st.raw_document_id
                    FROM sales s
                    JOIN marketplace_posting_status st
                      ON st.company_id = :company AND st.marketplace_account_id = :account
                     AND st.posting_number = s.posting_number
                    UNION ALL
                    SELECT s.sku, s.business_date, r.raw_document_id
                    FROM sales s
                    JOIN marketplace_return_fact r
                      ON r.company_id = :company AND r.marketplace_account_id = :account
                     AND r.order_number = s.order_number AND r.marketplace_sku = s.sku
                ), distinct_refs AS (
                    SELECT DISTINCT sku, business_date, raw_document_id
                    FROM raw_refs WHERE raw_document_id IS NOT NULL
                ), ranked_refs AS (
                    SELECT sku, business_date, raw_document_id,
                           ROW_NUMBER() OVER (PARTITION BY sku, business_date ORDER BY raw_document_id) AS position
                    FROM distinct_refs
                )
                SELECT k.sku, k.business_date::text AS business_date,
                       COALESCE(jsonb_agg(refs.raw_document_id::text ORDER BY refs.position)
                           FILTER (WHERE refs.position <= 50), '[]'::jsonb)::text AS raw_document_ids,
                       COALESCE(BOOL_OR(refs.position > 50), FALSE) AS raw_document_ids_truncated
                FROM selected k
                LEFT JOIN ranked_refs refs ON refs.sku = k.sku AND refs.business_date = k.business_date
                GROUP BY k.sku, k.business_date
            ) provenance
            SQL;

        return $this->connection->createQueryBuilder()->select('sku', 'business_date', 'raw_document_ids', 'raw_document_ids_truncated')
            ->from($source)->setParameter('company', $companyId)->setParameter('account', $accountId)
            ->setParameter('keys', json_encode(array_map(static fn (array $key): array => [
                'sku' => $key['sku'], 'business_date' => $key['date'],
            ], $keys), \JSON_THROW_ON_ERROR))
            ->orderBy('sku')->addOrderBy('business_date')->setMaxResults(\count($keys));
    }
}
