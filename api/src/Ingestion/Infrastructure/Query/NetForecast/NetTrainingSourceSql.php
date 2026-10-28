<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Query\QueryBuilder;

/** Fixed-source, bounded current-snapshot inputs shared by both populations. */
final class NetTrainingSourceSql
{
    public const array SALES_COLUMNS = ['source_row_id', 'fact_revision', 'captured_at', 'capture_version', 'capture_origin', 'capture_path', 'capture_is_initial', 'business_date', 'status', 'marketplace_sku', 'posting_number', 'order_number', 'quantity', 'raw_document_id', 'first_loaded_at'];
    public const array RETURN_COLUMNS = ['source_row_id', 'fact_revision', 'captured_at', 'capture_version', 'capture_origin', 'capture_path', 'capture_is_initial', 'order_number', 'marketplace_sku', 'return_type', 'return_reason_name', 'posting_number', 'quantity', 'raw_document_id', 'first_loaded_at'];

    public static function bind(QueryBuilder $query, NetTrainingContext $context, NetTrainingRequest $request, NetTrainingLimits $limits): QueryBuilder
    {
        $msk = $context->readAt->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0, 0);
        foreach (['companyId' => $context->companyId, 'accountId' => $context->accountId, 'asOf' => $context->readAt->format('Y-m-d H:i:s.u'), 'epochStart' => $context->epoch->start->format('Y-m-d H:i:s.u'), 'captureVersion' => $context->epoch->captureVersion, 'grossFrom' => $msk->modify('-90 days')->format('Y-m-d'), 'grossTo' => $msk->format('Y-m-d'), 'originFrom' => $context->readAt->modify('-157 days')->format('Y-m-d H:i:s.u'), 'originTo' => $context->readAt->modify('-67 days')->format('Y-m-d H:i:s.u'), 'maxGroups' => $limits->groups, 'groupSentinel' => $limits->groups + 1, 'maxSalesKeys' => $limits->salesKeys, 'saleSentinel' => $limits->salesKeys + 1, 'maxReturnKeys' => $limits->returnKeys, 'returnSentinel' => $limits->returnKeys + 1, 'maxRevisions' => $limits->revisions, 'revisionSentinel' => $limits->revisions + 1, 'maxStatuses' => $limits->statuses, 'statusSentinel' => $limits->statuses + 1, 'maxReceipts' => $limits->receipts, 'receiptSentinel' => $limits->receipts + 1, 'maxKeysPerGroup' => $limits->keysPerGroup, 'maxRevisionsPerSource' => $limits->revisionsPerSource, 'maxStatusesPerGroup' => $limits->statusesPerGroup] as $key => $value) {
            $query->setParameter($key, $value);
        }

        return $query->setParameter('sourceRevisionSentinel', $limits->revisionsPerSource + 1)->setParameter('requestedSkus', $request->marketplaceSkus, ArrayParameterType::STRING);
    }

    /** @param list<string> $columns */
    public static function columns(string $alias, array $columns): string
    {
        return implode(', ', array_map(static fn (string $column): string => $alias.'.'.$column, $columns));
    }

    public static function ctes(bool $returns): string
    {
        $seed = $returns ? <<<'SQL'
            SELECT DISTINCT f.source_row_id
            FROM sales_fact f
            WHERE f.company_id = :companyId AND f.marketplace_account_id = :accountId
              AND (f.posting_number IN (SELECT posting_number FROM candidate_deliveries)
                   OR f.order_number IN (SELECT order_number FROM candidate_deliveries)
                   OR f.source_row_id IN (
                       SELECT r.source_row_id FROM sales_fact_revision r
                       WHERE r.company_id = :companyId AND r.marketplace_account_id = :accountId
                         AND r.order_number IN (SELECT order_number FROM candidate_deliveries)
                   ))
            LIMIT :saleSentinel
            SQL : <<<'SQL'
            SELECT f.source_row_id
            FROM sales_fact f
            WHERE f.company_id = :companyId AND f.marketplace_account_id = :accountId
              AND f.business_date >= CAST(:grossFrom AS date) AND f.business_date < CAST(:grossTo AS date)
            LIMIT :saleSentinel
            SQL;
        $sales = self::columns('r', self::SALES_COLUMNS);
        $returnsColumns = self::columns('r', self::RETURN_COLUMNS);
        $scopeColumns = 'CAST(:companyId AS uuid) AS company_id, CAST(:accountId AS uuid) AS marketplace_account_id, ';
        $latestSales = $scopeColumns.self::columns('r', ['source_row_id', 'business_date', 'status', 'marketplace_sku', 'posting_number', 'order_number', 'quantity', 'captured_at']);
        $latestReturns = $scopeColumns.self::columns('r', ['source_row_id', 'order_number', 'marketplace_sku', 'return_type', 'return_reason_name', 'quantity']);
        $orderedSales = self::columns('r', ['source_row_id', 'fact_revision', 'captured_at', 'capture_version', 'capture_origin', 'capture_path', 'capture_is_initial', 'business_date', 'marketplace_sku', 'posting_number', 'order_number', 'raw_document_id', 'first_loaded_at']);
        $orderedReturns = self::columns('r', ['source_row_id', 'fact_revision', 'captured_at', 'capture_version', 'capture_origin', 'capture_path', 'capture_is_initial', 'order_number', 'marketplace_sku', 'return_type', 'quantity', 'first_loaded_at']);
        $candidateHints = $returns ? 'TRUE' : 'FALSE';

        return <<<SQL
            candidate_deliveries AS MATERIALIZED (
                SELECT s.posting_number, s.order_number
                FROM marketplace_posting_status s
                WHERE s.company_id = :companyId AND s.marketplace_account_id = :accountId
                  AND {$candidateHints}
                  AND s.status = 'delivered' AND s.substatus IN ('posting_delivered', 'posting_received')
                  AND s.observed_at >= CAST(:originFrom AS timestamp) AND s.observed_at < CAST(:originTo AS timestamp)
                LIMIT :statusSentinel
            ),
            seed_keys AS MATERIALIZED (
                {$seed}
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
                SELECT f.order_number FROM sales_fact f JOIN seed_keys k ON k.source_row_id = f.source_row_id
                WHERE f.company_id = :companyId AND f.marketplace_account_id = :accountId AND f.order_number IS NOT NULL
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
                SELECT f.source_row_id, f.fact_revision, f.business_date, f.order_number, f.marketplace_sku, f.posting_number
                FROM sales_fact f JOIN expanded_keys k ON k.source_row_id = f.source_row_id
                WHERE f.company_id = :companyId AND f.marketplace_account_id = :accountId
                LIMIT :saleSentinel
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
            return_keys AS MATERIALIZED (
                SELECT f.source_row_id, f.fact_revision, f.order_number, f.marketplace_sku FROM marketplace_return_fact f
                WHERE f.company_id = :companyId AND f.marketplace_account_id = :accountId
                  AND (f.order_number IN (SELECT order_number FROM all_orders) OR f.source_row_id IN (
                      SELECT r.source_row_id FROM marketplace_return_fact_revision r
                      WHERE r.company_id = :companyId AND r.marketplace_account_id = :accountId
                        AND r.order_number IN (SELECT order_number FROM all_orders)
                  ))
                LIMIT :returnSentinel
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
                  AND (r.report_type = 'ozon_posting_fbo_list' AND EXISTS(SELECT 1 FROM sale_keys k WHERE k.business_date BETWEEN r.date_from AND r.date_to)
                       OR r.report_type = 'ozon_returns_list' AND r.date_to >= (CAST(:originFrom AS timestamp) AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Moscow')::date)
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
            input_metrics AS MATERIALIZED (
                SELECT (SELECT COUNT(source_row_id) FROM seed_keys) AS seed_sales,
                       (SELECT COUNT(source_row_id) FROM seed_history) AS seed_revisions,
                       (SELECT COUNT(source_row_id) FROM historical_order_links) AS historical_revisions,
                       (SELECT COUNT(source_row_id) FROM historical_order_keys) AS historical_sales,
                       (SELECT COUNT(source_row_id) FROM current_order_keys) AS current_order_sales,
                       (SELECT COUNT(source_row_id) FROM expanded_keys) AS expanded_sales,
                       (SELECT COUNT(source_row_id) FROM sale_keys) AS sales,
                       (SELECT COUNT(source_row_id) FROM return_keys) AS returns,
                       (SELECT COUNT(order_key) FROM candidate_groups) AS group_count,
                       (SELECT COUNT(source_row_id) FROM sales_revisions) + (SELECT COUNT(source_row_id) FROM return_revisions) AS revisions,
                       (SELECT COUNT(raw_document_id) FROM status_inputs) AS statuses,
                       (SELECT COUNT(posting_number) FROM candidate_deliveries) AS delivery_hints,
                       (SELECT COUNT(id) FROM receipt_inputs) AS receipts,
                       GREATEST(COALESCE((SELECT MAX(fact_revision) FROM sale_keys), 0), COALESCE((SELECT MAX(fact_revision) FROM return_keys), 0)) AS source_revisions,
                       COALESCE((SELECT MAX(sources) FROM group_key_counts), 0) AS sales_per_group,
                       COALESCE((SELECT MAX(sources) FROM return_key_counts), 0) AS returns_per_group,
                       COALESCE((SELECT MAX(observations) FROM group_status_counts), 0) AS statuses_per_group
            ),
            input_budget AS MATERIALIZED (
                SELECT exceeded.input IS NULL AS ok, exceeded.input, exceeded.observed, exceeded.maximum
                FROM input_metrics m
                LEFT JOIN LATERAL (
                    SELECT v.input, v.observed, v.maximum FROM (VALUES
                        ('seed_sales_keys', m.seed_sales, CAST(:maxSalesKeys AS bigint), 1),
                        ('sales_keys', m.sales, CAST(:maxSalesKeys AS bigint), 2),
                        ('return_keys', m.returns, CAST(:maxReturnKeys AS bigint), 3),
                        ('groups', m.group_count, CAST(:maxGroups AS bigint), 4),
                        ('revisions', m.revisions, CAST(:maxRevisions AS bigint), 5),
                        ('statuses', m.statuses, CAST(:maxStatuses AS bigint), 6),
                        ('delivery_hints', m.delivery_hints, CAST(:maxStatuses AS bigint), 7),
                        ('receipts', m.receipts, CAST(:maxReceipts AS bigint), 8),
                        ('revisions_per_source', m.source_revisions, CAST(:maxRevisionsPerSource AS bigint), 9),
                        ('sales_keys_per_group', m.sales_per_group, CAST(:maxKeysPerGroup AS bigint), 10),
                        ('return_keys_per_group', m.returns_per_group, CAST(:maxKeysPerGroup AS bigint), 11),
                        ('statuses_per_group', m.statuses_per_group, CAST(:maxStatusesPerGroup AS bigint), 12)
                        , ('seed_revisions', m.seed_revisions, CAST(:maxRevisions AS bigint), 13)
                        , ('historical_order_revisions', m.historical_revisions, CAST(:maxRevisions AS bigint), 14)
                        , ('historical_sales_keys', m.historical_sales, CAST(:maxSalesKeys AS bigint), 15)
                        , ('current_order_sales_keys', m.current_order_sales, CAST(:maxSalesKeys AS bigint), 16)
                        , ('expanded_sales_keys', m.expanded_sales, CAST(:maxSalesKeys AS bigint), 17)
                    ) v(input, observed, maximum, priority)
                    WHERE v.observed > v.maximum ORDER BY v.priority LIMIT 1
                ) exceeded ON TRUE
            ),
            sales_ordered AS MATERIALIZED (
                SELECT {$orderedSales}, LAG(r.captured_at) OVER (PARTITION BY r.source_row_id ORDER BY r.fact_revision) AS previous_capture
                FROM sales_revisions r CROSS JOIN input_budget budget WHERE budget.ok
            ),
            return_ordered AS MATERIALIZED (
                SELECT {$orderedReturns}, LAG(r.captured_at) OVER source_order AS previous_capture,
                       LAG(r.return_type) OVER source_order AS previous_type, LAG(r.quantity) OVER source_order AS previous_quantity
                FROM return_revisions r CROSS JOIN input_budget budget WHERE budget.ok
                WINDOW source_order AS (PARTITION BY r.source_row_id ORDER BY r.fact_revision)
            ),
            sales_chain AS MATERIALIZED (
                SELECT r.source_row_id,
                       (MIN(r.fact_revision) = 1 AND MAX(r.fact_revision) = COUNT(r.fact_revision)
                        AND MAX(r.fact_revision) = MAX(k.fact_revision)
                        AND BOOL_OR(r.fact_revision = 1 AND r.capture_is_initial)
                        AND BOOL_AND(r.capture_version = :captureVersion AND r.capture_origin = 'live_polling' AND r.capture_path = 'upsert'
                            AND r.first_loaded_at >= CAST(:epochStart AS timestamp) AND r.first_loaded_at <= r.captured_at
                            AND r.captured_at >= CAST(:epochStart AS timestamp) AND (r.previous_capture IS NULL OR r.captured_at >= r.previous_capture))
                        AND COUNT(DISTINCT (r.business_date, r.order_number, r.marketplace_sku, r.posting_number)) = 1
                        AND COUNT(DISTINCT r.raw_document_id) = COUNT(r.fact_revision)) AS valid,
                       MIN(r.captured_at) AS initial_capture
                FROM sales_ordered r JOIN sale_keys k ON k.source_row_id = r.source_row_id
                GROUP BY r.source_row_id
            ),
            return_chain AS MATERIALIZED (
                SELECT r.source_row_id,
                       BOOL_AND(r.return_type IN ('Cancellation', 'ClientReturn')) AS known_types,
                       (MIN(r.fact_revision) = 1 AND MAX(r.fact_revision) = COUNT(r.fact_revision)
                        AND MAX(r.fact_revision) = MAX(k.fact_revision)
                        AND BOOL_OR(r.fact_revision = 1 AND r.capture_is_initial)
                        AND BOOL_AND(r.capture_version = :captureVersion AND r.capture_origin = 'live_polling' AND r.capture_path = 'upsert'
                            AND r.first_loaded_at >= CAST(:epochStart AS timestamp) AND r.first_loaded_at <= r.captured_at
                            AND r.captured_at >= CAST(:epochStart AS timestamp) AND (r.previous_capture IS NULL OR r.captured_at >= r.previous_capture)
                            AND r.return_type IN ('Cancellation', 'ClientReturn')
                            AND NOT COALESCE(r.previous_type = 'ClientReturn' AND (r.return_type <> 'ClientReturn' OR r.quantity < r.previous_quantity), FALSE))
                        AND COUNT(DISTINCT (r.order_number, r.marketplace_sku)) = 1) AS valid
                FROM return_ordered r JOIN return_keys k ON k.source_row_id = r.source_row_id
                GROUP BY r.source_row_id
            ),
            training_sales AS MATERIALIZED (
                SELECT DISTINCT ON (r.source_row_id) {$latestSales}
                FROM sales_revisions r CROSS JOIN input_budget budget WHERE budget.ok ORDER BY r.source_row_id, r.fact_revision DESC
            ),
            training_returns AS MATERIALIZED (
                SELECT DISTINCT ON (r.source_row_id) {$latestReturns}
                FROM return_revisions r CROSS JOIN input_budget budget WHERE budget.ok ORDER BY r.source_row_id, r.fact_revision DESC
            ),
            training_status AS MATERIALIZED (
                SELECT s.company_id, s.marketplace_account_id, s.posting_number, s.order_number, s.status, s.substatus, s.cancel_reason_id, s.observed_at, s.raw_document_id
                FROM status_inputs s CROSS JOIN input_budget budget WHERE budget.ok
            ),
            posting_current_status AS MATERIALIZED (
                SELECT DISTINCT ON (s.posting_number) s.posting_number, s.order_number, s.status, s.substatus
                FROM training_status s ORDER BY s.posting_number, s.observed_at DESC
            ),
            training_quantity_balances AS MATERIALIZED (
                SELECT s.order_number, s.marketplace_sku,
                       CASE WHEN l.status = 'delivered' THEN 'ClientReturn' ELSE 'Cancellation' END AS return_type,
                       SUM(s.quantity::bigint) AS quantity
                FROM training_sales s JOIN posting_current_status l ON l.posting_number = s.posting_number
                WHERE l.status = 'delivered' AND l.substatus IN ('posting_delivered', 'posting_received')
                   OR l.status = 'cancelled' AND l.substatus = 'posting_canceled'
                GROUP BY s.order_number, s.marketplace_sku, l.status
                UNION ALL
                SELECT r.order_number, r.marketplace_sku, r.return_type, -SUM(r.quantity::bigint) AS quantity
                FROM training_returns r WHERE r.return_type IN ('ClientReturn', 'Cancellation')
                GROUP BY r.order_number, r.marketplace_sku, r.return_type
            ),
            quantity_overflows AS MATERIALIZED (
                SELECT order_number, marketplace_sku, return_type FROM training_quantity_balances
                GROUP BY order_number, marketplace_sku, return_type HAVING SUM(quantity) < 0
            ),
            contradictory_quantity_groups AS MATERIALIZED (
                SELECT DISTINCT order_number, marketplace_sku FROM quantity_overflows
            ),
            posting_sale_identity AS MATERIALIZED (
                SELECT posting_number, COUNT(DISTINCT order_number) AS orders, BOOL_AND(order_number IS NOT NULL) AS complete
                FROM (SELECT posting_number, order_number FROM sale_keys UNION SELECT posting_number, order_number FROM sales_revisions) identities
                GROUP BY posting_number
            ),
            status_anchors AS MATERIALIZED (
                SELECT s.posting_number, s.order_number, s.status, s.substatus, s.cancel_reason_id, s.observed_at, s.raw_document_id,
                       BOOL_OR(r.source_row_id IS NOT NULL AND r.capture_origin = 'live_polling' AND r.capture_path = 'upsert' AND r.capture_version = :captureVersion AND r.captured_at >= s.observed_at) AS anchored,
                       ((s.status = 'awaiting_packaging' AND s.substatus = 'posting_created')
                         OR (s.status = 'awaiting_deliver' AND s.substatus = 'posting_transferring_to_delivery')
                         OR (s.status = 'delivering' AND s.substatus IN ('posting_in_pickup_point', 'posting_on_way_to_city'))
                         OR (s.status = 'delivered' AND s.substatus IN ('posting_delivered', 'posting_received'))
                         OR (s.status = 'cancelled' AND s.substatus = 'posting_canceled')) IS TRUE AS known,
                       s.status IN ('delivered', 'cancelled') AS terminal
                FROM training_status s LEFT JOIN sales_revisions r ON r.posting_number = s.posting_number AND r.raw_document_id = s.raw_document_id AND r.status = s.status
                GROUP BY s.posting_number, s.order_number, s.status, s.substatus, s.cancel_reason_id, s.observed_at, s.raw_document_id
            ),
            status_ties AS MATERIALIZED (
                SELECT posting_number FROM status_anchors GROUP BY posting_number, observed_at
                HAVING COUNT(DISTINCT (status, substatus, cancel_reason_id)) > 1
            ),
            status_summary AS MATERIALIZED (
                SELECT s.posting_number, MIN(s.observed_at) FILTER (WHERE s.terminal) AS first_terminal,
                       MIN(s.observed_at) FILTER (WHERE s.status = 'delivered') AS delivery_origin,
                       MIN(s.observed_at) FILTER (WHERE NOT s.terminal) AS first_nonterminal,
                       MAX(s.observed_at) FILTER (WHERE NOT s.terminal) AS last_nonterminal,
                       BOOL_AND(s.known AND s.anchored) AND COUNT(DISTINCT s.order_number) = 1
                           AND COUNT(DISTINCT s.status) FILTER (WHERE s.terminal) <= 1 AND NOT BOOL_OR(t.posting_number IS NOT NULL) AS valid,
                       COUNT(s.raw_document_id) AS observations
                FROM status_anchors s LEFT JOIN (SELECT DISTINCT posting_number FROM status_ties) t USING (posting_number)
                GROUP BY s.posting_number
            ),
            posting_proof AS MATERIALIZED (
                SELECT p.posting_number, p.first_terminal, p.delivery_origin, p.observations,
                       p.valid AND (p.last_nonterminal IS NULL OR p.first_terminal IS NULL OR p.last_nonterminal <= p.first_terminal) AS valid,
                       p.first_nonterminal < p.first_terminal AS terminal_observed
                FROM status_summary p
            ),
            posting_receipt_dates AS MATERIALIZED (
                SELECT k.business_date, r.marked_at, r.status, r.capture_origin, r.capture_version
                FROM (SELECT DISTINCT business_date FROM sale_keys) k
                JOIN receipt_inputs r ON r.report_type = 'ozon_posting_fbo_list' AND k.business_date BETWEEN r.date_from AND r.date_to
            ),
            posting_receipts AS MATERIALIZED (
                SELECT business_date,
                       MAX(marked_at) FILTER (WHERE status = 'completed' AND capture_origin = 'live_polling' AND capture_version = :captureVersion) AS completed_at,
                       MAX(marked_at) FILTER (WHERE status = 'failed') AS failed_at
                FROM posting_receipt_dates GROUP BY business_date
            ),
            source_initial_receipts AS MATERIALIZED (
                SELECT k.source_row_id, BOOL_OR(d.status = 'completed' AND d.capture_origin = 'live_polling' AND d.capture_version = :captureVersion
                    AND d.marked_at > c.initial_capture AND (p.first_terminal IS NULL OR d.marked_at < p.first_terminal)) AS observed_initial
                FROM sale_keys k JOIN sales_chain c ON c.source_row_id = k.source_row_id
                LEFT JOIN posting_proof p ON p.posting_number = k.posting_number
                LEFT JOIN posting_receipt_dates d ON d.business_date = k.business_date
                GROUP BY k.source_row_id
            ),
            return_group_proof AS MATERIALIZED (
                SELECT links.order_number, links.marketplace_sku, BOOL_AND(COALESCE(c.valid, FALSE)) AS valid,
                       BOOL_OR(c.known_types IS FALSE) AS unknown_type
                FROM (
                    SELECT source_row_id, order_number, marketplace_sku FROM return_keys
                    UNION SELECT source_row_id, order_number, marketplace_sku FROM return_revisions
                ) links LEFT JOIN return_chain c ON c.source_row_id = links.source_row_id
                GROUP BY links.order_number, links.marketplace_sku
            ),
            source_proof AS MATERIALIZED (
                SELECT k.source_row_id, k.business_date, k.order_number, k.marketplace_sku, k.posting_number,
                       COALESCE(c.valid, FALSE) AND COALESCE(p.valid, FALSE) AND k.order_number IS NOT NULL AND k.posting_number IS NOT NULL AND COALESCE(rg.valid, TRUE)
                         AND r.status = current_status.status AND k.order_number = current_status.order_number AND identity.orders = 1 AND identity.complete AS history_valid,
                       d.completed_at > r.captured_at
                         AND d.completed_at > ((k.business_date + 1)::timestamp AT TIME ZONE 'Europe/Moscow' AT TIME ZONE 'UTC')
                         AND (d.failed_at IS NULL OR d.completed_at > d.failed_at) AND COALESCE(i.observed_initial, FALSE) AS postings_complete
                FROM sale_keys k LEFT JOIN sales_chain c ON c.source_row_id = k.source_row_id
                LEFT JOIN training_sales r ON r.source_row_id = k.source_row_id
                LEFT JOIN posting_proof p ON p.posting_number = k.posting_number
                LEFT JOIN posting_receipts d ON d.business_date = k.business_date
                LEFT JOIN source_initial_receipts i ON i.source_row_id = k.source_row_id
                LEFT JOIN return_group_proof rg ON rg.order_number = k.order_number AND rg.marketplace_sku = k.marketplace_sku
                LEFT JOIN posting_current_status current_status ON current_status.posting_number = k.posting_number
                LEFT JOIN posting_sale_identity identity ON identity.posting_number = k.posting_number
            ),
            source_alias_proof AS MATERIALIZED (
                SELECT links.business_date, links.order_number, links.marketplace_sku,
                       BOOL_AND(COALESCE(p.history_valid, FALSE) AND COALESCE(p.postings_complete, FALSE)) AS valid
                FROM (
                    SELECT source_row_id, business_date, order_number, marketplace_sku FROM sale_keys
                    UNION ALL SELECT source_row_id, business_date, order_number, marketplace_sku FROM sales_revisions
                ) links LEFT JOIN source_proof p ON p.source_row_id = links.source_row_id
                GROUP BY links.business_date, links.order_number, links.marketplace_sku
            ),
            source_order_proof AS MATERIALIZED (
                SELECT order_number, marketplace_sku, BOOL_AND(valid) AS valid
                FROM source_alias_proof GROUP BY order_number, marketplace_sku
            )
            SQL;
    }
}
