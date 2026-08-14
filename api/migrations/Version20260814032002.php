<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * marketplace_listing теряет last_seen_at.
 *
 * Колонка задумывалась признаком ухода товара («видели раньше, чем
 * прошла эта синхронизация»), но признаком быть перестала: она хранит
 * секунды, и две синхронизации внутри одной секунды получали одинаковое
 * значение — исчезнувший товар переживал такую пару незамеченным.
 * Теперь writer сравнивает с самой выгрузкой, и читать колонку стало
 * некому: «когда каталог синхронизировался в прошлый раз» отвечает
 * raw-слой.
 *
 * Оставленная, она означала бы, что повторный прогон обработчика на том же
 * ответе площадки меняет строку, — ровно то, чего CLAUDE.md §4 не
 * допускает. Удаление одним шагом здесь безопасно: правило совместимых
 * изменений защищает таблицы фактов с боевыми данными, а marketplace_listing
 * на проде пуста — синхронизация каталога ещё не выкладывалась.
 */
final class Version20260814032002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'marketplace_listing: удаление last_seen_at — признаком ухода товара стала сама выгрузка, изменяемых колонок в таблице не остаётся.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_listing DROP last_seen_at');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_listing ADD last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
    }
}
