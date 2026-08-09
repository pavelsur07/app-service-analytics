<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260808182053 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'sales_fact: естественный PK (company_id, marketplace_account_id, source_row_id) по ADR-006/ADR-009.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sales_fact (company_id UUID NOT NULL, marketplace_account_id UUID NOT NULL, source_row_id TEXT NOT NULL, business_date DATE NOT NULL, status VARCHAR(32) NOT NULL, marketplace_sku VARCHAR(64) NOT NULL, quantity INT NOT NULL, amount_minor BIGINT NOT NULL, commission_amount_minor BIGINT NOT NULL, currency CHAR(3) NOT NULL, raw_document_id UUID NOT NULL, row_hash VARCHAR(64) NOT NULL, first_loaded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (company_id, marketplace_account_id, source_row_id))');
        $this->addSql('CREATE INDEX idx_sales_fact_company_business_date ON sales_fact (company_id, business_date)');
        $this->addSql('CREATE INDEX idx_sales_fact_raw_document_id ON sales_fact (raw_document_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sales_fact');
    }
}
