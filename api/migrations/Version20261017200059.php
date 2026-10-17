<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * План продаж: шапка версии и строки SKU × день (ADR-040 п. 3).
 *
 * Таблица занятых дней (п. 9) сюда не входит: её пишет утверждение,
 * и она появится вместе с ним (этап 0.7), а не вперёд.
 *
 * Ключи. `sales_plan` — суррогатный id: запрет суррогата из CLAUDE.md §2
 * написан для фактов, а план вводит человек. `sales_plan_line` —
 * тоже суррогатный, естественный ключ `(company_id, sales_plan_id,
 * marketplace_sku, plan_date)` — уникальным индексом: дата в составном
 * идентификаторе Doctrine не годится в ключ карты идентичности.
 * Тот же индекс обслуживает чтение строк плана — отдельного не нужно.
 *
 * `company_id` первым столбцом каждого составного индекса (§1).
 * Ссылки на сущности Identity — `marketplace_account_id`,
 * `created_by_user_id` — получают индекс в этой же миграции (§6).
 *
 * Частичные уникальные индексы по линии — не больше одного черновика
 * и одного утверждённого (п. 8). Условие записано в той форме, которую
 * возвращает интроспекция PostgreSQL (patterns.md, «Частичный
 * уникальный индекс»), иначе migrations:diff предлагал бы пересоздание.
 *
 * CHECK держат инварианты, которые домен проверяет и так: статус,
 * порядок дат, неотрицательные штуки, положительная цена. Длины периода
 * среди них нет: это стартовый лимит (п. 13), обратимая константа,
 * и её смена не должна требовать миграции.
 * Домен — первая линия, база — последняя: строка в обход домена
 * (ручной SQL, будущий DBAL-путь) не нарушит их молча. ORM их
 * не описывает — schema:validate и migrations:diff их не видят.
 *
 * Новые таблицы, существующие не трогаются: правило совместимых
 * изменений неприменимо, down() откатывает удалением.
 */
final class Version20261017200059 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Планирование продаж: sales_plan и sales_plan_line (ADR-040).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sales_plan (id UUID NOT NULL, company_id UUID NOT NULL, marketplace_account_id UUID NOT NULL, name VARCHAR(200) NOT NULL, date_from DATE NOT NULL, date_to DATE NOT NULL, currency CHAR(3) NOT NULL, status VARCHAR(16) NOT NULL, lineage_id UUID NOT NULL, version_number INT NOT NULL, created_by_user_id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, version INT DEFAULT 1 NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_sales_plan_company_account ON sales_plan (company_id, marketplace_account_id)');
        $this->addSql('CREATE INDEX idx_sales_plan_company_created_by ON sales_plan (company_id, created_by_user_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_sales_plan_lineage_draft ON sales_plan (company_id, lineage_id) WHERE ((status)::text = \'draft\'::text)');
        $this->addSql('CREATE UNIQUE INDEX uq_sales_plan_lineage_approved ON sales_plan (company_id, lineage_id) WHERE ((status)::text = \'approved\'::text)');
        $this->addSql(<<<'SQL'
            ALTER TABLE sales_plan ADD CONSTRAINT chk_sales_plan_status
                CHECK (status IN ('draft', 'approved', 'superseded', 'discarded', 'cancelled'))
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sales_plan ADD CONSTRAINT chk_sales_plan_period
                CHECK (date_from <= date_to)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sales_plan ADD CONSTRAINT chk_sales_plan_version_number
                CHECK (version_number >= 1)
            SQL);

        $this->addSql('CREATE TABLE sales_plan_line (id UUID NOT NULL, company_id UUID NOT NULL, sales_plan_id UUID NOT NULL, marketplace_sku VARCHAR(64) NOT NULL, plan_date DATE NOT NULL, quantity INT NOT NULL, price_minor BIGINT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_sales_plan_line_plan_sku_date ON sales_plan_line (company_id, sales_plan_id, marketplace_sku, plan_date)');
        $this->addSql(<<<'SQL'
            ALTER TABLE sales_plan_line ADD CONSTRAINT chk_sales_plan_line_quantity
                CHECK (quantity >= 0)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE sales_plan_line ADD CONSTRAINT chk_sales_plan_line_price
                CHECK (price_minor > 0)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sales_plan_line');
        $this->addSql('DROP TABLE sales_plan');
    }
}
