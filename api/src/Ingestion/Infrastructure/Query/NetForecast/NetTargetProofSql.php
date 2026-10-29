<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

/** Proofs over bounded current inputs; no training selection or target maturity filter. */
final class NetTargetProofSql
{
    public static function ctes(): string
    {
        return <<<'SQL'
            sales_ordered AS MATERIALIZED (
                SELECT r.source_row_id, r.fact_revision, r.captured_at, r.capture_version, r.capture_origin, r.capture_path, r.capture_is_initial, r.business_date, r.marketplace_sku, r.posting_number, r.order_number, r.raw_document_id, r.first_loaded_at, LAG(r.captured_at) OVER (PARTITION BY r.source_row_id ORDER BY r.fact_revision) AS previous_capture
                FROM sales_revisions r CROSS JOIN input_budget budget WHERE budget.ok
            ),
            return_ordered AS MATERIALIZED (
                SELECT r.source_row_id, r.fact_revision, r.captured_at, r.capture_version, r.capture_origin, r.capture_path, r.capture_is_initial, r.order_number, r.marketplace_sku, r.return_type, r.quantity, r.first_loaded_at, LAG(r.captured_at) OVER source_order AS previous_capture,
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
                       BOOL_AND(NOT COALESCE(r.previous_type = 'ClientReturn' AND (r.return_type <> 'ClientReturn' OR r.quantity < r.previous_quantity), FALSE)) AS curve_valid,
                       (MIN(r.fact_revision) = 1 AND MAX(r.fact_revision) = COUNT(r.fact_revision)
                        AND MAX(r.fact_revision) = MAX(k.fact_revision)
                        AND BOOL_OR(r.fact_revision = 1 AND r.capture_is_initial)
                        AND BOOL_AND(r.capture_version = :captureVersion AND r.capture_origin = 'live_polling' AND r.capture_path = 'upsert'
                            AND r.first_loaded_at >= CAST(:epochStart AS timestamp) AND r.first_loaded_at <= r.captured_at
                            AND r.captured_at >= CAST(:epochStart AS timestamp) AND (r.previous_capture IS NULL OR r.captured_at >= r.previous_capture))
                        AND COUNT(DISTINCT (r.order_number, r.marketplace_sku)) = 1) AS valid
                FROM return_ordered r JOIN return_keys k ON k.source_row_id = r.source_row_id
                GROUP BY r.source_row_id
            ),
            target_latest_sales AS MATERIALIZED (
                SELECT DISTINCT ON (r.source_row_id) CAST(:companyId AS uuid) AS company_id, CAST(:accountId AS uuid) AS marketplace_account_id, r.source_row_id, r.fact_revision, r.id, r.business_date, r.status, r.marketplace_sku, r.posting_number, r.order_number, r.quantity, r.captured_at, r.raw_document_id, r.row_hash, r.amount_minor, r.currency
                FROM sales_revisions r CROSS JOIN input_budget budget WHERE budget.ok ORDER BY r.source_row_id, r.fact_revision DESC
            ),
            target_latest_returns AS MATERIALIZED (
                SELECT DISTINCT ON (r.source_row_id) CAST(:companyId AS uuid) AS company_id, CAST(:accountId AS uuid) AS marketplace_account_id, r.source_row_id, r.fact_revision, r.id, r.order_number, r.marketplace_sku, r.return_type, r.return_reason_name, r.posting_number, r.quantity, r.captured_at, r.raw_document_id, r.row_hash
                FROM return_revisions r CROSS JOIN input_budget budget WHERE budget.ok ORDER BY r.source_row_id, r.fact_revision DESC
            ),
            training_status AS MATERIALIZED (
                SELECT s.company_id, s.marketplace_account_id, s.posting_number, s.order_number, s.status, s.substatus, s.cancel_reason_id, s.observed_at, s.raw_document_id
                FROM status_inputs s CROSS JOIN input_budget budget WHERE budget.ok
            ),
            training_sales AS MATERIALIZED (
                SELECT company_id, marketplace_account_id, source_row_id, posting_number, order_number, marketplace_sku, quantity, business_date
                FROM target_latest_sales
            ),
            training_returns AS MATERIALIZED (
                SELECT company_id, marketplace_account_id, order_number, marketplace_sku, quantity, return_type, return_reason_name
                FROM target_latest_returns
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
                FROM (SELECT posting_number, order_number FROM sale_keys UNION ALL SELECT posting_number, order_number FROM sales_revisions) identities
                GROUP BY posting_number
            ),
            status_anchors AS MATERIALIZED (
                SELECT s.posting_number, s.order_number, s.status, s.substatus, s.cancel_reason_id, s.observed_at, s.raw_document_id,
                       BOOL_OR(r.source_row_id IS NOT NULL AND r.capture_origin = 'live_polling' AND r.capture_path = 'upsert' AND r.capture_version = :captureVersion AND r.captured_at >= s.observed_at AND r.order_number = s.order_number) AS anchored,
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
                       BOOL_AND(s.known) AS known,
                       BOOL_AND(s.anchored) AND COUNT(DISTINCT s.order_number) = 1
                           AND COUNT(DISTINCT s.status) FILTER (WHERE s.terminal) <= 1 AND NOT BOOL_OR(t.posting_number IS NOT NULL) AS valid,
                       COUNT(s.raw_document_id) AS observations
                FROM status_anchors s LEFT JOIN (SELECT DISTINCT posting_number FROM status_ties) t USING (posting_number)
                GROUP BY s.posting_number
            ),
            posting_proof AS MATERIALIZED (
                SELECT p.posting_number, p.first_terminal, p.delivery_origin, p.observations, p.known,
                       p.valid AND (p.last_nonterminal IS NULL OR p.first_terminal IS NULL OR p.last_nonterminal < p.first_terminal) AS valid,
                       p.first_nonterminal < p.first_terminal AS terminal_observed
                FROM status_summary p
            ),
            posting_receipt_dates AS MATERIALIZED (
                SELECT k.business_date, r.id, r.marked_at, r.status, r.capture_origin, r.capture_version
                FROM (SELECT DISTINCT business_date FROM sale_keys UNION SELECT day::date FROM generate_series(CAST(:targetFrom AS timestamp), CAST(:targetTo AS timestamp), INTERVAL '1 day') day) k
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
            target_alias_links AS MATERIALIZED (
                SELECT source_row_id, business_date, order_number, marketplace_sku, posting_number FROM sale_keys
                UNION ALL SELECT source_row_id, business_date, order_number, marketplace_sku, posting_number FROM sales_revisions
            ),
            target_return_proof AS MATERIALIZED (
                SELECT k.source_row_id, COALESCE(c.valid, FALSE)
                    AND ROW(k.fact_revision, k.order_number, k.marketplace_sku, k.quantity, k.return_type, k.return_reason_name, k.raw_document_id, k.row_hash)
                      IS NOT DISTINCT FROM ROW(r.fact_revision, r.order_number, r.marketplace_sku, r.quantity, r.return_type, r.return_reason_name, r.raw_document_id, r.row_hash) AS valid
                FROM return_keys k LEFT JOIN return_chain c USING (source_row_id) LEFT JOIN target_latest_returns r USING (source_row_id)
            ),
            target_closure_proof AS MATERIALIZED (
                SELECT NOT EXISTS (
                    SELECT order_number FROM target_alias_links WHERE order_number IS NOT NULL
                    EXCEPT SELECT order_number FROM seed_orders
                ) AND NOT EXISTS (
                    SELECT 1 FROM (
                        SELECT DISTINCT source_row_id, business_date, order_number, marketplace_sku, posting_number FROM target_alias_links
                    ) identities GROUP BY source_row_id HAVING COUNT(source_row_id) <> 1
                ) AND NOT EXISTS (
                    SELECT order_number FROM return_revisions WHERE order_number IS NOT NULL
                    EXCEPT SELECT order_number FROM seed_orders
                ) AND NOT EXISTS (
                    SELECT 1 FROM (
                        SELECT DISTINCT source_row_id, order_number, marketplace_sku FROM return_revisions
                    ) identities GROUP BY source_row_id HAVING COUNT(source_row_id) <> 1
                ) AND (SELECT COUNT(source_row_id) FROM expanded_keys) = (SELECT COUNT(source_row_id) FROM sale_keys)
                  AND (SELECT COUNT(source_row_id) FROM sale_keys) = (SELECT COUNT(source_row_id) FROM target_latest_sales)
                  AND (SELECT COUNT(source_row_id) FROM return_keys) = (SELECT COUNT(source_row_id) FROM target_latest_returns) AND NOT EXISTS (
                      SELECT source_row_id FROM return_historical_links EXCEPT SELECT source_row_id FROM return_keys
                  ) AND NOT EXISTS (SELECT 1 FROM target_return_proof p WHERE NOT COALESCE(p.valid, FALSE)) AS valid
            ),
            target_current_returns AS MATERIALIZED (
                SELECT MAX(marked_at) FILTER (WHERE status = 'completed' AND capture_origin = 'live_polling' AND capture_version = :captureVersion) AS completed_at,
                       MAX(marked_at) FILTER (WHERE status = 'failed') AS failed_at
                FROM receipt_inputs WHERE report_type = 'ozon_returns_list'
                  AND (CAST(:asOf AS timestamp) AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Moscow')::date BETWEEN date_from AND date_to
            ),
            target_input_latest AS MATERIALIZED (
                SELECT MAX(at) AS at FROM (
                    SELECT captured_at AS at FROM sales_revisions UNION ALL SELECT captured_at FROM return_revisions UNION ALL SELECT observed_at FROM status_inputs
                ) captures
            ),
            target_source_proof AS MATERIALIZED (
                SELECT k.source_row_id,
                       COALESCE(c.valid, FALSE) AND COALESCE(p.valid, FALSE) AND k.order_number IS NOT NULL AND k.posting_number IS NOT NULL
                         AND ROW(k.fact_revision, k.business_date, k.order_number, k.marketplace_sku, k.posting_number, k.quantity, k.status, k.raw_document_id, k.row_hash)
                           IS NOT DISTINCT FROM ROW(r.fact_revision, r.business_date, r.order_number, r.marketplace_sku, r.posting_number, r.quantity, r.status, r.raw_document_id, r.row_hash)
                         AND r.status = current_status.status AND k.order_number = current_status.order_number
                         AND identity.orders = 1 AND identity.complete AS history_valid,
                       COALESCE(d.completed_at > r.captured_at AND d.completed_at > latest.observed_at
                         AND (d.failed_at IS NULL OR d.completed_at > d.failed_at), FALSE) AS postings_complete,
                       CASE WHEN p.known AND p.terminal_observed AND COALESCE(i.observed_initial, FALSE) THEN p.delivery_origin ELSE NULL END AS delivery_origin,
                       p.known AS status_known, c.initial_capture
                FROM sale_keys k LEFT JOIN sales_chain c USING (source_row_id)
                LEFT JOIN target_latest_sales r USING (source_row_id)
                LEFT JOIN posting_proof p ON p.posting_number = k.posting_number
                LEFT JOIN posting_receipts d ON d.business_date = k.business_date
                LEFT JOIN source_initial_receipts i ON i.source_row_id = k.source_row_id
                LEFT JOIN posting_current_status current_status ON current_status.posting_number = k.posting_number
                LEFT JOIN posting_sale_identity identity ON identity.posting_number = k.posting_number
                LEFT JOIN (SELECT posting_number, MAX(observed_at) AS observed_at FROM status_inputs GROUP BY posting_number) latest ON latest.posting_number = k.posting_number
            ),
            target_order_proof AS MATERIALIZED (
                SELECT k.order_number, BOOL_AND(COALESCE(p.history_valid, FALSE)) AS history_valid,
                       BOOL_AND(COALESCE(p.postings_complete, FALSE)) AS postings_complete,
                       BOOL_AND(COALESCE(p.status_known, FALSE)) AS status_known
                FROM sale_keys k LEFT JOIN target_source_proof p USING (source_row_id) GROUP BY k.order_number
            ),
            target_delivery_sources AS MATERIALIZED (
                SELECT DISTINCT ON (r.source_row_id) r.source_row_id, r.order_number, r.marketplace_sku, r.quantity, r.fact_revision, r.captured_at
                FROM sales_revisions r JOIN status_anchors s ON s.posting_number = r.posting_number AND s.raw_document_id = r.raw_document_id
                  AND s.status = 'delivered' AND s.known AND s.anchored
                ORDER BY r.source_row_id, r.fact_revision
            ),
            target_changed_delivery AS MATERIALIZED (
                SELECT DISTINCT r.source_row_id FROM sales_revisions r JOIN target_delivery_sources d USING (source_row_id)
                WHERE r.fact_revision > d.fact_revision AND r.quantity <> d.quantity
            ),
            target_origins AS MATERIALIZED (
                SELECT r.source_row_id, r.order_number, r.marketplace_sku, r.quantity, p.delivery_origin AS origin,
                       FLOOR(EXTRACT(EPOCH FROM (CAST(:asOf AS timestamp) - p.delivery_origin)) / 86400)::int AS age
                FROM target_latest_sales r JOIN target_source_proof p USING (source_row_id)
                WHERE r.status = 'delivered' AND p.delivery_origin IS NOT NULL
            ),
            target_required_windows AS MATERIALIZED (
                SELECT DISTINCT origin,
                       (origin AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Moscow')::date AS day_from,
                       CASE WHEN age < 60 THEN (CAST(:asOf AS timestamp) AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Moscow')::date - 1
                            ELSE ((origin + INTERVAL '67 days') AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Moscow')::date END AS day_to
                FROM target_origins
            ),
            target_calendar_dates AS MATERIALIZED (
                SELECT DISTINCT day::date AS day FROM target_required_windows w
                CROSS JOIN LATERAL generate_series(w.day_from::timestamp, LEAST(w.day_to, w.day_from + 67)::timestamp, INTERVAL '1 day') day
            ),
            target_receipt_calendar AS MATERIALIZED (
                SELECT d.day,
                       MAX(r.marked_at) FILTER (WHERE r.status = 'completed' AND r.date_from = d.day AND r.date_to = d.day AND r.capture_origin = 'live_polling' AND r.capture_version = :captureVersion) AS latest_completed,
                       MAX(r.marked_at) FILTER (WHERE r.status = 'failed') AS latest_failed,
                       MAX(r.marked_at) FILTER (WHERE r.status = 'completed' AND r.date_from = d.day AND r.date_to = d.day AND r.capture_origin = 'live_polling' AND r.capture_version = :captureVersion
                         AND r.marked_at >= ((d.day + 1)::timestamp AT TIME ZONE 'Europe/Moscow' AT TIME ZONE 'UTC')
                         AND r.marked_at <= ((d.day + 2)::timestamp AT TIME ZONE 'Europe/Moscow' AT TIME ZONE 'UTC')) AS timely_completed,
                       MAX(r.marked_at) FILTER (WHERE r.status = 'failed' AND r.marked_at <= ((d.day + 2)::timestamp AT TIME ZONE 'Europe/Moscow' AT TIME ZONE 'UTC')) AS timely_failed,
                       MD5(COALESCE(STRING_AGG(r.id::text, ',' ORDER BY r.id) FILTER (WHERE r.id IS NOT NULL), '')) AS witness_digest
                FROM target_calendar_dates d LEFT JOIN receipt_inputs r ON r.report_type = 'ozon_returns_list' AND r.date_from <= d.day AND r.date_to >= d.day
                GROUP BY d.day
            ),
            target_daily_proof AS MATERIALIZED (
                SELECT w.origin, w.day_from, w.day_to,
                       CASE WHEN w.day_to < w.day_from THEN TRUE ELSE
                           COUNT(c.day) = w.day_to - w.day_from + 1 AND BOOL_AND(c.timely_completed IS NOT NULL
                             AND (c.timely_failed IS NULL OR c.timely_completed > c.timely_failed)
                             AND (c.latest_failed IS NULL OR c.latest_completed > c.latest_failed)) END AS valid,
                       MAX(c.latest_completed) AS coverage_through,
                       MD5(COALESCE(STRING_AGG(c.day::text || ':' || c.witness_digest, ',' ORDER BY c.day), '')) AS witness_digest
                FROM target_required_windows w LEFT JOIN target_receipt_calendar c ON c.day BETWEEN w.day_from AND w.day_to
                GROUP BY w.origin, w.day_from, w.day_to
            ),
            target_deep_proof AS MATERIALIZED (
                SELECT w.origin, MAX(r.marked_at) AS completed_at
                FROM target_required_windows w LEFT JOIN receipt_inputs r
                  ON r.report_type = 'ozon_returns_list' AND r.status = 'completed' AND r.capture_origin = 'live_polling' AND r.capture_version = :captureVersion
                 AND r.date_from <= w.day_from
                 AND r.date_to >= ((w.origin + INTERVAL '60 days') AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Moscow')::date
                 AND r.marked_at >= w.origin + INTERVAL '67 days'
                GROUP BY w.origin
            ),
            target_readiness AS MATERIALIZED (
                SELECT o.source_row_id, o.origin, o.age,
                       d.valid AS daily_complete, deep.completed_at AS deep_completed_at,
                       LEAST(o.origin, d.day_from::timestamp AT TIME ZONE 'Europe/Moscow' AT TIME ZONE 'UTC') AS coverage_from,
                       LEAST(CAST(:asOf AS timestamp), d.coverage_through) AS coverage_through,
                       d.witness_digest
                FROM target_origins o JOIN target_daily_proof d USING (origin) LEFT JOIN target_deep_proof deep USING (origin)
            ),
            target_client_deltas AS MATERIALIZED (
                SELECT r.source_row_id, r.order_number, r.marketplace_sku, r.captured_at,
                       CASE WHEN r.return_type = 'ClientReturn' THEN CASE WHEN r.previous_type = 'ClientReturn' THEN r.quantity::bigint - r.previous_quantity ELSE r.quantity::bigint END ELSE 0::bigint END AS quantity
                FROM return_ordered r
            ),
            target_group_origins AS MATERIALIZED (
                SELECT s.order_number, s.marketplace_sku, COUNT(DISTINCT o.origin) AS origins, MIN(o.origin) AS origin,
                       BOOL_OR(o.origin IS NULL) AS unknown_origin, SUM(s.quantity::bigint) AS delivered_quantity
                FROM target_latest_sales s LEFT JOIN target_origins o USING (source_row_id)
                WHERE s.status = 'delivered' GROUP BY s.order_number, s.marketplace_sku
            ),
            target_group_returns AS MATERIALIZED (
                SELECT r.order_number, r.marketplace_sku,
                       BOOL_AND(r.quantity > 0) AS quantity_valid,
                       BOOL_AND(chain.known_types AND (r.return_type = 'ClientReturn' OR r.return_type = 'Cancellation' AND c.event_stage IS NOT NULL)) AS type_known,
                       BOOL_AND(chain.curve_valid) AS curve_valid,
                       BOOL_AND(COALESCE(p.valid, FALSE)) AS history_valid,
                       COALESCE(SUM(r.quantity::bigint) FILTER (WHERE r.return_type = 'ClientReturn'), 0) AS client_quantity
                FROM target_latest_returns r LEFT JOIN target_return_proof p USING (source_row_id)
                LEFT JOIN return_chain chain USING (source_row_id)
                LEFT JOIN ozon_return_reason_classification c ON c.return_type = r.return_type AND c.return_reason_name = r.return_reason_name
                GROUP BY r.order_number, r.marketplace_sku
            ),
            target_terminal_candidates AS MATERIALIZED (
                SELECT order_number, marketplace_sku
                FROM (
                    SELECT order_number, marketplace_sku, delivered_quantity AS balance, TRUE AS multiple_origins
                    FROM target_group_origins WHERE origins > 1 OR unknown_origin
                    UNION ALL
                    SELECT order_number, marketplace_sku, -client_quantity, FALSE FROM target_group_returns
                ) balances GROUP BY order_number, marketplace_sku
                HAVING BOOL_OR(multiple_origins) AND SUM(balance) = 0
            ),
            target_capacity_sources AS MATERIALIZED (
                -- An initial-delivered capture is only a capacity witness, never a model origin.
                SELECT source_row_id, order_number, marketplace_sku, MAX(quantity)::bigint AS quantity,
                       COALESCE(MIN(origin), MIN(captured_at)) AS at
                FROM (
                    SELECT source_row_id, order_number, marketplace_sku, quantity, origin, NULL::timestamp AS captured_at FROM target_origins
                    UNION ALL
                    SELECT source_row_id, order_number, marketplace_sku, quantity, NULL::timestamp, captured_at FROM target_delivery_sources
                ) witnesses GROUP BY source_row_id, order_number, marketplace_sku
            ),
            target_capacity_events AS MATERIALIZED (
                SELECT order_number, marketplace_sku, at, event_rank, quantity,
                       BOOL_OR(candidate) OVER (PARTITION BY order_number, marketplace_sku) AS candidate
                FROM (
                    SELECT order_number, marketplace_sku, at, 1 AS event_rank, quantity, FALSE AS candidate FROM target_capacity_sources
                    UNION ALL
                    SELECT order_number, marketplace_sku, captured_at, 0, -quantity, FALSE FROM target_client_deltas WHERE quantity > 0
                    UNION ALL
                    SELECT order_number, marketplace_sku, NULL::timestamp, 0, 0::bigint, TRUE FROM target_terminal_candidates
                ) events
            ),
            target_capacity_prefix AS MATERIALIZED (
                -- Return peers precede delivery peers; equal time supplies no ordering proof.
                SELECT order_number, marketplace_sku,
                       SUM(quantity) OVER (PARTITION BY order_number, marketplace_sku ORDER BY at, event_rank
                           RANGE BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS available
                FROM target_capacity_events WHERE candidate AND at IS NOT NULL
            ),
            target_capacity_proof AS MATERIALIZED (
                SELECT order_number, marketplace_sku, BOOL_AND(available >= 0) AS valid
                FROM target_capacity_prefix GROUP BY order_number, marketplace_sku
            ),
            target_origin_delta_proof AS MATERIALIZED (
                SELECT d.order_number, d.marketplace_sku,
                       BOOL_AND(d.quantity >= 0 AND (d.quantity = 0 OR o.origin IS NULL OR d.captured_at > o.origin)) AS valid
                FROM target_client_deltas d LEFT JOIN target_group_origins o USING (order_number, marketplace_sku)
                GROUP BY d.order_number, d.marketplace_sku
            ),
            target_delta_proof AS MATERIALIZED (
                SELECT order_number, marketplace_sku, BOOL_AND(valid) AS valid
                FROM (
                    SELECT order_number, marketplace_sku, valid FROM target_origin_delta_proof
                    UNION ALL
                    SELECT order_number, marketplace_sku, valid FROM target_capacity_proof
                ) proofs GROUP BY order_number, marketplace_sku
            )
            SQL;
    }
}
