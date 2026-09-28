<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Реклама Ozon по SKU (ADR-035): факт-таблица ad_sku_expense_fact —
 * расход кампании на SKU за день, ключ (company_id, marketplace_account_id,
 * source_row_id = campaign_id|business_date|sku). Новая таблица — боевые
 * данные не затрагиваются.
 *
 * После применения данные появятся из новых SKU-отчётов: суточный тик,
 * рескан и разовая загрузка истории командой
 * app:ingestion:backfill-ozon-advertising.
 *
 * down() рабочий: таблица пересобирается из площадки той же командой.
 */
final class Version20260928104349 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Ozon advertising expense by SKU fact table (ADR-035)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ad_sku_expense_fact (company_id UUID NOT NULL, marketplace_account_id UUID NOT NULL, source_row_id TEXT NOT NULL, business_date DATE NOT NULL, campaign_id VARCHAR(32) NOT NULL, marketplace_sku VARCHAR(64) NOT NULL, amount_minor BIGINT NOT NULL, currency CHAR(3) NOT NULL, raw_document_id UUID NOT NULL, source_received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, row_hash VARCHAR(64) NOT NULL, first_loaded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (company_id, marketplace_account_id, source_row_id))');
        $this->addSql('CREATE INDEX idx_ad_sku_expense_fact_company_business_date ON ad_sku_expense_fact (company_id, business_date)');
        $this->addSql('CREATE INDEX idx_ad_sku_expense_fact_raw_document_id ON ad_sku_expense_fact (raw_document_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ad_sku_expense_fact');
    }
}
