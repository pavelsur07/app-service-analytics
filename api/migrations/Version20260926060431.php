<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Атрибуты доставки заказа Ozon FBO в sales_fact: склад отгрузки
 * (analytics_data.warehouse_id / warehouse_name) и город доставки
 * (analytics_data.city). Только для показа в списке — индексов нет.
 *
 * Три необязательные колонки со значением по умолчанию NULL — изменение
 * метаданных таблицы, без перезаписи строк и долгой блокировки записи.
 * Совместима со старым кодом: он колонок не знает и не читает.
 * Исторические строки заполняет app:ingestion:backfill-ozon-posting-statuses
 * из сохранённого raw.
 *
 * down() рабочий: колонки восстанавливаются из raw той же командой.
 */
final class Version20260926060431 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Ozon FBO delivery attributes (warehouse, delivery city) to sales_fact';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_fact ADD warehouse_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE sales_fact ADD warehouse_name TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE sales_fact ADD delivery_city TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_fact DROP warehouse_id');
        $this->addSql('ALTER TABLE sales_fact DROP warehouse_name');
        $this->addSql('ALTER TABLE sales_fact DROP delivery_city');
    }
}
