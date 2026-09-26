<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Остатки Ozon FBO (ADR-034): снимочный факт stock_snapshot_fact
 * (снимок дня × SKU × склад, дата снимка — колонкой первичного ключа
 * под будущее партиционирование) и отметка полноты снимка дня
 * stock_snapshot_run. Новые таблицы — боевые данные не затрагиваются.
 *
 * После применения данные появятся с первым прогоном: ночным (час
 * рескана) или ручным диспатчем FetchOzonStocksMessage. Проверка — у
 * каждого активного подключения есть отметка дня с непустым started_at.
 *
 * down() рабочий, но история снимков невосстановима (ADR-021): остатки
 * задним числом не загружаются. Откатывать после накопления истории —
 * только восстановлением из резервной копии.
 */
final class Version20260926120931 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Ozon FBO stock snapshots (ADR-034)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE stock_snapshot_fact (company_id UUID NOT NULL, marketplace_account_id UUID NOT NULL, snapshot_date DATE NOT NULL, source_row_id TEXT NOT NULL, marketplace_sku VARCHAR(64) NOT NULL, warehouse_id BIGINT NOT NULL, warehouse_name TEXT NOT NULL, cluster_id BIGINT NOT NULL, cluster_name TEXT NOT NULL, available INT NOT NULL, transit INT NOT NULL, requested INT NOT NULL, return_from_customer INT NOT NULL, return_to_seller INT NOT NULL, defect INT NOT NULL, other INT NOT NULL, ads_cluster NUMERIC(12, 4) DEFAULT NULL, idc_cluster INT DEFAULT NULL, turnover_grade_cluster VARCHAR(32) DEFAULT NULL, raw_document_id UUID NOT NULL, PRIMARY KEY (company_id, marketplace_account_id, snapshot_date, source_row_id))');
        $this->addSql('CREATE INDEX idx_stock_snapshot_fact_raw_document_id ON stock_snapshot_fact (raw_document_id)');
        $this->addSql('CREATE TABLE stock_snapshot_run (company_id UUID NOT NULL, marketplace_account_id UUID NOT NULL, snapshot_date DATE NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, first_started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, requested_skus JSONB NOT NULL, raw_document_ids JSONB NOT NULL, row_count INT NOT NULL, PRIMARY KEY (company_id, marketplace_account_id, snapshot_date))');
        $this->addSql('CREATE INDEX idx_stock_snapshot_run_started_at ON stock_snapshot_run (started_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE stock_snapshot_fact');
        $this->addSql('DROP TABLE stock_snapshot_run');
    }
}
