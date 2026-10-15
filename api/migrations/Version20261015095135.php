<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Восстановление пароля (ADR-037): дописываемая таблица одноразовых ссылок
 * сброса. Хранится только SHA-256 токена; индекс по user_id — для отмены
 * прежних ссылок при выпуске новой. Новая таблица — боевые данные
 * не затрагиваются.
 *
 * down() рабочий: таблица содержит только короткоживущие ссылки,
 * их потеря означает лишь повторный запрос сброса.
 */
final class Version20261015095135 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add password reset token table (ADR-037)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE password_reset_token (id UUID NOT NULL, user_id UUID NOT NULL, token_hash CHAR(64) NOT NULL, issued_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, consumed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_password_reset_token_user_id ON password_reset_token (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_password_reset_token_hash ON password_reset_token (token_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE password_reset_token');
    }
}
