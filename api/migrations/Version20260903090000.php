<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Короткие ссылки и неизменяемые события переходов (ADR-022).
 *
 * Счётчика в short_link нет: каждый переход остаётся отдельным фактом,
 * чтобы статистику можно было разбить по времени без конкурентной записи
 * в строку, обслуживающую публичный redirect.
 */
final class Version20260903090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Links: короткие ссылки и сырые события переходов (ADR-022).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE short_link (
                id UUID NOT NULL,
                code VARCHAR(7) NOT NULL,
                name VARCHAR(120) NOT NULL,
                target_url VARCHAR(2048) NOT NULL,
                status VARCHAR(16) NOT NULL,
                version INT DEFAULT 1 NOT NULL,
                created_by_admin_id UUID NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT chk_short_link_status CHECK (status IN ('active', 'disabled'))
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_short_link_code ON short_link (code)');
        $this->addSql('CREATE INDEX idx_short_link_created ON short_link (created_at, id)');
        $this->addSql('CREATE INDEX idx_short_link_created_by ON short_link (created_by_admin_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE short_link_click (
                id UUID NOT NULL,
                short_link_id UUID NOT NULL,
                clicked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                user_agent VARCHAR(1024) DEFAULT NULL,
                referer VARCHAR(2048) DEFAULT NULL,
                is_bot BOOLEAN NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_short_link_click_link_time ON short_link_click (short_link_id, clicked_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE short_link_click');
        $this->addSql('DROP TABLE short_link');
    }
}
