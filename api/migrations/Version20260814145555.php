<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * marketplace_expense_fact — расходы площадки (ADR-012).
 *
 * Ключ той же формы, что у всех факт-таблиц (§2, ADR-006):
 * (company_id, marketplace_account_id, source_row_id), где source_row_id
 * склеен из accrual_id, артикула и типа начисления. Суррогата нет.
 *
 * marketplace_sku NOT NULL: у расходов без товара — реклама, хранение —
 * там пустая строка. NULL в первичном ключе PostgreSQL не допускает,
 * а значение входит в склейку, и пустое место в ней должно быть
 * однозначным.
 *
 * Индексы под то, чем экран будет пользоваться: (company_id,
 * business_date) — расходы за период, (company_id, marketplace_sku) —
 * экономика товара, raw_document_id — прослеживаемость строки
 * до сырого ответа (ADR-006). company_id первым столбцом везде (§1).
 */
final class Version20260814145555 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'marketplace_expense_fact: расходы площадки, естественный PK (company_id, marketplace_account_id, source_row_id) по ADR-012.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE marketplace_expense_fact (company_id UUID NOT NULL, marketplace_account_id UUID NOT NULL, source_row_id TEXT NOT NULL, business_date DATE NOT NULL, marketplace_sku VARCHAR(64) NOT NULL, fee_type_id INT NOT NULL, unit_number VARCHAR(64) NOT NULL, amount_minor BIGINT NOT NULL, currency CHAR(3) NOT NULL, raw_document_id UUID NOT NULL, row_hash VARCHAR(64) NOT NULL, first_loaded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (company_id, marketplace_account_id, source_row_id))');
        $this->addSql('CREATE INDEX idx_expense_fact_company_business_date ON marketplace_expense_fact (company_id, business_date)');
        $this->addSql('CREATE INDEX idx_expense_fact_company_sku ON marketplace_expense_fact (company_id, marketplace_sku)');
        $this->addSql('CREATE INDEX idx_expense_fact_raw_document_id ON marketplace_expense_fact (raw_document_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_expense_fact');
    }
}
