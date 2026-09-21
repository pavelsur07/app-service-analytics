<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planning 0.2: editable daily sales plan with optimistic version and append-only audit.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE planning_daily_plan (
              id UUID NOT NULL,
              company_id UUID NOT NULL,
              marketplace_account_id UUID NOT NULL,
              marketplace_sku VARCHAR(64) NOT NULL,
              business_date DATE NOT NULL,
              quantity INT DEFAULT NULL,
              version INT DEFAULT 1 NOT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              updated_by UUID NOT NULL,
              PRIMARY KEY (id),
              CONSTRAINT chk_planning_daily_plan_quantity CHECK (quantity IS NULL OR quantity >= 0),
              CONSTRAINT chk_planning_daily_plan_version CHECK (version > 0)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_planning_daily_plan_key ON planning_daily_plan (company_id, marketplace_account_id, marketplace_sku, business_date)');
        $this->addSql('CREATE INDEX idx_planning_daily_plan_updated_by ON planning_daily_plan (company_id, updated_by)');
        $this->addSql(<<<'SQL'
            CREATE TABLE planning_plan_change (
              id UUID NOT NULL,
              company_id UUID NOT NULL,
              marketplace_account_id UUID NOT NULL,
              marketplace_sku VARCHAR(64) NOT NULL,
              business_date DATE NOT NULL,
              old_quantity INT DEFAULT NULL,
              new_quantity INT DEFAULT NULL,
              old_version INT NOT NULL,
              new_version INT NOT NULL,
              actor_id UUID NOT NULL,
              changed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              PRIMARY KEY (id),
              CONSTRAINT chk_planning_plan_change_old_quantity CHECK (old_quantity IS NULL OR old_quantity >= 0),
              CONSTRAINT chk_planning_plan_change_new_quantity CHECK (new_quantity IS NULL OR new_quantity >= 0),
              CONSTRAINT chk_planning_plan_change_versions CHECK (old_version >= 0 AND new_version = old_version + 1)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_planning_plan_change_key ON planning_plan_change (company_id, marketplace_account_id, marketplace_sku, business_date, changed_at)');
        $this->addSql('CREATE INDEX idx_planning_plan_change_actor ON planning_plan_change (company_id, actor_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE planning_plan_change');
        $this->addSql('DROP TABLE planning_daily_plan');
    }
}
