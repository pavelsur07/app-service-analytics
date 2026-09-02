<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Подтверждение email для самостоятельной регистрации (ADR-020).
 *
 * Существующие учётные записи созданы доверенным администратором, поэтому
 * backfill сохраняет им вход. Только новый self-signup пишет NULL.
 */
final class Version20260902120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User confirmation/consent fields and append-only email verification tokens (ADR-020).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD email_confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD legal_consent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD legal_documents_version VARCHAR(32) DEFAULT NULL');
        $this->addSql('UPDATE "user" SET email_confirmed_at = created_at');

        $this->addSql('CREATE TABLE email_verification_token (id UUID NOT NULL, user_id UUID NOT NULL, token_hash CHAR(64) NOT NULL, issued_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, consumed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_email_verification_token_user_id ON email_verification_token (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_email_verification_token_hash ON email_verification_token (token_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE email_verification_token');
        $this->addSql('ALTER TABLE "user" DROP email_confirmed_at');
        $this->addSql('ALTER TABLE "user" DROP legal_consent_at');
        $this->addSql('ALTER TABLE "user" DROP legal_documents_version');
    }
}
