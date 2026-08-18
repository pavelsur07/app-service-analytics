<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Первый шаг удаления цены продавца из наблюдения (ADR-015).
 *
 * Спайк по живой карточке показал, что прислать её расширение
 * не может — на странице этого числа нет вовсе. Оно приходит
 * из `/v3/product/info/list` и живёт историей
 * в `marketplace_listing_price`; СПП считается при чтении разницей.
 *
 * **Колонка не удаляется, а перестаёт быть обязательной, и это
 * не осторожность впрок.** Одношаговое удаление не оставляет
 * совместимого порядка выкладки ни в одну сторону: применить миграцию
 * раньше кода — прежний writer пишет в исчезнувшую колонку и падает;
 * выложить код раньше миграции — новый writer не заполняет NOT NULL
 * и падает тоже. Гейт выкладки в этом проекте требует применённых
 * миграций до перезапуска контейнеров, то есть первый из двух случаев
 * наступил бы обязательно.
 *
 * Снятие NOT NULL совместимо с обоими: прежний код по-прежнему пишет
 * значение, новый оставляет NULL. Окна, в котором работает не то,
 * не остаётся вовсе.
 *
 * Сама колонка удаляется следующим изменением, когда новый код уже
 * везде. Держать остаток дольше одного релиза незачем — см. пометку
 * у `PriceObservation::$sellerPriceMinor`.
 *
 * `down()` восстанавливает NOT NULL и потому сработает только пока
 * в колонке нет NULL — то есть пока не принято ни одного наблюдения
 * новым кодом. Это верно: откатывать имеет смысл ровно в этом окне.
 */
final class Version20260818074735 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Цена продавца в наблюдении перестаёт быть обязательной (ADR-015, шаг 1 из 2)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE price_observation ALTER seller_price_minor DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE price_observation ALTER seller_price_minor SET NOT NULL');
    }
}
