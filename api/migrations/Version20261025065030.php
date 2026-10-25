<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Момент последнего изменения ключей или состояния подключения
 * (ADR-043 п. 4): поле `updated_at` выдачи подключений сервисам вне
 * монолита. По нему сервис отличает новые ключи от отказанных Ozon.
 *
 * Тремя шагами, потому что колонка обязательная, а строки уже есть:
 * добавить без NOT NULL, заполнить моментом подключения — более точного
 * следа прошлых изменений в таблице нет, а сервису нужно лишь значение,
 * которое сменится при следующей замене ключа, — и только потом
 * запретить NULL. Таблица — подключения, а не факты: десятки строк,
 * блокировка на время UPDATE не заметна.
 *
 * Умолчание `CURRENT_TIMESTAMP` — для старого образа: выкладка стоит на
 * гейте миграций, пока миграция не применена, и всё это время прежний код
 * вставляет подключения без `updated_at`. Без умолчания такая вставка
 * упала бы на NOT NULL. База работает в UTC, как и PHP.
 */
final class Version20261025065030 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'marketplace_account.updated_at — последнее изменение ключей или состояния (ADR-043 п. 4)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_account ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP');
        $this->addSql('UPDATE marketplace_account SET updated_at = created_at');
        $this->addSql('ALTER TABLE marketplace_account ALTER COLUMN updated_at SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_account DROP updated_at');
    }
}
