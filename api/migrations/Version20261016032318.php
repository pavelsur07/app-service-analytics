<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Штуки в ленте начислений и отметка загрузки дня (ADR-038).
 *
 * marketplace_expense_fact.quantity — колонка с константным умолчанием:
 * PostgreSQL 11+ добавляет её без перезаписи таблицы и без долгой
 * блокировки. Все строки, записанные до неё, — на одну штуку
 * (проверено обследованием raw обеих компаний), поэтому умолчание 1
 * верно и для истории; заполнять нечего, чтение переключается в том же
 * изменении (правило совместимых изменений соблюдено: только добавление).
 *
 * marketplace_day_load — новая таблица, ключ начинается с company_id
 * (CLAUDE.md §1). Не заполняется: дни без отметки судятся по raw.
 *
 * down() рабочий: колонка и таблица удаляются. Теряются только штуки
 * строк на несколько штук и отметки — и то и другое восстанавливается
 * повторной загрузкой дней из площадки.
 */
final class Version20261016032318 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add accrual quantity and day load marks (ADR-038)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE marketplace_day_load (company_id UUID NOT NULL, marketplace_account_id UUID NOT NULL, report_type VARCHAR(64) NOT NULL, period DATE NOT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, failed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, failure VARCHAR(500) DEFAULT NULL, PRIMARY KEY (company_id, marketplace_account_id, report_type, period))');
        $this->addSql('ALTER TABLE marketplace_expense_fact ADD quantity INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_day_load');
        $this->addSql('ALTER TABLE marketplace_expense_fact DROP quantity');
    }
}
