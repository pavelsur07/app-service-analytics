<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260809091329 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User + CompanyMember (ADR-002, ADR-007): доступ к данным компании определяется членством, не одним лишь companyId в URL. company_member — составной PK (company_id, user_id), без суррогатного id (ADR-003 — суррогат только сущностям, на которые ссылаются извне).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE company_member (
              company_id UUID NOT NULL,
              user_id UUID NOT NULL,
              role VARCHAR(32) NOT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              PRIMARY KEY (company_id, user_id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_company_member_user_id ON company_member (user_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE "user" (
              id UUID NOT NULL,
              email VARCHAR(255) NOT NULL,
              password_hash VARCHAR(255) NOT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_user_email ON "user" (email)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE company_member');
        $this->addSql('DROP TABLE "user"');
    }
}
