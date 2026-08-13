<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Индекс под контроль свежести данных (NotifyStaleAccountsAction):
 * диапазон по received_at с группировкой по подключению раз в час.
 * Без него сторож читал бы всю таблицу raw-документов целиком, а она
 * растёт с каждым днём работы каждого подключения — и первым перестал бы
 * работать он сам, тихо, ровно как отказ, который он ловит.
 *
 * CONCURRENTLY не используется: таблица сейчас небольшая, а создание
 * без блокировки требует выхода из транзакции миграции. Когда объём
 * сделает это существенным, индекс уже будет стоять — этот случай
 * ровно из тех, где дешевле сделать сразу.
 */
final class Version20260813093203 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'marketplace_raw_document: индекс (received_at, company_id, marketplace_account_id) под контроль свежести данных.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_marketplace_raw_document_received_at ON marketplace_raw_document (received_at, company_id, marketplace_account_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_marketplace_raw_document_received_at');
    }
}
