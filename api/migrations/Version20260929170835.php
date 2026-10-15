<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Поиск снятых с продажи SKU по истории заказов: company_id первым столбцом,
 * trigram-поиск по marketplace_sku. Индекс создаётся без блокировки записи
 * фактов. down() удаляет индекс; расширения остаются общими объектами БД.
 */
final class Version20260929170835 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Add tenant-scoped trigram index for historical sales SKU search';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS btree_gin');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        // Interrupted CREATE INDEX CONCURRENTLY can leave an INVALID index behind.
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_sales_fact_company_sku_trgm');
        $this->addSql('CREATE INDEX CONCURRENTLY idx_sales_fact_company_sku_trgm ON sales_fact USING gin (company_id, marketplace_sku gin_trgm_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY idx_sales_fact_company_sku_trgm');
    }
}
