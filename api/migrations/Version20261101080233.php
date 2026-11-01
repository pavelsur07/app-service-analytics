<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Research project drafts and calendar ranges (ADR-050).
 */
final class Version20261101080233 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Company-scoped Ozon research drafts and periods';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE product_research_ozon_period (id UUID NOT NULL, from_month DATE NOT NULL, to_month DATE NOT NULL, name VARCHAR(100) NOT NULL, position INT NOT NULL, company_id UUID NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F3695C63979B1AD6166D1F9C ON product_research_ozon_period (company_id, project_id)');
        $this->addSql('CREATE INDEX idx_research_period_order ON product_research_ozon_period (company_id, project_id, position)');
        $this->addSql('CREATE UNIQUE INDEX uq_research_period_range ON product_research_ozon_period (company_id, project_id, from_month, to_month, name)');
        $this->addSql('CREATE TABLE product_research_ozon_project (company_id UUID NOT NULL, id UUID NOT NULL, category_path VARCHAR(2048) NOT NULL, category_revision INT NOT NULL, name VARCHAR(200) NOT NULL, comment VARCHAR(2000) NOT NULL, include_current_month BOOLEAN NOT NULL, status VARCHAR(16) NOT NULL, created_by_user_id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, period_count INT NOT NULL, version INT DEFAULT 1 NOT NULL, PRIMARY KEY (company_id, id))');
        $this->addSql('CREATE INDEX idx_research_project_author ON product_research_ozon_project (company_id, created_by_user_id)');
        $this->addSql('ALTER TABLE product_research_ozon_period ADD CONSTRAINT FK_F3695C63979B1AD6166D1F9C FOREIGN KEY (company_id, project_id) REFERENCES product_research_ozon_project (company_id, id) NOT DEFERRABLE');
        $this->addSql("ALTER TABLE product_research_ozon_period ADD CONSTRAINT chk_research_period CHECK (from_month <= to_month AND EXTRACT(DAY FROM from_month) = 1 AND EXTRACT(DAY FROM to_month) = 1 AND position >= 0 AND position < 24)");
        $this->addSql("ALTER TABLE product_research_ozon_project ADD CONSTRAINT chk_research_project CHECK (category_revision > 0 AND period_count BETWEEN 1 AND 24 AND version > 0 AND status = 'draft')");
    }

    public function down(Schema $schema): void
    {
        // Drafts cannot be reconstructed from MPStats: never silently drop them.
        $this->abortIf(false !== $this->connection->fetchOne('SELECT EXISTS (SELECT 1 FROM product_research_ozon_project)'), 'Research drafts exist; preserve data and restore the matching database backup for rollback.');
        $this->addSql('ALTER TABLE product_research_ozon_period DROP CONSTRAINT FK_F3695C63979B1AD6166D1F9C');
        $this->addSql('DROP TABLE product_research_ozon_period');
        $this->addSql('DROP TABLE product_research_ozon_project');
    }
}
