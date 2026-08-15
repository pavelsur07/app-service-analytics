<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Себестоимость единицы товара с историей по датам (ADR-013).
 *
 * Уникальный индекс по (компания, подключение, артикул, дата начала
 * действия) — не украшение: он же и защита от двух цен на один день.
 * Проверять существование перед вставкой нельзя — между проверкой
 * и вставкой два параллельных запроса прошли бы её оба.
 *
 * Он же обслуживает единственное чтение, которое понадобится расчёту:
 * «какая цена действовала на дату D» — это поиск по трём первым
 * столбцам с ограничением сверху по четвёртому. Отдельного индекса
 * для этого заводить не нужно.
 *
 * Дата окончания действия не хранится: выводится из следующей записи.
 * Вторая дата рядом с первой неизбежно рассинхронизируется.
 *
 * Новая таблица, существующие не трогаются — правило совместимых
 * изменений здесь неприменимо, откатывается удалением.
 */
final class Version20260815101425 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Себестоимость единицы товара с историей по датам';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE marketplace_listing_cost (id UUID NOT NULL, company_id UUID NOT NULL, marketplace_account_id UUID NOT NULL, marketplace_sku VARCHAR(64) NOT NULL, effective_from DATE NOT NULL, unit_cost_minor BIGINT NOT NULL, currency CHAR(3) NOT NULL, method VARCHAR(32) NOT NULL, recorded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, version INT DEFAULT 1 NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_marketplace_listing_cost_effective_from ON marketplace_listing_cost (company_id, marketplace_account_id, marketplace_sku, effective_from)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_listing_cost');
    }
}
