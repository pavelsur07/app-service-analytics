<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Реклама подключения (ADR-026, п. 1): состояние рекламного ключа
 * отдельно от состояния подключения. NULL — реклама не подключена.
 *
 * Необязательная колонка без значения по умолчанию — изменение
 * метаданных таблицы, без перезаписи строк. Совместима со старым кодом:
 * он колонку не знает и не читает.
 *
 * down() рабочий: колонка — единственное место, где хранится состояние
 * рекламы, а сами ключи лежат в зашифрованном объекте учётных данных
 * и откатом не затрагиваются. После отката подключения с рекламой
 * выглядят как без неё, данные не теряются.
 */
final class Version20260924192856 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Реклама подключения: состояние рекламного ключа';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_account ADD advertising_state VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_account DROP advertising_state');
    }
}
