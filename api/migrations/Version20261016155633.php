<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Цена с Ozon Картой в наблюдении (ADR-039).
 *
 * Добавление nullable-колонки без значения по умолчанию в PostgreSQL —
 * правка каталога, без перезаписи таблицы и без долгой блокировки.
 *
 * Применяется раньше кода, который её пишет и читает; совместимости
 * в обратном порядке нет и не нужно. Порядок держит конвейер: шаг
 * `doctrine:migrations:up-to-date` проверяет новый образ до замены
 * работающих контейнеров и останавливает выкладку, пока миграция
 * не применена. Прежний код на новой схеме работает: колонку он
 * не упоминает.
 *
 * down() рабочий: удаляется только новая колонка, прежние данные
 * не затрагиваются.
 */
final class Version20261016155633 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the Ozon Card price to price observations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE price_observation ADD card_price_minor BIGINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE price_observation DROP card_price_minor');
    }
}
