<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Disposable company/source-scoped category projection (ADR-050).
 */
final class Version20261101093709 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ozon category catalog metadata cache and local search projection';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE product_research_ozon_catalog (company_id UUID NOT NULL, source_connection_id UUID NOT NULL, revision BIGINT NOT NULL, generated_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL, lease_id UUID DEFAULT NULL, lease_until TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL, next_attempt_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL, error_code VARCHAR(64) DEFAULT NULL, PRIMARY KEY (company_id, source_connection_id))');
        $this->addSql('CREATE TABLE product_research_ozon_category (id UUID NOT NULL, path_key CHAR(64) NOT NULL, path VARCHAR(2048) NOT NULL, company_id UUID NOT NULL, source_connection_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8537CD08979B1AD6C8BCEE63 ON product_research_ozon_category (company_id, source_connection_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_research_category_path ON product_research_ozon_category (company_id, source_connection_id, path_key)');
        $this->addSql('ALTER TABLE product_research_ozon_catalog ADD CONSTRAINT chk_research_catalog CHECK (revision >= 0 AND ((revision = 0 AND generated_at IS NULL) OR (revision > 0 AND generated_at IS NOT NULL)))');
        $this->addSql('ALTER TABLE product_research_ozon_category ADD CONSTRAINT FK_8537CD08979B1AD6C8BCEE63 FOREIGN KEY (company_id, source_connection_id) REFERENCES product_research_ozon_catalog (company_id, source_connection_id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // Only derived catalog data; human research projects are not removed.
        $this->addSql('ALTER TABLE product_research_ozon_category DROP CONSTRAINT FK_8537CD08979B1AD6C8BCEE63');
        $this->addSql('DROP TABLE product_research_ozon_category');
        $this->addSql('DROP TABLE product_research_ozon_catalog');
    }
}
