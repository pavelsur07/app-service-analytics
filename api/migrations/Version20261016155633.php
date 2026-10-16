<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Цена с Ozon Картой в наблюдении (docs/plan/extension-hardening.md, этап 1).
 *
 * Добавление nullable-колонки без значения по умолчанию в PostgreSQL —
 * правка каталога, без перезаписи таблицы и без долгой блокировки.
 * Совместимо с выкладкой в любом порядке: прежний writer колонку
 * не упоминает, новый пишет NULL, когда цены с картой нет.
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
