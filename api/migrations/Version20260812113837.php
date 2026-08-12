<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260812113837 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'extension_token: учётные данные расширения браузера по ADR-010 (уточняет ADR-007). Хранится только sha256 секрета — уникальный индекс по нему и есть точка проверки; строки не удаляются, revoked_at/revoked_by_user_id/last_seen_at служат следом выпуска и отзыва.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE extension_token (id UUID NOT NULL, company_id UUID NOT NULL, user_id UUID NOT NULL, token_hash CHAR(64) NOT NULL, token_prefix VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked_by_user_id UUID DEFAULT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_extension_token_company_user ON extension_token (company_id, user_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_extension_token_hash ON extension_token (token_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE extension_token');
    }
}
