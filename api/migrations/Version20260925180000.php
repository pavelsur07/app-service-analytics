<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-030: buyout_outcome на прошлую дату — для проверки прогноза выкупа.
 * Текст совпадает с view Version20260925150000 с точностью до трёх
 * источников «на дату» с фильтром компании внутри; равенство функции
 * на бесконечности и view проверяет интеграционный тест.
 */
final class Version20260925180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add point-in-time buyout_outcome_as_of(company, as_of) for forecast backtesting';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE FUNCTION buyout_outcome_as_of(p_company_id uuid, p_as_of timestamp)
            RETURNS TABLE (
                company_id uuid,
                marketplace_account_id uuid,
                source_row_id text,
                posting_number varchar,
                order_number varchar,
                marketplace_sku varchar,
                quantity integer,
                business_date date,
                outcome varchar,
                handed_over_at timestamp,
                resolved_at timestamp,
                is_forecast_eligible boolean,
                resolution_observed boolean,
                is_in_flight boolean
            )
            LANGUAGE sql
            STABLE
            AS $$
            WITH status_as_of AS NOT MATERIALIZED (
                SELECT *
                FROM marketplace_posting_status
                WHERE company_id = p_company_id
                  AND observed_at <= p_as_of
            ),
            returns_as_of AS NOT MATERIALIZED (
                SELECT *
                FROM marketplace_return_fact
                WHERE company_id = p_company_id
                  AND first_loaded_at <= p_as_of
            ),
            sales_as_of AS NOT MATERIALIZED (
                SELECT *
                FROM sales_fact
                WHERE company_id = p_company_id
                  AND first_loaded_at <= p_as_of
            ),
            latest_status AS NOT MATERIALIZED (
                SELECT DISTINCT ON (company_id, marketplace_account_id, posting_number)
                       company_id, marketplace_account_id, posting_number,
                       status, substatus, cancel_reason_id, observed_at, raw_document_id
                FROM status_as_of
                ORDER BY company_id, marketplace_account_id, posting_number,
                         observed_at DESC, raw_document_id DESC
            ),
            terminal_times AS NOT MATERIALIZED (
                SELECT DISTINCT ON (company_id, marketplace_account_id, posting_number)
                       company_id, marketplace_account_id, posting_number,
                       observed_at AS resolved_at,
                       raw_document_id AS resolved_raw_document_id
                FROM status_as_of
                WHERE (status = 'delivered' AND substatus IN ('posting_delivered', 'posting_received'))
                   OR (status = 'cancelled' AND substatus = 'posting_canceled')
                ORDER BY company_id, marketplace_account_id, posting_number,
                         observed_at, raw_document_id
            ),
            status_evidence AS NOT MATERIALIZED (
                SELECT s.company_id, s.marketplace_account_id, s.posting_number,
                       MIN(s.observed_at) FILTER (
                           WHERE s.status = 'delivering'
                             AND s.substatus IN ('posting_in_pickup_point', 'posting_on_way_to_city')
                             AND (
                                 t.resolved_at IS NULL
                                 OR (s.observed_at, s.raw_document_id) < (t.resolved_at, t.resolved_raw_document_id)
                             )
                       ) AS handed_over_at,
                       t.resolved_at,
                       COALESCE(BOOL_OR(
                           ((s.status = 'awaiting_packaging' AND s.substatus = 'posting_created')
                             OR (s.status = 'awaiting_deliver' AND s.substatus = 'posting_transferring_to_delivery'))
                           AND t.resolved_at IS NOT NULL
                           AND (s.observed_at, s.raw_document_id) < (t.resolved_at, t.resolved_raw_document_id)
                       ), FALSE) AS has_pre_handover_observation,
                                   COALESCE(BOOL_OR(
                                       t.resolved_at IS NOT NULL
                                       AND (s.observed_at, s.raw_document_id) < (t.resolved_at, t.resolved_raw_document_id)
                                   ), FALSE) AS resolution_observed
                FROM status_as_of s
                LEFT JOIN terminal_times t
                  ON t.company_id = s.company_id
                 AND t.marketplace_account_id = s.marketplace_account_id
                 AND t.posting_number = s.posting_number
                GROUP BY s.company_id, s.marketplace_account_id, s.posting_number,
                         t.resolved_at, t.resolved_raw_document_id
            ),
            return_evidence AS NOT MATERIALIZED (
                SELECT r.company_id, r.marketplace_account_id, r.order_number, r.marketplace_sku,
                       COALESCE(SUM(r.quantity), 0)::bigint AS return_quantity,
                       COALESCE(SUM(r.quantity) FILTER (WHERE r.return_type = 'ClientReturn'), 0)::bigint AS client_return_quantity,
                       COALESCE(SUM(r.quantity) FILTER (WHERE r.return_type = 'Cancellation' AND c.event_stage = 'HANDOVER_REFUSAL'), 0)::bigint AS handover_refusal_quantity,
                       COALESCE(SUM(r.quantity) FILTER (WHERE r.return_type = 'Cancellation' AND c.event_stage = 'PICKUP_EXPIRED'), 0)::bigint AS pickup_expired_quantity,
                       COALESCE(SUM(r.quantity) FILTER (WHERE r.return_type = 'Cancellation' AND c.event_stage = 'DELIVERY_FAILED'), 0)::bigint AS delivery_failed_quantity,
                       COALESCE(SUM(r.quantity) FILTER (WHERE r.return_type = 'Cancellation' AND c.event_stage = 'CANCELLED'), 0)::bigint AS cancelled_quantity
                FROM returns_as_of r
                LEFT JOIN ozon_return_reason_classification c
                  ON c.return_type = r.return_type
                 AND c.return_reason_name = r.return_reason_name
                GROUP BY r.company_id, r.marketplace_account_id, r.order_number, r.marketplace_sku
            ),
            sales_evidence AS NOT MATERIALIZED (
                SELECT s.company_id, s.marketplace_account_id, s.source_row_id,
                       s.posting_number, s.order_number, s.marketplace_sku,
                       s.quantity, s.business_date,
                       l.status, l.substatus, l.cancel_reason_id,
                       COALESCE(
                           (l.status = 'awaiting_packaging' AND l.substatus = 'posting_created')
                           OR (l.status = 'awaiting_deliver' AND l.substatus = 'posting_transferring_to_delivery')
                           OR (l.status = 'delivering' AND l.substatus IN ('posting_in_pickup_point', 'posting_on_way_to_city'))
                           OR (l.status = 'delivered' AND l.substatus IN ('posting_delivered', 'posting_received'))
                           OR (l.status = 'cancelled' AND l.substatus = 'posting_canceled'),
                           FALSE
                       ) AS status_is_known,
                       COALESCE(
                           (l.status = 'awaiting_packaging' AND l.substatus = 'posting_created')
                           OR (l.status = 'awaiting_deliver' AND l.substatus = 'posting_transferring_to_delivery')
                           OR (l.status = 'delivering' AND l.substatus IN ('posting_in_pickup_point', 'posting_on_way_to_city')),
                           FALSE
                       ) AS is_active_pending,
                       e.handed_over_at, e.resolved_at, e.has_pre_handover_observation,
                                   e.resolution_observed
                FROM sales_as_of s
                LEFT JOIN latest_status l
                  ON l.company_id = s.company_id
                 AND l.marketplace_account_id = s.marketplace_account_id
                 AND l.posting_number = s.posting_number
                LEFT JOIN status_evidence e
                  ON e.company_id = s.company_id
                 AND e.marketplace_account_id = s.marketplace_account_id
                 AND e.posting_number = s.posting_number
            ),
            base_with_return_totals AS NOT MATERIALIZED (
                SELECT s.*,
                       COALESCE(r.return_quantity, 0) AS return_quantity_total,
                       COALESCE(r.client_return_quantity, 0) AS client_return_quantity_total,
                       COALESCE(r.handover_refusal_quantity, 0) AS handover_refusal_quantity_total,
                       COALESCE(r.pickup_expired_quantity, 0) AS pickup_expired_quantity_total,
                       COALESCE(r.delivery_failed_quantity, 0) AS delivery_failed_quantity_total,
                       COALESCE(r.cancelled_quantity, 0) AS cancelled_quantity_total
                FROM sales_evidence s
                LEFT JOIN return_evidence r
                  ON r.company_id = s.company_id
                 AND r.marketplace_account_id = s.marketplace_account_id
                 AND r.order_number = s.order_number
                 AND r.marketplace_sku = s.marketplace_sku
            ),
            positioned AS NOT MATERIALIZED (
                SELECT b.*,
                       CASE
                           WHEN b.order_number IS NOT NULL
                            AND b.status_is_known
                            AND b.status IN ('delivered', 'cancelled')
                           THEN COALESCE(SUM(b.quantity::bigint) FILTER (
                               WHERE b.status_is_known AND b.status = 'delivered'
                           ) OVER allocation_prefix, 0)
                           ELSE 0::bigint
                       END AS delivered_quantity_before,
                       CASE
                           WHEN b.order_number IS NOT NULL
                            AND b.status_is_known
                            AND b.status IN ('delivered', 'cancelled')
                           THEN COALESCE(SUM(b.quantity::bigint) FILTER (
                               WHERE b.status_is_known AND b.status = 'cancelled'
                           ) OVER allocation_prefix, 0)
                           ELSE 0::bigint
                       END AS cancelled_quantity_before,
                       CASE
                           WHEN b.order_number IS NOT NULL
                            AND b.status_is_known
                            AND b.status IN ('delivered', 'cancelled')
                           THEN COALESCE(BOOL_AND(b.handed_over_at IS NOT NULL) FILTER (
                               WHERE b.status_is_known AND b.status = 'cancelled'
                           ) OVER allocation_partition, FALSE)
                           ELSE FALSE
                       END AS all_cancelled_handed_over,
                       CASE
                           WHEN b.order_number IS NOT NULL
                            AND b.status_is_known
                            AND b.status IN ('delivered', 'cancelled')
                           THEN COALESCE(BOOL_AND(
                               b.handed_over_at IS NULL
                               AND b.has_pre_handover_observation
                           ) FILTER (
                               WHERE b.status_is_known AND b.status = 'cancelled'
                           ) OVER allocation_partition, FALSE)
                           ELSE FALSE
                       END AS all_cancelled_pre_handover,
                       CASE WHEN b.order_number IS NOT NULL THEN COALESCE(BOOL_OR(
                           b.status_is_known AND b.status = 'delivered'
                       ) OVER order_partition, FALSE) ELSE FALSE END AS order_has_delivered,
                       CASE WHEN b.order_number IS NOT NULL THEN COALESCE(BOOL_AND(
                           b.status_is_known AND b.status IN ('delivered', 'cancelled')
                       ) OVER order_partition, FALSE) ELSE FALSE END AS order_all_terminal
                FROM base_with_return_totals b
                WINDOW
                    allocation_partition AS (
                        PARTITION BY b.company_id, b.marketplace_account_id,
                                     b.order_number, b.marketplace_sku
                    ),
                    allocation_prefix AS (
                        PARTITION BY b.company_id, b.marketplace_account_id,
                                     b.order_number, b.marketplace_sku
                        ORDER BY b.business_date, COALESCE(b.posting_number, ''), b.source_row_id
                        ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING
                    ),
                    order_partition AS (
                        PARTITION BY b.company_id, b.marketplace_account_id, b.order_number
                    )
            ),
            base AS NOT MATERIALIZED (
                SELECT p.*,
                       CASE WHEN p.status_is_known AND p.status = 'delivered' THEN
                           GREATEST(0::bigint,
                               LEAST(p.delivered_quantity_before + p.quantity::bigint, p.client_return_quantity_total)
                               - p.delivered_quantity_before
                           ) ELSE 0::bigint END AS client_return_quantity,
                       CASE WHEN p.status_is_known AND p.status = 'cancelled' THEN
                           GREATEST(0::bigint,
                               LEAST(p.cancelled_quantity_before + p.quantity::bigint, p.handover_refusal_quantity_total)
                               - p.cancelled_quantity_before
                           ) ELSE 0::bigint END AS handover_refusal_quantity,
                       CASE WHEN p.status_is_known AND p.status = 'cancelled' THEN
                           GREATEST(0::bigint,
                               LEAST(
                                   p.cancelled_quantity_before + p.quantity::bigint,
                                   p.handover_refusal_quantity_total + p.pickup_expired_quantity_total
                               ) - GREATEST(p.cancelled_quantity_before, p.handover_refusal_quantity_total)
                           ) ELSE 0::bigint END AS pickup_expired_quantity,
                       CASE WHEN p.status_is_known AND p.status = 'cancelled' THEN
                           GREATEST(0::bigint,
                               LEAST(
                                   p.cancelled_quantity_before + p.quantity::bigint,
                                   p.handover_refusal_quantity_total + p.pickup_expired_quantity_total + p.delivery_failed_quantity_total
                               ) - GREATEST(
                                   p.cancelled_quantity_before,
                                   p.handover_refusal_quantity_total + p.pickup_expired_quantity_total
                               )
                           ) ELSE 0::bigint END AS delivery_failed_quantity,
                       CASE WHEN p.status_is_known AND p.status = 'cancelled' THEN
                           GREATEST(0::bigint,
                               LEAST(
                                   p.cancelled_quantity_before + p.quantity::bigint,
                                   p.handover_refusal_quantity_total + p.pickup_expired_quantity_total
                                       + p.delivery_failed_quantity_total + p.cancelled_quantity_total
                               ) - GREATEST(
                                   p.cancelled_quantity_before,
                                   p.handover_refusal_quantity_total + p.pickup_expired_quantity_total + p.delivery_failed_quantity_total
                               )
                           ) ELSE 0::bigint END AS cancelled_quantity
                FROM positioned p
            ),
            classified AS NOT MATERIALIZED (
                SELECT b.*,
                       CASE
                           WHEN b.order_number IS NOT NULL
                            AND b.status_is_known
                            AND b.status = 'cancelled'
                            AND b.handover_refusal_quantity > 0
                           THEN CASE
                               WHEN b.order_has_delivered THEN 'P'
                               WHEN b.order_all_terminal THEN 'T2'
                               ELSE NULL
                           END
                           ELSE NULL
                       END::VARCHAR(2) AS handover_refusal_outcome
                FROM base b
            )
            SELECT c.company_id, c.marketplace_account_id, c.source_row_id,
                   c.posting_number, c.order_number, c.marketplace_sku,
                   allocation.quantity::int AS quantity, c.business_date,
                   allocation.outcome::VARCHAR(2) AS outcome,
                   c.handed_over_at,
                   c.resolved_at,
                   (
                       c.is_active_pending
                       AND allocation.outcome IS NULL
                       AND c.return_quantity_total = 0
                   ) AS is_forecast_eligible,
                               COALESCE(c.resolution_observed, FALSE) AS resolution_observed,
                               (c.is_active_pending AND allocation.outcome IS NULL) AS is_in_flight
            FROM classified c
            CROSS JOIN LATERAL (
                SELECT raw.outcome, SUM(raw.quantity)::bigint AS quantity
                FROM (
                    SELECT 'R'::VARCHAR(2) AS outcome, c.client_return_quantity AS quantity
                    WHERE c.status_is_known AND c.status = 'delivered'
                    UNION ALL
                    SELECT 'D'::VARCHAR(2), c.quantity::bigint - c.client_return_quantity
                    WHERE c.status_is_known AND c.status = 'delivered'
                    UNION ALL
                    SELECT c.handover_refusal_outcome, c.handover_refusal_quantity
                    WHERE c.status_is_known AND c.status = 'cancelled'
                    UNION ALL
                    SELECT 'T2'::VARCHAR(2), c.pickup_expired_quantity + c.delivery_failed_quantity
                    WHERE c.status_is_known AND c.status = 'cancelled'
                    UNION ALL
                    SELECT CASE
                               WHEN c.all_cancelled_handed_over THEN 'T2'
                               WHEN c.all_cancelled_pre_handover THEN 'T1'
                               ELSE NULL
                           END::VARCHAR(2),
                           c.cancelled_quantity
                    WHERE c.status_is_known AND c.status = 'cancelled'
                    UNION ALL
                    SELECT CASE WHEN c.all_cancelled_handed_over THEN 'T2' ELSE NULL END::VARCHAR(2),
                           c.quantity::bigint - c.handover_refusal_quantity - c.pickup_expired_quantity
                               - c.delivery_failed_quantity - c.cancelled_quantity
                    WHERE c.status_is_known AND c.status = 'cancelled'
                    UNION ALL
                    SELECT NULL::VARCHAR(2), c.quantity::bigint
                    WHERE NOT (c.status_is_known AND c.status IN ('delivered', 'cancelled'))
                ) raw
                WHERE raw.quantity > 0
                GROUP BY raw.outcome
            ) allocation
            $$
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP FUNCTION buyout_outcome_as_of(uuid, timestamp)');
    }
}
