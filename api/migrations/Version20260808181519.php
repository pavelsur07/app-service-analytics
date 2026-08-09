<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260808181519 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Raw-слой: marketplace_raw_document (ADR-006), body — text, не jsonb: сохраняется точно как получено, без требования валидного JSON.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE marketplace_raw_document (id UUID NOT NULL, company_id UUID NOT NULL, marketplace_account_id UUID NOT NULL, report_type VARCHAR(64) NOT NULL, period DATE NOT NULL, body_hash VARCHAR(64) NOT NULL, body TEXT NOT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_marketplace_raw_document_company_id ON marketplace_raw_document (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_marketplace_raw_document_natural_key ON marketplace_raw_document (company_id, marketplace_account_id, report_type, period, body_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_raw_document');
    }
}
