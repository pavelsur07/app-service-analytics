<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921154000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planning 0.3: immutable XLSX import previews and idempotent apply result.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE planning_import_preview (
              id UUID NOT NULL,
              company_id UUID NOT NULL,
              marketplace_account_id UUID NOT NULL,
              actor_id UUID NOT NULL,
              fingerprint VARCHAR(64) NOT NULL,
              normalized_rows JSONB NOT NULL,
              status VARCHAR(16) NOT NULL,
              apply_result JSONB DEFAULT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              applied_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
              PRIMARY KEY (id),
              CONSTRAINT chk_planning_import_preview_status CHECK (status IN ('ready', 'applied'))
            )
        SQL);
        $this->addSql('CREATE INDEX idx_planning_import_preview_scope ON planning_import_preview (company_id, marketplace_account_id, id)');
        $this->addSql('CREATE INDEX idx_planning_import_preview_expiry ON planning_import_preview (company_id, status, expires_at)');
        $this->addSql('CREATE INDEX idx_planning_import_preview_actor ON planning_import_preview (company_id, actor_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE planning_import_preview');
    }
}
