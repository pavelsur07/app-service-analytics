<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Аудит-журнал (ADR-007, CLAUDE.md «Безопасность и аудит»): изменение
 * себестоимости, изменение планов, добавление и изменение учётных данных
 * подключений, вход администратора в данные клиента.
 *
 * Индекс (company_id, occurred_at): журнал читают одним вопросом —
 * «что происходило у этого клиента и когда», — и company_id ведущим
 * столбцом по тому же правилу, что у любых данных компании (§1).
 *
 * Содержимого изменения в таблице нет: для учётных данных оно означало бы
 * хранить рядом либо ключ, либо его остаток. Ценность записи в том, кто
 * и когда действие совершил.
 */
final class Version20260814121128 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'audit_record: аудит-журнал действий над данными компании (ADR-007).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audit_record (id UUID NOT NULL, company_id UUID NOT NULL, actor_user_id UUID NOT NULL, action VARCHAR(64) NOT NULL, subject_id UUID NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_audit_record_company_occurred ON audit_record (company_id, occurred_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE audit_record');
    }
}
