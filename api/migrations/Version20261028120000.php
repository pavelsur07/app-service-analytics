<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-049: compatible prospective capture. Legacy revision0 has no invented history.
 * App rollback keeps this schema. Production down destroys observation history:
 * save all revision/receipt tables before down; restore them from that backup.
 * Mutable facts/raw cannot reconstruct accepted ABA states or load receipts.
 */
final class Version20261028120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-049: accepted sales/return revisions and immutable load receipts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_fact ADD fact_revision BIGINT DEFAULT 0 NOT NULL, ADD capture_initial BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE sales_fact ADD CONSTRAINT chk_sales_fact_revision CHECK (fact_revision >= 0) NOT VALID');
        $this->addSql('ALTER TABLE marketplace_return_fact ADD fact_revision BIGINT DEFAULT 0 NOT NULL, ADD capture_initial BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE marketplace_return_fact ADD CONSTRAINT chk_marketplace_return_fact_revision CHECK (fact_revision >= 0) NOT VALID');
        $this->addSql('CREATE TABLE sales_fact_revision (company_id UUID NOT NULL, marketplace_account_id UUID NOT NULL, source_row_id TEXT NOT NULL, fact_revision BIGINT NOT NULL, id UUID NOT NULL, captured_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, capture_version VARCHAR(32) NOT NULL, capture_path VARCHAR(32) NOT NULL, capture_origin VARCHAR(32) NOT NULL, capture_is_initial BOOLEAN NOT NULL, business_date DATE NOT NULL, status VARCHAR(32) NOT NULL, marketplace_sku VARCHAR(64) NOT NULL, posting_number VARCHAR(64), order_number VARCHAR(64), quantity INT NOT NULL, warehouse_id BIGINT, warehouse_name TEXT, delivery_city TEXT, cluster_from TEXT, cluster_to TEXT, ordered_at TIMESTAMP(0) WITHOUT TIME ZONE, amount_minor BIGINT NOT NULL, commission_amount_minor BIGINT NOT NULL, currency CHAR(3) NOT NULL, raw_document_id UUID NOT NULL, row_hash VARCHAR(64) NOT NULL, first_loaded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (company_id, marketplace_account_id, source_row_id, fact_revision))');
        $this->addSql('CREATE UNIQUE INDEX uq_sales_revision_id ON sales_fact_revision (id)');
        $this->addSql('CREATE INDEX idx_sales_revision_order_sku ON sales_fact_revision (company_id, marketplace_account_id, order_number, marketplace_sku, captured_at)');
        $this->addSql('CREATE INDEX idx_sales_revision_captured ON sales_fact_revision (company_id, marketplace_account_id, captured_at, id)');
        $this->addSql('ALTER TABLE sales_fact_revision ADD CONSTRAINT chk_sales_revision_revision CHECK (fact_revision > 0)');
        $this->addSql('ALTER TABLE sales_fact_revision ADD CONSTRAINT chk_sales_revision_capture CHECK (capture_version = \'accepted_state_v1\' AND capture_path IN (\'upsert\', \'backfill_links\') AND capture_origin IN (\'unknown\', \'live_polling\', \'historical_backfill\', \'raw_replay\') AND (NOT capture_is_initial OR (fact_revision = 1 AND capture_origin = \'live_polling\' AND capture_path = \'upsert\')))');
        $this->addSql('CREATE TABLE marketplace_return_fact_revision (company_id UUID NOT NULL, marketplace_account_id UUID NOT NULL, source_row_id TEXT NOT NULL, fact_revision BIGINT NOT NULL, id UUID NOT NULL, captured_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, capture_version VARCHAR(32) NOT NULL, capture_path VARCHAR(32) NOT NULL, capture_origin VARCHAR(32) NOT NULL, capture_is_initial BOOLEAN NOT NULL, order_number VARCHAR(64) NOT NULL, marketplace_sku VARCHAR(64) NOT NULL, return_type VARCHAR(64) NOT NULL, return_reason_name TEXT NOT NULL, posting_number VARCHAR(64) NOT NULL, source_id BIGINT NOT NULL, quantity INT NOT NULL, visual_status_id INT NOT NULL, visual_status VARCHAR(64) NOT NULL, visual_status_changed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, raw_document_id UUID NOT NULL, row_hash CHAR(64) NOT NULL, first_loaded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (company_id, marketplace_account_id, source_row_id, fact_revision))');
        $this->addSql('CREATE UNIQUE INDEX uq_return_revision_id ON marketplace_return_fact_revision (id)');
        $this->addSql('CREATE INDEX idx_return_revision_order_sku ON marketplace_return_fact_revision (company_id, marketplace_account_id, order_number, marketplace_sku, captured_at)');
        $this->addSql('CREATE INDEX idx_return_revision_captured ON marketplace_return_fact_revision (company_id, marketplace_account_id, captured_at, id)');
        $this->addSql('ALTER TABLE marketplace_return_fact_revision ADD CONSTRAINT chk_return_revision_revision CHECK (fact_revision > 0)');
        $this->addSql('ALTER TABLE marketplace_return_fact_revision ADD CONSTRAINT chk_return_revision_capture CHECK (capture_version = \'accepted_state_v1\' AND capture_path IN (\'upsert\', \'backfill_links\') AND capture_origin IN (\'unknown\', \'live_polling\', \'historical_backfill\', \'raw_replay\') AND (NOT capture_is_initial OR (fact_revision = 1 AND capture_origin = \'live_polling\' AND capture_path = \'upsert\')))');
        $this->addSql('CREATE TABLE marketplace_day_load_receipt (company_id UUID NOT NULL, marketplace_account_id UUID NOT NULL, report_type VARCHAR(64) NOT NULL, date_from DATE NOT NULL, date_to DATE NOT NULL, marked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, status VARCHAR(16) NOT NULL, id UUID NOT NULL, capture_version VARCHAR(32) NOT NULL, capture_origin VARCHAR(32) DEFAULT \'unknown\' NOT NULL, PRIMARY KEY (company_id, marketplace_account_id, report_type, date_from, date_to, marked_at, status))');
        $this->addSql('CREATE UNIQUE INDEX uq_day_load_receipt_id ON marketplace_day_load_receipt (id)');
        $this->addSql('CREATE INDEX idx_day_load_receipt_window ON marketplace_day_load_receipt (company_id, marketplace_account_id, report_type, marked_at, date_from, date_to)');
        $this->addSql('ALTER TABLE marketplace_day_load_receipt ADD CONSTRAINT chk_day_load_receipt_capture CHECK (date_from <= date_to AND status IN (\'completed\', \'failed\') AND capture_version = \'accepted_state_v1\' AND capture_origin IN (\'unknown\', \'live_polling\', \'historical_backfill\', \'raw_replay\'))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_day_load_receipt');
        $this->addSql('DROP TABLE marketplace_return_fact_revision');
        $this->addSql('DROP TABLE sales_fact_revision');
        $this->addSql('ALTER TABLE marketplace_return_fact DROP CONSTRAINT chk_marketplace_return_fact_revision, DROP capture_initial, DROP fact_revision');
        $this->addSql('ALTER TABLE sales_fact DROP CONSTRAINT chk_sales_fact_revision, DROP capture_initial, DROP fact_revision');
    }
}
