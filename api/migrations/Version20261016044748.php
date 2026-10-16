<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Контроль свежести читает отметки загрузки дня (ADR-038) по всем
 * компаниям за последние 36 часов: индекс с временем успеха первым
 * столбцом, остальные (с period — отбор недавнего дня) — ради index-only scan (CLAUDE.md §1, «Индекс
 * следует за запросом»). Таблица отметок — не факт-таблица и небольшая
 * (сотни строк на подключение): обычный CREATE INDEX блокирует запись
 * отметок на доли секунды.
 *
 * down() рабочий: индекс удаляется, данные не затрагиваются.
 */
final class Version20261016044748 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index day load marks by completion time for the freshness watch (ADR-038)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_marketplace_day_load_completed_at ON marketplace_day_load (completed_at, company_id, marketplace_account_id, report_type, period)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_marketplace_day_load_completed_at');
    }
}
