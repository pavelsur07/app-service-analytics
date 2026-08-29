<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Второй актор аудит-журнала: администратор системного контура (ADR-017).
 *
 * Журнал заведён под события продавца, и обе ссылки в нём — на арендатора
 * и на пользователя арендатора — были обязательными. У событий раздела
 * Admin это не так, и по-разному:
 *
 * - «заведён Admin» не относится ни к какой компании: администратор
 *   не принадлежит арендатору, поэтому company_id становится nullable;
 * - актор такого события живёт не в таблице `user`, а в таблице
 *   администраторов (ADR-007: две таблицы, признак администратора
 *   не выражается флагом в общей). Поэтому actor_user_id становится
 *   nullable, а рядом появляется actor_admin_id.
 *
 * Одна колонка «actor_id» на оба контура была бы короче и неверна:
 * uuid сам по себе не говорит, в какой таблице искать человека, а две
 * таблицы пользователей — решение ADR-007, которое здесь не отменяется.
 *
 * CHECK, а не проверка в коде: «ровно один актор» — инвариант строки,
 * и держать его должна база. Проверка в приложении защитой не является
 * по той же причине, по которой ею не является проверка перед вставкой
 * (CLAUDE.md §4) — мимо неё всегда есть путь.
 *
 * Внешнего ключа на таблицу администраторов нет: её ещё не существует
 * (Stage 2), и FK в этом модуле не используются нигде. Миграция
 * от неё не зависит и применяется отдельно.
 *
 * Индекса на actor_admin_id нет, и это не забывчивость: чтения у журнала
 * сегодня нет вообще — в AuditRecordRepository только addToUnitOfWork,
 * экрана журнала не существует. Индекс следует за запросом, а не
 * за таблицей (CLAUDE.md §1); появится запрос «что делал этот
 * администратор» — появится и индекс, вместе с ним.
 *
 * idx_audit_record_company_occurred остаётся как был, ведущим
 * по company_id: строки без компании в него попадут с NULL и мешать
 * не будут — их единицы против записей арендаторов.
 *
 * CHECK в маппинге Doctrine не отражён (ORM их не описывает), поэтому
 * doctrine:schema:validate его не видит и migrations:diff не пытается
 * ни создать, ни удалить. Ограничение живёт только здесь — снести его
 * можно лишь новой миграцией, что и требуется.
 *
 * Блокировка короткая: ALTER ... DROP NOT NULL меняет только метаданные,
 * добавление nullable-колонки без DEFAULT в PostgreSQL 11+ таблицу
 * не переписывает. Проверка CHECK читает таблицу целиком один раз —
 * на текущем объёме журнала это доли секунды.
 *
 * down() рабочий: строк с NULL до Stage 3 не появляется, обратные
 * SET NOT NULL проходят.
 */
final class Version20260829053603 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'audit_record: актор-администратор и событие без компании (ADR-017).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_record ALTER company_id DROP NOT NULL');
        $this->addSql('ALTER TABLE audit_record ALTER actor_user_id DROP NOT NULL');
        $this->addSql('ALTER TABLE audit_record ADD actor_admin_id UUID DEFAULT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE audit_record ADD CONSTRAINT chk_audit_record_single_actor
                CHECK ((actor_user_id IS NULL) <> (actor_admin_id IS NULL))
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_record DROP CONSTRAINT chk_audit_record_single_actor');
        $this->addSql('ALTER TABLE audit_record DROP actor_admin_id');
        $this->addSql('ALTER TABLE audit_record ALTER actor_user_id SET NOT NULL');
        $this->addSql('ALTER TABLE audit_record ALTER company_id SET NOT NULL');
    }
}
