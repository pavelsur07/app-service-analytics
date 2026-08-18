<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * История цены, выставленной продавцом в кабинете Ozon (ADR-015).
 *
 * Первичный ключ естественный — (компания, подключение, артикул, момент
 * первого появления значения), суррогата нет (CLAUDE.md §2). Он же
 * обслуживает и запись, и чтение: писателю нужна последняя строка
 * по артикулу, читающему расчёту — ближайшая предыдущая к моменту
 * наблюдения. И то, и другое — обход по трём первым столбцам
 * с ограничением по четвёртому, второго индекса не нужно.
 *
 * `changed_at` — когда мы впервые увидели это значение, а не когда
 * продавец его поменял: второго Ozon не сообщает. Цена действует
 * до следующей строки; дата окончания не хранится по той же причине,
 * что в ADR-013 — вторая дата рядом с первой рассинхронизируется.
 *
 * `old_price_minor` допускает NULL: у товара без зачёркнутой цены её
 * нет вовсе, и ноль здесь означал бы «скидка до нуля», а не «скидки
 * не объявлено».
 *
 * Новая таблица, существующие не трогаются: правило совместимых
 * изменений неприменимо, откатывается удалением.
 */
final class Version20260818062811 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'История цены продавца в кабинете Ozon (ADR-015)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE marketplace_listing_price (company_id UUID NOT NULL, marketplace_account_id UUID NOT NULL, marketplace_sku VARCHAR(64) NOT NULL, changed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, price_minor BIGINT NOT NULL, old_price_minor BIGINT DEFAULT NULL, currency CHAR(3) NOT NULL, PRIMARY KEY (company_id, marketplace_account_id, marketplace_sku, changed_at))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_listing_price');
    }
}
