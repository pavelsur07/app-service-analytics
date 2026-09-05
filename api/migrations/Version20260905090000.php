<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Онбординг (ADR-021): название магазина у подключения и глобальная
 * уникальность кабинета.
 *
 * marketplace_account — справочник в единицы строк, а не факт-таблица,
 * поэтому три шага по колонке идут одной миграцией: правило совместимых
 * изменений писано про таблицы с миллионами строк, где ALTER блокирует
 * запись на минуты.
 */
final class Version20260905090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Название магазина и глобальная уникальность кабинета Ozon';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_account ADD name VARCHAR(255) DEFAULT NULL');
        // Существующие подключения заведены нами вручную, названия у них
        // не было: идентификатор кабинета — единственное осмысленное
        // значение, которое мы про них знаем.
        $this->addSql('UPDATE marketplace_account SET name = external_shop_id WHERE name IS NULL');
        $this->addSql('ALTER TABLE marketplace_account ALTER COLUMN name SET NOT NULL');

        // Старый индекс (company_id, marketplace, external_shop_id) был
        // безусловным: отзыв освобождал кабинет для чужой компании (через
        // новый индекс ниже), но навсегда занимал его для своей же —
        // асимметрия наизнанку. Пересоздаём с тем же условием, что и у
        // нового индекса (ADR-011: отзыв необратим).
        $this->addSql('DROP INDEX uq_marketplace_account_company_marketplace_external_shop');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uq_marketplace_account_company_marketplace_external_shop
                ON marketplace_account (company_id, marketplace, external_shop_id)
                WHERE state <> 'revoked'
            SQL);

        // Условие WHERE обязательно: отзыв необратим (ADR-011),
        // и безусловный индекс занял бы кабинет навсегда.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uq_marketplace_account_marketplace_external_shop_active
                ON marketplace_account (marketplace, external_shop_id)
                WHERE state <> 'revoked'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uq_marketplace_account_marketplace_external_shop_active');

        // Возвращаем старый индекс в исходное безусловное состояние.
        $this->addSql('DROP INDEX uq_marketplace_account_company_marketplace_external_shop');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uq_marketplace_account_company_marketplace_external_shop
                ON marketplace_account (company_id, marketplace, external_shop_id)
            SQL);

        $this->addSql('ALTER TABLE marketplace_account DROP name');
    }
}
