<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Таблица администраторов сервиса (ADR-007, ADR-017).
 *
 * ADR-007 решил это ещё в августе — «два независимых firewall и две
 * таблицы пользователей», флаг администратора в общей таблице отвергнут
 * явно, — но решение не было реализовано. Здесь оно исполняется.
 *
 * Отдельная таблица, а не колонка в `user`: одна забытая проверка
 * отделяет флаг в общей таблице от раскрытия данных всех клиентов
 * (формулировка ADR-007). Раздельные таблицы делают такую ошибку
 * невыразимой — сессия продавца физически не находит строку
 * администратора, потому что провайдеры смотрят в разные таблицы.
 *
 * role — строка, не enum-тип PostgreSQL: добавление третьей роли
 * не должно требовать ALTER TYPE, а перечисление живёт в PHP
 * (AdminRole) и проверяется маппингом.
 *
 * created_by_admin_id nullable ровно для первого SuperAdmin: его заводит
 * консольная команда, автора у него в системе нет по построению.
 * Внешнего ключа нет — как и везде в этом модуле; ссылка на строку
 * той же таблицы, и цикл FK на самого себя ничего бы не добавил.
 *
 * Индекса на created_by_admin_id нет: запроса «кого завёл этот
 * администратор» не существует, а индекс следует за запросом, а не
 * за таблицей. Уникальный индекс по email нужен наоборот всегда —
 * по нему идёт вход, и он же перехватывает повторное заведение
 * на вставке, а не проверкой перед ней (CLAUDE.md §4).
 *
 * Данных для миграции нет: администраторов в системе не существовало,
 * мигрировать нечего — таблица создаётся пустой.
 */
final class Version20260829062716 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'administrator: контур администраторов сервиса с ролями (ADR-007, ADR-017).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE administrator (
                id UUID NOT NULL,
                email VARCHAR(255) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(32) NOT NULL,
                created_by_admin_id UUID DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_administrator_email ON administrator (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE administrator');
    }
}
