<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Статус клиентского аккаунта (ADR-017): active | blocked.
 *
 * Первая функция системного раздела — управление аккаунтами, и статус
 * здесь единственное, что отделяет работающего клиента от выключенного.
 * Заблокированный аккаунт не удаляется и не помечается неполным задним
 * числом: перестаёт пускать своих пользователей, данные остаются как были.
 *
 * Строка, не enum-тип PostgreSQL, — как и role у администратора: третье
 * состояние (например, под будущий биллинг) не должно требовать
 * ALTER TYPE, а перечисление живёт в PHP (CompanyStatus).
 *
 * DEFAULT нужен на время добавления и снимается сразу: существующим
 * строкам он проставляет active одним движением, а дальше значение
 * всегда задаёт приложение. Оставленный DEFAULT разошёлся бы
 * с маппингом Doctrine, который его не описывает, и schema:validate
 * начал бы показывать несуществующее расхождение.
 *
 * Блокировка короткая: добавление колонки с константным DEFAULT
 * в PostgreSQL 11+ таблицу не переписывает, DROP DEFAULT меняет только
 * метаданные.
 *
 * Индекса нет намеренно. Статус читается вместе со строкой компании —
 * в проверке доступа (CompanyAccessSubscriber) отбор идёт по
 * первичному ключу, и индекс по status там не при чём. Экран списка
 * аккаунтов появится в следующем этапе; понадобится ему отбор
 * по статусу — индекс придёт с тем запросом, а не заранее
 * (индекс следует за запросом, CLAUDE.md §1).
 */
final class Version20260829100437 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'company.status: блокировка клиентского аккаунта (ADR-017).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE company ADD status VARCHAR(32) DEFAULT 'active' NOT NULL");
        $this->addSql('ALTER TABLE company ALTER status DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company DROP status');
    }
}
