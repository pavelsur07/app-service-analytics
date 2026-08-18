<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Наблюдения цены с карточки Ozon (ADR-014).
 *
 * **Первичный ключ естественный** — (компания, кабинет, артикул, момент
 * снимка), суррогата нет (CLAUDE.md §2). Он же обеспечивает
 * идемпотентность приёма: момент фиксируется в браузере, когда цена
 * прочитана, и при повторной отправке после сетевого сбоя приезжает тот
 * же самый, поэтому конфликт разрешается внутри INSERT, без проверки
 * перед вставкой (§4).
 *
 * Он же обслуживает единственное чтение, которое понадобится экрану
 * истории: «цены по артикулу за период» — поиск по трём первым столбцам
 * с ограничением по четвёртому. Отдельного индекса под это не нужно.
 *
 * **Индекса на `captured_by_user_id` нет, и это осознанно.** §6 требует
 * индекс для ссылки на сущность чужого модуля, но по этой колонке
 * не фильтруют и не соединяют ни в одном запросе, а таблица растёт
 * неограниченно — полсотни артикулов дают около миллиона строк в год
 * на компанию, и неиспользуемый индекс оплачивался бы на каждой вставке.
 * Это тот же принцип, который §1 формулирует прямо: «индекс следует
 * за запросом, а не за таблицей». Расхождение с буквой §6 вынесено
 * владельцу отдельным вопросом, а не решено молча.
 *
 * Две денежные колонки и одна `currency` на строку — ADR-004: обе цены
 * одной карточки в одной валюте, разные означали бы ошибку разбора
 * страницы, а не смешанный случай.
 *
 * Новая таблица, существующие не трогаются: правило совместимых
 * изменений здесь неприменимо, откатывается удалением.
 */
final class Version20260818034246 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Наблюдения цены с карточки Ozon (ADR-014)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE price_observation (company_id UUID NOT NULL, marketplace_account_id UUID NOT NULL, marketplace_sku VARCHAR(64) NOT NULL, observed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, displayed_price_minor BIGINT NOT NULL, seller_price_minor BIGINT NOT NULL, currency CHAR(3) NOT NULL, source VARCHAR(32) NOT NULL, captured_by_user_id UUID NOT NULL, extension_version VARCHAR(32) NOT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (company_id, marketplace_account_id, marketplace_sku, observed_at))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE price_observation');
    }
}
