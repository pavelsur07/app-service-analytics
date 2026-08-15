<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Артикул продавца и наименование в каталоге.
 *
 * Карточка до сих пор была голым sku площадки — строкой вроде
 * `1402861293`. Ввести по такому списку себестоимость невозможно:
 * селлер не узнаёт в этих цифрах свои товары.
 *
 * Обе колонки nullable, и это решение. На проде уже лежат строки без
 * этих значений, а имя приходит вторым запросом к площадке и может
 * отстать от артикула на один тик синхронизации. NOT NULL потребовал бы
 * второй миграции ради строгости, которой некому воспользоваться.
 *
 * Ключ таблицы не меняется. Артикул продавца в него не входит намеренно:
 * селлер переименовывает его сам, и будь он частью ключа, история цен
 * отвязалась бы при первом же переименовании.
 *
 * Индекса на offer_id нет: по нему пока никто не ищет. Он появится
 * вместе с экраном ввода себестоимости, который будет группировать
 * карточки по артикулу.
 *
 * Добавление nullable-колонки без DEFAULT в PostgreSQL 11+ не переписывает
 * таблицу — на боевой таблице в две сотни строк это и так незаметно.
 */
final class Version20260815082842 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Артикул продавца и наименование в marketplace_listing';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_listing ADD offer_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE marketplace_listing ADD name VARCHAR(512) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_listing DROP offer_id');
        $this->addSql('ALTER TABLE marketplace_listing DROP name');
    }
}
