<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260813072409 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'marketplace_listing: каталог подключения. Естественный PK (company_id, marketplace_account_id, marketplace_sku) без суррогата — он же покрывает и чтение артикулов компании, и удаление исчезнувших: company_id первым столбцом (CLAUDE.md §1, §6), отдельный индекс на marketplace_account_id не нужен.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE marketplace_listing (company_id UUID NOT NULL, marketplace_account_id UUID NOT NULL, marketplace_sku VARCHAR(64) NOT NULL, first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (company_id, marketplace_account_id, marketplace_sku))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_listing');
    }
}
