<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Список артикулов, за ценой которых следит расширение (ADR-014).
 *
 * Уникальный индекс по (компания, подключение, артикул) — не украшение,
 * а сам механизм идемпотентности: повторное «добавить в отслеживание»
 * разрешается через ON CONFLICT в самом запросе, без проверки перед
 * вставкой, которую два параллельных клика прошли бы оба (CLAUDE.md §4).
 * `company_id` первым столбцом — §1.
 *
 * Второго индекса под чтение списка нет намеренно. Список отдаётся
 * с фильтром по компании и состоянию и сортировкой по артикулу, то есть
 * этот индекс его сортировку не обслуживает, — но строк на компанию
 * несколько десятков по построению (потолок в StartTrackingAction),
 * и индекс, оплачиваемый на каждой записи, экономил бы сортировку
 * пятидесяти строк.
 *
 * `created_by_user_id` без индекса — поле следа, по нему не фильтруют
 * и не соединяют; то же решение и та же причина, что
 * у `extension_token.revoked_by_user_id`.
 *
 * Новая таблица, существующие не трогаются: правило совместимых
 * изменений здесь неприменимо, откатывается удалением.
 */
final class Version20260817182921 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Список отслеживаемых артикулов для мониторинга цены (ADR-014)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tracked_sku (id UUID NOT NULL, company_id UUID NOT NULL, marketplace_account_id UUID NOT NULL, marketplace_sku VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by_user_id UUID NOT NULL, stopped_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_tracked_sku_company_account_sku ON tracked_sku (company_id, marketplace_account_id, marketplace_sku)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tracked_sku');
    }
}
