<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Аудит-журнал и версия подключения — вместе, потому что появились
 * ради одного сценария: клиент меняет ключи подключения сам.
 *
 * audit_record (ADR-007, CLAUDE.md «Безопасность и аудит»): изменение
 * себестоимости, изменение планов, добавление и изменение учётных данных
 * подключений, вход администратора в данные клиента. Индекс
 * (company_id, occurred_at) — журнал читают одним вопросом «что
 * происходило у этого клиента и когда», company_id ведущим столбцом
 * по тому же правилу, что у любых данных компании (§1).
 *
 * previous_value/new_value — «было» и «стало», обязательные по ADR-011
 * для данных, которые правятся на месте. Для секретов там отпечаток,
 * а не значение: ключ в журнале был бы тем же секретом, только в таблице
 * без шифрования.
 *
 * marketplace_account.version — оптимистическая блокировка (ADR-008):
 * учётные данные вводит человек и правит на месте, без версии второй
 * сохранивший молча затирает первого. DEFAULT 1 закрывает существующие
 * строки; NOT NULL — версия не бывает неизвестной.
 */
final class Version20260814123808 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'audit_record (ADR-007) и marketplace_account.version (ADR-008) — под замену ключей клиентом.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audit_record (id UUID NOT NULL, company_id UUID NOT NULL, actor_user_id UUID NOT NULL, action VARCHAR(64) NOT NULL, subject_id UUID NOT NULL, previous_value TEXT DEFAULT NULL, new_value TEXT DEFAULT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_audit_record_company_occurred ON audit_record (company_id, occurred_at)');
        $this->addSql('ALTER TABLE marketplace_account ADD version INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE audit_record');
        $this->addSql('ALTER TABLE marketplace_account DROP version');
    }
}
