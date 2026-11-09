<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109123500 extends AbstractMigration
{
    public function getDescription(): string { return 'Rebuildable company-scoped Ozon campaign metadata catalog'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ozon_campaign_catalog_snapshot (company_id UUID NOT NULL, account_id UUID NOT NULL, raw_document_id UUID NOT NULL, received_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, campaigns JSONB NOT NULL, total INT DEFAULT NULL, complete BOOLEAN NOT NULL, PRIMARY KEY(company_id, account_id))');
        $this->addSql("ALTER TABLE ozon_campaign_catalog_snapshot ADD CONSTRAINT chk_ozon_campaign_catalog_shape CHECK (jsonb_typeof(campaigns)='array' AND jsonb_array_length(campaigns)<=10000 AND (total IS NULL OR total>=jsonb_array_length(campaigns)) AND (NOT complete OR (total IS NOT NULL AND total=jsonb_array_length(campaigns))))");
    }

    public function down(Schema $schema): void
    {
        // Only a rebuildable metadata projection; immutable raw is untouched.
        $this->addSql('DROP TABLE ozon_campaign_catalog_snapshot');
    }
}
