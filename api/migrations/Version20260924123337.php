<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Сырьё в S3, этап 2 (ADR-024): тело новых документов — объект
 * в хранилище, в строке — ключ и размер, body = NULL.
 *
 * Все шаги — метаданные таблицы, без перезаписи строк: новые колонки
 * необязательны, снятие NOT NULL мгновенно. Совместима со старым кодом:
 * он пишет body, а новых колонок не знает. Поэтому применяется до выкладки
 * нового кода (гейт doctrine:migrations:up-to-date).
 *
 * CHECK держит инвариант «тело есть хотя бы в одном месте»: строка
 * без body и без ключа — потерянное сырьё, и база не должна его принять.
 * Doctrine CHECK-ограничения не видит, migrations:diff его не тронет.
 *
 * down() — рабочий, пока нет строк с body = NULL. После того как новый код
 * записал хоть одну такую строку, **откат только восстановлением**: тела
 * этих строк есть только в S3, и вернуть NOT NULL нельзя, не перенеся их
 * обратно в базу. По той же причине после этого запрещён и откат образа
 * ниже этапа 2 — старый код строку с body = NULL не читает; аварийный
 * путь — RAW_BODY_STORE=database (docs/operations-checklist.md).
 */
final class Version20260924123337 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Сырьё в S3: ключ и размер объекта, body необязательно';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_raw_document ADD storage_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE marketplace_raw_document ADD byte_size BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE marketplace_raw_document ALTER body DROP NOT NULL');
        $this->addSql('ALTER TABLE marketplace_raw_document ADD CONSTRAINT chk_marketplace_raw_document_body_somewhere CHECK (body IS NOT NULL OR storage_key IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_raw_document DROP CONSTRAINT chk_marketplace_raw_document_body_somewhere');
        $this->addSql('ALTER TABLE marketplace_raw_document DROP storage_key');
        $this->addSql('ALTER TABLE marketplace_raw_document DROP byte_size');
        $this->addSql('ALTER TABLE marketplace_raw_document ALTER body SET NOT NULL');
    }
}
