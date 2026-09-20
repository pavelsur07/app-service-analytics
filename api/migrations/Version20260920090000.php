<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Provenance and completeness for Planning reads of Ingestion (ADR-024). */
final class Version20260920090000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Planning source completeness, account generation and resolution observation metadata';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET lock_timeout = '5s'");
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS btree_gin');
        // A failed concurrent build can leave an INVALID index with the intended name.
        // Reset only the indexes introduced by this migration before each attempt.
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_sales_fact_planning_sku');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_sales_fact_planning_sku_trgm');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_planning_listing_sku_trgm');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_planning_listing_offer_trgm');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_planning_listing_name_trgm');
        $this->addSql('CREATE INDEX CONCURRENTLY idx_sales_fact_planning_sku ON sales_fact (company_id, marketplace_account_id, marketplace_sku, business_date)');
        $this->addSql('CREATE INDEX CONCURRENTLY idx_sales_fact_planning_sku_trgm ON sales_fact USING GIN (company_id, marketplace_account_id, marketplace_sku gin_trgm_ops)');
        $this->addSql('CREATE INDEX CONCURRENTLY idx_planning_listing_sku_trgm ON marketplace_listing USING GIN (company_id, marketplace_account_id, marketplace_sku gin_trgm_ops)');
        $this->addSql('CREATE INDEX CONCURRENTLY idx_planning_listing_offer_trgm ON marketplace_listing USING GIN (company_id, marketplace_account_id, offer_id gin_trgm_ops)');
        $this->addSql('CREATE INDEX CONCURRENTLY idx_planning_listing_name_trgm ON marketplace_listing USING GIN (company_id, marketplace_account_id, name gin_trgm_ops)');
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS planning_ingestion_source_state (
                company_id UUID NOT NULL,
                marketplace_account_id UUID NOT NULL,
                source_kind VARCHAR(16) NOT NULL,
                from_date DATE NOT NULL,
                to_date DATE NOT NULL,
                content_hash CHAR(64) NOT NULL,
                last_complete_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_regular_complete_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                last_regular_window_to DATE DEFAULT NULL,
                last_origin VARCHAR(8) NOT NULL,
                PRIMARY KEY (company_id, marketplace_account_id, source_kind, from_date, to_date),
                CONSTRAINT chk_planning_source_kind CHECK (source_kind IN ('postings', 'returns', 'catalog')),
                CONSTRAINT chk_planning_source_origin CHECK (last_origin IN ('regular', 'rescan')),
                CONSTRAINT chk_planning_source_dates CHECK (from_date <= to_date)
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_planning_source_last_complete ON planning_ingestion_source_state (company_id, marketplace_account_id, source_kind, last_complete_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS planning_ingestion_source_raw_document (
                company_id UUID NOT NULL,
                marketplace_account_id UUID NOT NULL,
                source_kind VARCHAR(16) NOT NULL,
                from_date DATE NOT NULL,
                to_date DATE NOT NULL,
                raw_document_id UUID NOT NULL,
                PRIMARY KEY (company_id, marketplace_account_id, source_kind, from_date, to_date, raw_document_id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS planning_ingestion_account_state (
                company_id UUID NOT NULL,
                marketplace_account_id UUID NOT NULL,
                generation BIGINT NOT NULL,
                observation_run BIGINT NOT NULL DEFAULT 0,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                baseline_completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (company_id, marketplace_account_id),
                CONSTRAINT chk_planning_account_generation CHECK (generation > 0)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS planning_ingestion_source_row_run (
                company_id UUID NOT NULL,
                marketplace_account_id UUID NOT NULL,
                source_row_id TEXT NOT NULL,
                last_seen_run BIGINT NOT NULL,
                PRIMARY KEY (company_id, marketplace_account_id, source_row_id),
                CONSTRAINT chk_planning_source_row_run CHECK (last_seen_run >= 0)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS planning_ingestion_day_coverage (
                company_id UUID NOT NULL,
                marketplace_account_id UUID NOT NULL,
                observation_date DATE NOT NULL,
                postings_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                returns_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (company_id, marketplace_account_id, observation_date)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS planning_ingestion_resolution_observation (
                company_id UUID NOT NULL,
                marketplace_account_id UUID NOT NULL,
                source_row_id TEXT NOT NULL,
                allocation_key VARCHAR(20) NOT NULL,
                outcome VARCHAR(2) NOT NULL,
                first_observed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                undated_since_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                first_known_outcome_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                first_regularly_observed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                date_lineage VARCHAR(80) DEFAULT NULL,
                source_event_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                backfill BOOLEAN NOT NULL,
                raw_document_id UUID DEFAULT NULL,
                last_seen_run BIGINT NOT NULL DEFAULT 0,
                PRIMARY KEY (company_id, marketplace_account_id, source_row_id, allocation_key, outcome),
                CONSTRAINT chk_planning_allocation_key CHECK (allocation_key ~ '^[1-9][0-9]*$'),
                CONSTRAINT chk_planning_date_lineage CHECK ((first_known_outcome_at IS NULL) = (date_lineage IS NULL)),
                CONSTRAINT chk_planning_observation_outcome CHECK (outcome IN ('T1', 'D', 'T2', 'P', 'R'))
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_planning_resolution_known ON planning_ingestion_resolution_observation (company_id, marketplace_account_id, first_known_outcome_at, allocation_key)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_planning_resolution_regular ON planning_ingestion_resolution_observation (company_id, marketplace_account_id, first_regularly_observed_at, allocation_key)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_planning_resolution_undated ON planning_ingestion_resolution_observation (company_id, marketplace_account_id, undated_since_at, source_row_id) WHERE undated_since_at IS NOT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_planning_resolution_latest_run ON planning_ingestion_resolution_observation (company_id, marketplace_account_id, source_row_id, outcome, last_seen_run)');
        $this->addSql('RESET lock_timeout');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("SET lock_timeout = '5s'");
        $this->addSql('DROP TABLE IF EXISTS planning_ingestion_resolution_observation');
        $this->addSql('DROP TABLE IF EXISTS planning_ingestion_source_row_run');
        $this->addSql('DROP TABLE IF EXISTS planning_ingestion_day_coverage');
        $this->addSql('DROP TABLE IF EXISTS planning_ingestion_account_state');
        $this->addSql('DROP TABLE IF EXISTS planning_ingestion_source_raw_document');
        $this->addSql('DROP TABLE IF EXISTS planning_ingestion_source_state');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_planning_listing_name_trgm');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_planning_listing_offer_trgm');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_planning_listing_sku_trgm');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_sales_fact_planning_sku_trgm');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_sales_fact_planning_sku');
        $this->addSql('RESET lock_timeout');
    }
}
