<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * История цены, выставленной продавцом в кабинете Ozon (ADR-015).
 *
 * **В первичном ключе — raw-документ, а не момент наблюдения.** Так
 * вставка идемпотентна по уникальному индексу, как требует CLAUDE.md §4:
 * повторная обработка того же ответа площадки — ретраем ли, параллельным
 * ли прогоном — даёт тот же документ (ADR-006 дедуплицирует сырьё
 * по содержимому) и упирается в ключ, а не в проверку существования.
 * Он же закрывает требование ADR-006 о прослеживаемости: строка факта
 * знает, из какого сырья получена.
 *
 * `changed_at` — обычная колонка: момент, когда значение впервые
 * увидели. Под чтение «какая цена действовала на момент наблюдения»
 * заведён отдельный индекс — первичный ключ его не обслуживает,
 * там на четвёртом месте документ, а не время.
 *
 * Цена действует до следующей строки; дата окончания не хранится
 * по той же причине, что в ADR-013 — вторая дата рядом с первой
 * рассинхронизируется.
 *
 * `old_price_minor` допускает NULL: у товара без зачёркнутой цены её
 * нет вовсе, и ноль означал бы «скидка до нуля», а не «скидки
 * не объявлено».
 *
 * Новая таблица, существующие не трогаются: правило совместимых
 * изменений неприменимо, откатывается удалением.
 */
final class Version20260818064148 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'История цены продавца в кабинете Ozon (ADR-015)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE marketplace_listing_price (company_id UUID NOT NULL, marketplace_account_id UUID NOT NULL, marketplace_sku VARCHAR(64) NOT NULL, raw_document_id UUID NOT NULL, changed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, price_minor BIGINT NOT NULL, old_price_minor BIGINT DEFAULT NULL, currency CHAR(3) NOT NULL, PRIMARY KEY (company_id, marketplace_account_id, marketplace_sku, raw_document_id))');
        $this->addSql('CREATE INDEX idx_marketplace_listing_price_effective ON marketplace_listing_price (company_id, marketplace_account_id, marketplace_sku, changed_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_listing_price');
    }
}
