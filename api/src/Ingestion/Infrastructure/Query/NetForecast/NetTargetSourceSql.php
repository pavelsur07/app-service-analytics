<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Query\QueryBuilder;

/** Current target population, including historical aliases before requested filters. */
final class NetTargetSourceSql
{
    public static function bind(QueryBuilder $query, NetTrainingContext $context, NetTargetRequest $request, NetTargetLimits $limits): QueryBuilder
    {
        foreach (['companyId' => $context->companyId, 'accountId' => $context->accountId, 'asOf' => $context->readAt->format('Y-m-d H:i:s.u'), 'epochStart' => $context->epoch->start->format('Y-m-d H:i:s.u'), 'captureVersion' => $context->epoch->captureVersion, 'targetFrom' => ($request->accumulationFrom ?? $request->from)->format('Y-m-d'), 'targetTo' => $request->to->format('Y-m-d'), 'maxGroups' => $limits->groups, 'groupSentinel' => $limits->groups + 1, 'maxSourceKeys' => $limits->sourceKeys, 'saleSentinel' => $limits->sourceKeys + 1, 'returnSentinel' => $limits->sourceKeys + 1, 'maxRevisions' => $limits->revisions, 'revisionSentinel' => $limits->revisions + 1, 'maxStatuses' => $limits->statuses, 'statusSentinel' => $limits->statuses + 1, 'maxReceipts' => $limits->receipts, 'receiptSentinel' => $limits->receipts + 1, 'maxRevisionsPerSource' => $limits->revisionsPerSource, 'sourceRevisionSentinel' => $limits->revisionsPerSource + 1, 'maxSourcesPerGroup' => $limits->sourcesPerGroup, 'maxStatusesPerGroup' => $limits->statusesPerGroup, 'maxGroupsPerSkuDay' => $limits->groupsPerSkuDay, 'maxOutputBytes' => $limits->outputBytes, 'maxPhpAllocationBytes' => $limits->phpAllocationBytes] as $key => $value) {
            $query->setParameter($key, $value);
        }

        return $query->setParameter('requestedSkus', $request->marketplaceSkus, ArrayParameterType::STRING);
    }

    public static function ctes(): string
    {
        $sales = NetTrainingSourceSql::columns('r', [...NetTrainingSourceSql::SALES_COLUMNS, 'id', 'amount_minor', 'currency', 'row_hash']);
        $returnsColumns = NetTrainingSourceSql::columns('r', [...NetTrainingSourceSql::RETURN_COLUMNS, 'id', 'row_hash']);

        return <<<SQL
            target_requested_skus AS MATERIALIZED (
                SELECT DISTINCT UNNEST(ARRAY[:requestedSkus]::text[]) AS marketplace_sku
            ),
            target_current_seed AS MATERIALIZED (
                SELECT f.source_row_id FROM sales_fact f JOIN target_requested_skus requested ON requested.marketplace_sku = f.marketplace_sku
                WHERE f.company_id = :companyId AND f.marketplace_account_id = :accountId
                  AND f.business_date BETWEEN CAST(:targetFrom AS date) AND CAST(:targetTo AS date)
                LIMIT :saleSentinel
            ),
            target_historical_seed AS MATERIALIZED (
                SELECT r.source_row_id FROM sales_fact_revision r JOIN target_requested_skus requested ON requested.marketplace_sku = r.marketplace_sku
                WHERE r.company_id = :companyId AND r.marketplace_account_id = :accountId
                  AND r.captured_at <= CAST(:asOf AS timestamp)
                  AND r.business_date BETWEEN CAST(:targetFrom AS date) AND CAST(:targetTo AS date)
                LIMIT :revisionSentinel
            ),
            seed_keys AS MATERIALIZED (
                SELECT source_row_id FROM target_current_seed UNION SELECT source_row_id FROM target_historical_seed LIMIT :saleSentinel
            ),
            seed_history AS MATERIALIZED (
                SELECT r.source_row_id, r.order_number FROM seed_keys k CROSS JOIN LATERAL (
                    SELECT r.source_row_id, r.order_number FROM sales_fact_revision r
                    WHERE r.company_id = :companyId AND r.marketplace_account_id = :accountId AND r.source_row_id = k.source_row_id
                      AND r.captured_at <= CAST(:asOf AS timestamp) AND r.fact_revision <= CAST(:maxRevisionsPerSource AS bigint) + 1
                    LIMIT :sourceRevisionSentinel
                ) r
                LIMIT :revisionSentinel
            ),
            seed_orders AS MATERIALIZED (
                SELECT f.order_number FROM seed_keys k CROSS JOIN LATERAL (
                    SELECT f.order_number FROM sales_fact f
                    WHERE f.company_id = :companyId AND f.marketplace_account_id = :accountId AND f.source_row_id = k.source_row_id
                    LIMIT 1
                ) f WHERE f.order_number IS NOT NULL
                UNION SELECT order_number FROM seed_history WHERE order_number IS NOT NULL
            ),
            historical_order_links AS MATERIALIZED (
                SELECT r.source_row_id FROM sales_fact_revision r
                WHERE r.company_id = :companyId AND r.marketplace_account_id = :accountId
                  AND r.order_number IN (SELECT order_number FROM seed_orders)
                LIMIT :revisionSentinel
            ),
            historical_order_keys AS MATERIALIZED (
                SELECT DISTINCT source_row_id FROM historical_order_links
                LIMIT :saleSentinel
            ),
            current_order_keys AS MATERIALIZED (
                SELECT f.source_row_id FROM sales_fact f JOIN seed_orders o ON o.order_number = f.order_number
                WHERE f.company_id = :companyId AND f.marketplace_account_id = :accountId
                LIMIT :saleSentinel
            ),
            expanded_keys AS MATERIALIZED (
                SELECT source_row_id FROM seed_keys
                UNION SELECT source_row_id FROM current_order_keys
                UNION SELECT source_row_id FROM historical_order_keys
                LIMIT :saleSentinel
            ),
            sale_keys AS MATERIALIZED (
                SELECT f.source_row_id, f.fact_revision, f.business_date, f.order_number, f.marketplace_sku, f.posting_number, f.quantity, f.status, f.raw_document_id, f.row_hash
                FROM expanded_keys k CROSS JOIN LATERAL (
                    SELECT f.source_row_id, f.fact_revision, f.business_date, f.order_number, f.marketplace_sku, f.posting_number, f.quantity, f.status, f.raw_document_id, f.row_hash
                    FROM sales_fact f
                    WHERE f.company_id = :companyId AND f.marketplace_account_id = :accountId AND f.source_row_id = k.source_row_id
                    LIMIT 1
                ) f LIMIT :saleSentinel
            ),
            sales_revisions AS MATERIALIZED (
                SELECT {$sales}
                FROM sale_keys k CROSS JOIN LATERAL (
                    SELECT {$sales} FROM sales_fact_revision r
                    WHERE r.company_id = :companyId AND r.marketplace_account_id = :accountId AND r.source_row_id = k.source_row_id
                      AND r.captured_at <= CAST(:asOf AS timestamp) AND r.fact_revision <= CAST(:maxRevisionsPerSource AS bigint) + 1
                    LIMIT :sourceRevisionSentinel
                ) r
                LIMIT :revisionSentinel
            ),
            candidate_groups AS MATERIALIZED (
                SELECT business_date, order_number, marketplace_sku, COALESCE(order_number, source_row_id) AS order_key
                FROM (
                    SELECT r.business_date, r.order_number, r.marketplace_sku, r.source_row_id FROM sales_revisions r
                    UNION ALL
                    SELECT k.business_date, k.order_number, k.marketplace_sku, k.source_row_id FROM sale_keys k
                ) identities
                GROUP BY business_date, order_number, marketplace_sku, COALESCE(order_number, source_row_id)
                LIMIT :groupSentinel
            ),
            all_orders AS MATERIALIZED (
                SELECT DISTINCT order_number FROM candidate_groups WHERE order_number IS NOT NULL
            ),
            return_historical_links AS MATERIALIZED (
                SELECT r.source_row_id FROM marketplace_return_fact_revision r
                WHERE r.company_id = :companyId AND r.marketplace_account_id = :accountId
                  AND r.captured_at <= CAST(:asOf AS timestamp)
                  AND r.order_number IN (SELECT order_number FROM seed_orders)
                LIMIT :revisionSentinel
            ),
            target_return_candidate_keys AS MATERIALIZED (
                (SELECT f.source_row_id FROM marketplace_return_fact f JOIN seed_orders o ON o.order_number = f.order_number
                 WHERE f.company_id = :companyId AND f.marketplace_account_id = :accountId LIMIT :returnSentinel)
                UNION SELECT source_row_id FROM return_historical_links
                LIMIT :returnSentinel
            ),
            return_keys AS MATERIALIZED (
                SELECT f.source_row_id, f.fact_revision, f.order_number, f.marketplace_sku, f.quantity, f.return_type, f.return_reason_name, f.raw_document_id, f.row_hash
                FROM target_return_candidate_keys k CROSS JOIN LATERAL (
                    SELECT f.source_row_id, f.fact_revision, f.order_number, f.marketplace_sku, f.quantity, f.return_type, f.return_reason_name, f.raw_document_id, f.row_hash
                    FROM marketplace_return_fact f
                    WHERE f.company_id = :companyId AND f.marketplace_account_id = :accountId AND f.source_row_id = k.source_row_id
                    LIMIT 1
                ) f LIMIT :returnSentinel
            ),
            return_revisions AS MATERIALIZED (
                SELECT {$returnsColumns}
                FROM return_keys k CROSS JOIN LATERAL (
                    SELECT {$returnsColumns} FROM marketplace_return_fact_revision r
                    WHERE r.company_id = :companyId AND r.marketplace_account_id = :accountId AND r.source_row_id = k.source_row_id
                      AND r.captured_at <= CAST(:asOf AS timestamp) AND r.fact_revision <= CAST(:maxRevisionsPerSource AS bigint) + 1
                    LIMIT :sourceRevisionSentinel
                ) r
                LIMIT :revisionSentinel
            ),
            posting_keys AS MATERIALIZED (
                SELECT DISTINCT posting_number FROM sale_keys WHERE posting_number IS NOT NULL
                UNION SELECT DISTINCT posting_number FROM sales_revisions WHERE posting_number IS NOT NULL
            ),
            status_inputs AS MATERIALIZED (
                SELECT s.company_id, s.marketplace_account_id, s.posting_number, s.order_number, s.status, s.substatus,
                       s.cancel_reason_id, s.observed_at, s.raw_document_id
                FROM marketplace_posting_status s JOIN posting_keys k ON k.posting_number = s.posting_number
                WHERE s.company_id = :companyId AND s.marketplace_account_id = :accountId
                  AND s.observed_at >= CAST(:epochStart AS timestamp) AND s.observed_at <= CAST(:asOf AS timestamp)
                LIMIT :statusSentinel
            ),
            receipt_inputs AS MATERIALIZED (
                SELECT r.id, r.report_type, r.date_from, r.date_to, r.marked_at, r.status, r.capture_origin, r.capture_version
                FROM marketplace_day_load_receipt r
                WHERE r.company_id = :companyId AND r.marketplace_account_id = :accountId
                  AND r.report_type IN ('ozon_posting_fbo_list', 'ozon_returns_list')
                  AND r.marked_at >= CAST(:epochStart AS timestamp) AND r.marked_at <= CAST(:asOf AS timestamp)
                  AND (r.report_type = 'ozon_returns_list' OR r.date_from <= CAST(:targetTo AS date) AND r.date_to >= CAST(:targetFrom AS date) OR EXISTS (
                      SELECT 1 FROM candidate_groups g WHERE g.business_date BETWEEN r.date_from AND r.date_to))
                LIMIT :receiptSentinel
            ),
            group_key_counts AS MATERIALIZED (
                SELECT COALESCE(order_number, source_row_id) AS order_key, marketplace_sku, COUNT(DISTINCT source_row_id) AS sources
                FROM (
                    SELECT source_row_id, order_number, marketplace_sku FROM sale_keys
                    UNION SELECT source_row_id, order_number, marketplace_sku FROM sales_revisions
                ) identities GROUP BY COALESCE(order_number, source_row_id), marketplace_sku
            ),
            return_key_counts AS MATERIALIZED (
                SELECT COALESCE(order_number, source_row_id) AS order_key, marketplace_sku, COUNT(DISTINCT source_row_id) AS sources
                FROM (
                    SELECT source_row_id, order_number, marketplace_sku FROM return_keys
                    UNION SELECT source_row_id, order_number, marketplace_sku FROM return_revisions
                ) identities GROUP BY COALESCE(order_number, source_row_id), marketplace_sku
            ),
            group_status_counts AS MATERIALIZED (
                SELECT COALESCE(k.order_number, k.source_row_id) AS order_key, k.marketplace_sku,
                       COUNT(DISTINCT (s.posting_number, s.raw_document_id)) FILTER (WHERE s.raw_document_id IS NOT NULL) AS observations
                FROM sale_keys k LEFT JOIN status_inputs s ON s.posting_number = k.posting_number
                GROUP BY COALESCE(k.order_number, k.source_row_id), k.marketplace_sku
            ),
            combined_group_keys AS MATERIALIZED (
                SELECT order_key, marketplace_sku, COUNT(source_key) AS sources FROM (
                    SELECT COALESCE(order_number, source_row_id) AS order_key, marketplace_sku, 'sale:' || source_row_id AS source_key FROM sale_keys
                    UNION SELECT COALESCE(order_number, source_row_id), marketplace_sku, 'return:' || source_row_id FROM return_keys
                ) keys GROUP BY order_key, marketplace_sku
            ),
            day_group_counts AS MATERIALIZED (
                SELECT business_date, marketplace_sku, COUNT(order_key) AS groups FROM candidate_groups GROUP BY business_date, marketplace_sku
            ),
            input_metrics AS MATERIALIZED (
                SELECT (SELECT COUNT(source_row_id) FROM target_current_seed) AS current_seed,
                       (SELECT COUNT(source_row_id) FROM target_historical_seed) AS historical_seed,
                       (SELECT COUNT(source_row_id) FROM seed_keys) AS seed_sales,
                       (SELECT COUNT(source_row_id) FROM seed_history) AS seed_revisions,
                       (SELECT COUNT(source_row_id) FROM historical_order_links) AS historical_revisions,
                       (SELECT COUNT(source_row_id) FROM current_order_keys) AS current_order_sales,
                       (SELECT COUNT(source_row_id) FROM expanded_keys) AS expanded_sales,
                       (SELECT COUNT(source_row_id) FROM sale_keys) + (SELECT COUNT(source_row_id) FROM return_keys) AS sources,
                       (SELECT COUNT(source_row_id) FROM return_historical_links) AS return_links,
                       (SELECT COUNT(order_key) FROM candidate_groups) AS group_count,
                       (SELECT COUNT(source_row_id) FROM sales_revisions) + (SELECT COUNT(source_row_id) FROM return_revisions) AS revisions,
                       (SELECT COUNT(raw_document_id) FROM status_inputs) AS statuses,
                       (SELECT COUNT(id) FROM receipt_inputs) AS receipts,
                       GREATEST(COALESCE((SELECT MAX(fact_revision) FROM sale_keys), 0), COALESCE((SELECT MAX(fact_revision) FROM return_keys), 0)) AS source_revisions,
                       COALESCE((SELECT MAX(sources) FROM combined_group_keys), 0) AS sources_per_group,
                       COALESCE((SELECT MAX(observations) FROM group_status_counts), 0) AS statuses_per_group,
                       COALESCE((SELECT MAX(groups) FROM day_group_counts), 0) AS groups_per_day
            ),
            input_budget AS MATERIALIZED (
                SELECT exceeded.input IS NULL AS ok, exceeded.input, exceeded.observed, exceeded.maximum
                FROM input_metrics m LEFT JOIN LATERAL (
                    SELECT v.input, v.observed, v.maximum FROM (VALUES
                        ('current_seed', m.current_seed, CAST(:maxSourceKeys AS bigint), 1),
                        ('historical_seed', m.historical_seed, CAST(:maxRevisions AS bigint), 2),
                        ('seed_keys', m.seed_sales, CAST(:maxSourceKeys AS bigint), 3),
                        ('seed_revisions', m.seed_revisions, CAST(:maxRevisions AS bigint), 4),
                        ('historical_order_links', m.historical_revisions, CAST(:maxRevisions AS bigint), 5),
                        ('current_order_keys', m.current_order_sales, CAST(:maxSourceKeys AS bigint), 6),
                        ('expanded_keys', m.expanded_sales, CAST(:maxSourceKeys AS bigint), 7),
                        ('source_keys', m.sources, CAST(:maxSourceKeys AS bigint), 8),
                        ('return_historical_links', m.return_links, CAST(:maxRevisions AS bigint), 9),
                        ('groups', m.group_count, CAST(:maxGroups AS bigint), 10),
                        ('revisions', m.revisions, CAST(:maxRevisions AS bigint), 11),
                        ('statuses', m.statuses, CAST(:maxStatuses AS bigint), 12),
                        ('receipts', m.receipts, CAST(:maxReceipts AS bigint), 13),
                        ('revisions_per_source', m.source_revisions, CAST(:maxRevisionsPerSource AS bigint), 14),
                        ('sources_per_group', m.sources_per_group, CAST(:maxSourcesPerGroup AS bigint), 15),
                        ('statuses_per_group', m.statuses_per_group, CAST(:maxStatusesPerGroup AS bigint), 16),
                        ('groups_per_sku_day', m.groups_per_day, CAST(:maxGroupsPerSkuDay AS bigint), 17)
                    ) v(input, observed, maximum, priority)
                    WHERE v.observed > v.maximum ORDER BY v.priority LIMIT 1
                ) exceeded ON TRUE
            )
            SQL;
    }
}
