<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025192000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Administrator TOTP enrollment and single-use recovery codes (ADR-045)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE administrator_mfa (administrator_id UUID NOT NULL, encrypted_secret TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, enabled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_used_step BIGINT DEFAULT NULL, version INT DEFAULT 1 NOT NULL, PRIMARY KEY (administrator_id))');
        $this->addSql('CREATE TABLE administrator_mfa_recovery_code (id UUID NOT NULL, administrator_id UUID NOT NULL, code_hash CHAR(64) NOT NULL, consumed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_admin_mfa_code_admin ON administrator_mfa_recovery_code (administrator_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_admin_mfa_code_hash ON administrator_mfa_recovery_code (code_hash)');
    }

    public function down(Schema $schema): void
    {
        // Откат к прежнему приложению снимает MFA. Для сохранения защиты
        // сначала закрыть admin-контуру внешний доступ; секреты/хэши
        // восстанавливать только из зашифрованной резервной копии.
        $this->addSql('DROP TABLE administrator_mfa_recovery_code');
        $this->addSql('DROP TABLE administrator_mfa');
    }
}
