<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Полная схема расчёта процента выкупа Ozon (ADR-019). */
final class Version20260901090000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Ozon posting history, return facts and T1/D/T2/P/R outcome view (ADR-019)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS marketplace_posting_status (company_id UUID NOT NULL, marketplace_account_id UUID NOT NULL, posting_number VARCHAR(64) NOT NULL, raw_document_id UUID NOT NULL, order_number VARCHAR(64) NOT NULL, status VARCHAR(32) NOT NULL, substatus VARCHAR(64) DEFAULT NULL, cancel_reason_id BIGINT DEFAULT NULL, observed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (company_id, marketplace_account_id, posting_number, raw_document_id))');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_marketplace_posting_status_effective ON marketplace_posting_status (company_id, marketplace_account_id, posting_number, observed_at, raw_document_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_marketplace_posting_status_maturity ON marketplace_posting_status (company_id, marketplace_account_id, observed_at, status)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_marketplace_posting_status_raw ON marketplace_posting_status (company_id, raw_document_id)');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_marketplace_raw_document_ozon_history');
        $this->addSql('CREATE INDEX CONCURRENTLY idx_marketplace_raw_document_ozon_history ON marketplace_raw_document (company_id, marketplace_account_id, report_type, received_at, id)');

        $this->addSql('ALTER TABLE sales_fact ADD COLUMN IF NOT EXISTS posting_number VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE sales_fact ADD COLUMN IF NOT EXISTS order_number VARCHAR(64) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            DO $$ BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM pg_constraint
                    WHERE conrelid = 'sales_fact'::regclass
                      AND conname = 'chk_sales_fact_quantity_positive'
                ) THEN
                    ALTER TABLE sales_fact
                        ADD CONSTRAINT chk_sales_fact_quantity_positive CHECK (quantity > 0) NOT VALID;
                END IF;
            END $$
            SQL);
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_sales_fact_posting');
        $this->addSql('CREATE INDEX CONCURRENTLY idx_sales_fact_posting ON sales_fact (company_id, marketplace_account_id, posting_number)');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_sales_fact_order_sku');
        $this->addSql('CREATE INDEX CONCURRENTLY idx_sales_fact_order_sku ON sales_fact (company_id, marketplace_account_id, order_number, marketplace_sku)');

        $this->addSql('CREATE TABLE IF NOT EXISTS marketplace_return_fact (company_id UUID NOT NULL, marketplace_account_id UUID NOT NULL, source_row_id TEXT NOT NULL, order_number VARCHAR(64) NOT NULL, marketplace_sku VARCHAR(64) NOT NULL, return_type VARCHAR(64) NOT NULL, return_reason_name TEXT NOT NULL, posting_number VARCHAR(64) NOT NULL, source_id BIGINT NOT NULL, quantity INT NOT NULL, visual_status_id INT NOT NULL, visual_status VARCHAR(64) NOT NULL, visual_status_changed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, raw_document_id UUID NOT NULL, row_hash CHAR(64) NOT NULL, first_loaded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (company_id, marketplace_account_id, source_row_id), CONSTRAINT chk_marketplace_return_fact_quantity CHECK (quantity > 0))');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_return_fact_order_sku ON marketplace_return_fact (company_id, marketplace_account_id, order_number, marketplace_sku)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_return_fact_visual_changed ON marketplace_return_fact (company_id, visual_status_changed_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_return_fact_raw_document ON marketplace_return_fact (company_id, raw_document_id)');

        $this->addSql("CREATE TABLE IF NOT EXISTS ozon_return_reason_classification (return_type VARCHAR(64) NOT NULL, return_reason_name TEXT NOT NULL, event_stage VARCHAR(32) NOT NULL, verified_at DATE NOT NULL, PRIMARY KEY (return_type, return_reason_name), CONSTRAINT chk_ozon_return_event_stage CHECK (event_stage IN ('HANDOVER_REFUSAL', 'PICKUP_EXPIRED', 'DELIVERY_FAILED', 'CANCELLED'))) ");
        $this->addSql(<<<'SQL'
            INSERT INTO ozon_return_reason_classification (return_type, return_reason_name, event_stage, verified_at) VALUES
            ('Cancellation', 'Покупатель отказался при вручении: товар не подошел', 'HANDOVER_REFUSAL', '2026-08-30'),
            ('Cancellation', 'Покупатель отказался при вручении: в заказе не тот товар', 'HANDOVER_REFUSAL', '2026-08-30'),
            ('Cancellation', 'Покупатель отказался при вручении: недоволен качеством товара', 'HANDOVER_REFUSAL', '2026-08-30'),
            ('Cancellation', 'Покупатель отказался при вручении: неполная комплектация', 'HANDOVER_REFUSAL', '2026-08-30'),
            ('Cancellation', 'Изменил решение о покупке/Товар не подошёл', 'HANDOVER_REFUSAL', '2026-08-30'),
            ('Cancellation', 'Покупатель не забрал заказ', 'PICKUP_EXPIRED', '2026-08-30'),
            ('Cancellation', 'Не удалось доставить заказ', 'DELIVERY_FAILED', '2026-08-30'),
            ('Cancellation', 'Покупатель отменил заказ', 'CANCELLED', '2026-08-30'),
            ('Cancellation', 'Покупатель отменил заказ: нашел дешевле', 'CANCELLED', '2026-08-30'),
            ('Cancellation', 'Покупатель отменил заказ: не устроил срок доставки', 'CANCELLED', '2026-08-30'),
            ('Cancellation', 'Покупатель отменил заказ: перенос сроков доставки', 'CANCELLED', '2026-08-30'),
            ('Cancellation', 'Нашлось дешевле', 'CANCELLED', '2026-08-30')
            ON CONFLICT (return_type, return_reason_name) DO UPDATE SET
                event_stage = EXCLUDED.event_stage,
                verified_at = EXCLUDED.verified_at
            SQL);
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE VIEW buyout_outcome AS
            WITH latest_status AS NOT MATERIALIZED (
                SELECT DISTINCT ON (company_id, marketplace_account_id, posting_number)
                       company_id, marketplace_account_id, posting_number,
                       status, substatus, cancel_reason_id, observed_at, raw_document_id
                FROM marketplace_posting_status
                ORDER BY company_id, marketplace_account_id, posting_number,
                         observed_at DESC, raw_document_id DESC
            ),
            terminal_times AS NOT MATERIALIZED (
                SELECT keys.company_id, keys.marketplace_account_id, keys.posting_number,
                       terminal.observed_at AS resolved_at,
                       terminal.raw_document_id AS resolved_raw_document_id
                FROM (
                    SELECT DISTINCT company_id, marketplace_account_id, posting_number
                    FROM marketplace_posting_status
                ) keys
                LEFT JOIN LATERAL (
                    SELECT observed_at, raw_document_id
                    FROM marketplace_posting_status candidate
                    WHERE candidate.company_id = keys.company_id
                      AND candidate.marketplace_account_id = keys.marketplace_account_id
                      AND candidate.posting_number = keys.posting_number
                      AND (
                          (candidate.status = 'delivered' AND candidate.substatus IN ('posting_delivered', 'posting_received'))
                          OR (candidate.status = 'cancelled' AND candidate.substatus = 'posting_canceled')
                      )
                    ORDER BY observed_at, raw_document_id
                    LIMIT 1
                ) terminal ON TRUE
            ),
            status_evidence AS NOT MATERIALIZED (
                SELECT t.company_id, t.marketplace_account_id, t.posting_number,
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
                       ), FALSE) AS has_pre_handover_observation
                FROM terminal_times t
                JOIN marketplace_posting_status s
                  ON s.company_id = t.company_id
                 AND s.marketplace_account_id = t.marketplace_account_id
                 AND s.posting_number = t.posting_number
                GROUP BY t.company_id, t.marketplace_account_id, t.posting_number,
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
                FROM marketplace_return_fact r
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
                       e.handed_over_at, e.resolved_at, e.has_pre_handover_observation
                FROM sales_fact s
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
                       COALESCE(g.delivered_quantity_before, 0) AS delivered_quantity_before,
                       COALESCE(g.cancelled_quantity_before, 0) AS cancelled_quantity_before,
                       COALESCE(g.all_cancelled_handed_over, FALSE) AS all_cancelled_handed_over,
                       COALESCE(g.all_cancelled_pre_handover, FALSE) AS all_cancelled_pre_handover
                FROM base_with_return_totals b
                LEFT JOIN LATERAL (
                    SELECT COALESCE(SUM(sibling.quantity::bigint) FILTER (
                               WHERE sibling.status_is_known
                                 AND sibling.status = 'delivered'
                                 AND (
                                     sibling.business_date,
                                     COALESCE(sibling.posting_number, ''),
                                     sibling.source_row_id
                                 ) < (
                                     b.business_date,
                                     COALESCE(b.posting_number, ''),
                                     b.source_row_id
                                 )
                           ), 0) AS delivered_quantity_before,
                           COALESCE(SUM(sibling.quantity::bigint) FILTER (
                               WHERE sibling.status_is_known
                                 AND sibling.status = 'cancelled'
                                 AND (
                                     sibling.business_date,
                                     COALESCE(sibling.posting_number, ''),
                                     sibling.source_row_id
                                 ) < (
                                     b.business_date,
                                     COALESCE(b.posting_number, ''),
                                     b.source_row_id
                                 )
                           ), 0) AS cancelled_quantity_before,
                           COALESCE(BOOL_AND(sibling.handed_over_at IS NOT NULL) FILTER (
                               WHERE sibling.status_is_known AND sibling.status = 'cancelled'
                           ), FALSE) AS all_cancelled_handed_over,
                           COALESCE(BOOL_AND(
                               sibling.handed_over_at IS NULL
                               AND sibling.has_pre_handover_observation
                           ) FILTER (
                               WHERE sibling.status_is_known AND sibling.status = 'cancelled'
                           ), FALSE) AS all_cancelled_pre_handover
                    FROM sales_evidence sibling
                    WHERE sibling.company_id = b.company_id
                      AND sibling.marketplace_account_id = b.marketplace_account_id
                      AND sibling.order_number = b.order_number
                      AND sibling.marketplace_sku = b.marketplace_sku
                ) g ON b.order_number IS NOT NULL
                   AND b.status_is_known
                   AND b.status IN ('delivered', 'cancelled')
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
                           THEN (
                               SELECT CASE
                                   WHEN COALESCE(BOOL_OR(
                                       sibling.source_row_id <> b.source_row_id
                                       AND sibling_status.status = 'delivered'
                                       AND sibling_status.substatus IN ('posting_delivered', 'posting_received')
                                   ), FALSE) THEN 'P'
                                   WHEN COALESCE(BOOL_AND(COALESCE(
                                       (sibling_status.status = 'delivered' AND sibling_status.substatus IN ('posting_delivered', 'posting_received'))
                                       OR (sibling_status.status = 'cancelled' AND sibling_status.substatus = 'posting_canceled'),
                                       FALSE
                                   )), TRUE) THEN 'T2'
                                   ELSE NULL
                               END
                               FROM sales_fact sibling
                               LEFT JOIN LATERAL (
                                   SELECT candidate.status, candidate.substatus
                                   FROM marketplace_posting_status candidate
                                   WHERE candidate.company_id = sibling.company_id
                                     AND candidate.marketplace_account_id = sibling.marketplace_account_id
                                     AND candidate.posting_number = sibling.posting_number
                                   ORDER BY candidate.observed_at DESC, candidate.raw_document_id DESC
                                   LIMIT 1
                               ) sibling_status ON TRUE
                               WHERE sibling.company_id = b.company_id
                                 AND sibling.marketplace_account_id = b.marketplace_account_id
                                 AND sibling.order_number = b.order_number
                           )
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
                   ) AS is_forecast_eligible
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
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP VIEW IF EXISTS buyout_outcome');
        $this->addSql('DROP TABLE IF EXISTS ozon_return_reason_classification');
        $this->addSql('DROP TABLE IF EXISTS marketplace_return_fact');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_marketplace_raw_document_ozon_history');
        $this->addSql('DROP TABLE IF EXISTS marketplace_posting_status');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_sales_fact_posting');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_sales_fact_order_sku');
        $this->addSql('ALTER TABLE sales_fact DROP CONSTRAINT IF EXISTS chk_sales_fact_quantity_positive');
        $this->addSql('ALTER TABLE sales_fact DROP COLUMN IF EXISTS posting_number');
        $this->addSql('ALTER TABLE sales_fact DROP COLUMN IF EXISTS order_number');
    }
}
