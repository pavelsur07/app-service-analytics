<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * report_type в индекс контроля свежести.
 *
 * Сторож стал считать свежесть по каждой выгрузке отдельно
 * (RecentlyIngestedAccountsQuery), то есть фильтровать и группировать
 * по report_type. Без него в индексе почасовая проверка перестала бы
 * быть index-only scan и ходила бы в кучу за типом каждой строки
 * диапазона — ровно та деградация, ради предотвращения которой индекс
 * и заводился.
 *
 * Столбец дописан четвёртым, а не поставлен первым: ведущим обязан
 * остаться диапазон по received_at — это единственное неравенство
 * в запросе, и всё, что встанет перед ним, придётся сканировать целиком.
 *
 * DROP и CREATE одним шагом, а не «создать новый, потом удалить старый»:
 * DDL в PostgreSQL транзакционен, и внутри одной транзакции миграции
 * другие сессии не увидят таблицу без индекса — они подождут на замке.
 * Правило совместимых изменений из CLAUDE.md написано про колонки
 * таблиц фактов, где переключение чтения занимает отдельную выкладку;
 * здесь переключать нечего, а таблица на проде — 204 строки.
 */
final class Version20260815065302 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'report_type в индекс контроля свежести: сторож считает свежесть по каждой выгрузке отдельно';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_marketplace_raw_document_received_at');
        $this->addSql('CREATE INDEX idx_marketplace_raw_document_received_at ON marketplace_raw_document (received_at, company_id, marketplace_account_id, report_type)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_marketplace_raw_document_received_at');
        $this->addSql('CREATE INDEX idx_marketplace_raw_document_received_at ON marketplace_raw_document (received_at, company_id, marketplace_account_id)');
    }
}
