<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Pending: единая итоговая миграция функции ADR-046, расширяется следующими задачами до merge. */
final class Version20261027120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sales planning approval and exclusive day claims (ADR-040/046)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE sales_plan ADD metric VARCHAR(48) DEFAULT NULL CONSTRAINT chk_sales_plan_metric CHECK (metric IN ('retained_sales', 'orders_excluding_pre_handover_cancellations'))");
        $this->addSql('ALTER TABLE sales_plan ADD approved_by_user_id UUID DEFAULT NULL, ADD approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_sales_plan_company_approved_by ON sales_plan (company_id, approved_by_user_id)');
        $this->addSql('CREATE TABLE sales_plan_day_claim (company_id UUID NOT NULL, marketplace_account_id UUID NOT NULL, marketplace_sku VARCHAR(64) NOT NULL, plan_date DATE NOT NULL, sales_plan_id UUID NOT NULL, PRIMARY KEY(company_id, marketplace_account_id, marketplace_sku, plan_date))');
        $this->addSql('CREATE INDEX idx_sales_plan_claim_company_plan ON sales_plan_day_claim (company_id, sales_plan_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sales_plan_day_claim');
        $this->addSql('DROP INDEX idx_sales_plan_company_approved_by');
        $this->addSql('ALTER TABLE sales_plan DROP approved_by_user_id, DROP approved_at, DROP metric');
    }
}
