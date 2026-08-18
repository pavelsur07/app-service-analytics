<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Список артикулов, за ценой которых следит расширение (ADR-014).
 *
 * Уникальный индекс по (компания, артикул) — не украшение, а сам
 * механизм идемпотентности: повторное «добавить в отслеживание»
 * разрешается через ON CONFLICT в самом запросе, без проверки перед
 * вставкой, которую два параллельных клика прошли бы оба (CLAUDE.md §4).
 * `company_id` первым столбцом — §1.
 *
 * **Подключение в ключ не входит,** хотя колонка есть: с ним
 * переподключение магазина давало бы вторую активную строку на тот же
 * артикул — список с дублем, двойной обход карточки за цикл и потолок,
 * считающий её за две.
 *
 * Второго индекса под чтение списка нет намеренно. Список отдаётся
 * с фильтром по компании и состоянию и сортировкой по артикулу — этот
 * индекс даёт префикс по компании и готовый порядок, остаётся только
 * отсев по состоянию. Остановленные строки копятся со скоростью кликов
 * человека, не данных; станет их тысячи — ответом будет частичный индекс
 * `WHERE status = 'active'`, а не второй полный.
 *
 * `created_by_user_id` индекс получает: это ссылка на `User` чужого
 * модуля, а §6 требует индекс в той же миграции и оговорки про размер
 * таблицы не делает. Составной, `company_id` первым столбцом (§1).
 * Оговорка у `extension_token.revoked_by_user_id` сюда не переносится:
 * там оба конца ссылки живут в Identity, и правило не срабатывает.
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
        $this->addSql('CREATE UNIQUE INDEX uq_tracked_sku_company_sku ON tracked_sku (company_id, marketplace_sku)');
        $this->addSql('CREATE INDEX idx_tracked_sku_company_created_by ON tracked_sku (company_id, created_by_user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tracked_sku');
    }
}
