<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Адрес фото карточки в каталоге.
 *
 * Экран юнит-экономики сравнивает товары, а глазом товар опознаётся
 * по картинке быстрее, чем по одиннадцатизначному sku площадки.
 *
 * Хранится адрес целиком, а не идентификатор, потому что собрать его
 * обратно нельзя: медиа-идентификатор в нём не выводится ни из sku,
 * ни из offer_id, ни из product_id, а шард бакета меняется от карточки
 * к карточке — проверено на всех 62 позициях снятой фикстуры. Размер
 * превью подставляет уже фронтенд, вставляя сегмент в этот адрес:
 * какого размера нужна картинка, решает вёрстка, а не каталог.
 *
 * Нового запроса к площадке колонка не создаёт. Адрес приходит тем же
 * ответом `/v3/product/info/list`, из которого уже берётся наименование,
 * и до сих пор просто выбрасывался.
 *
 * Nullable, и это решение той же природы, что у offer_id и name: на проде
 * уже лежат строки без значения, ответ второго запроса может отстать
 * на тик, а у карточки честно может не быть фото. NOT NULL потребовал бы
 * второй миграции ради строгости, которой некому воспользоваться.
 *
 * Индекса нет: по адресу никто не ищет, он только читается вместе
 * со строкой.
 *
 * Добавление nullable-колонки без DEFAULT в PostgreSQL 11+ не переписывает
 * таблицу — блокировка короткая, на объёме каталога незаметная.
 */
final class Version20260828171521 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Адрес фото карточки в marketplace_listing';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_listing ADD photo_url VARCHAR(512) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_listing DROP photo_url');
    }
}
