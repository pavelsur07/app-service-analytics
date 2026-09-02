<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Межарендаторное перечисление клиентских аккаунтов для системного
 * раздела (CLAUDE.md §1, «Исключение — межарендаторное чтение...»,
 * третий случай — «системный контур»; ADR-017, ADR-018).
 *
 * Имя говорит о природе запроса прямо: не `list`, не `findAll`, а
 * «все компании для администратора» — прочитав вызов, видно, что здесь
 * нет и не может быть companyId.
 *
 * **Недостижим из Ui продавца.** Узкий слой Deptrac
 * (api/deptrac.php, IdentityAdminAccountsQuery) выведен из широкого
 * IdentityInfrastructure через mustNot и выдан ровно одному классу —
 * ListClientAccountsController, который сам вынесен в узкий
 * IdentityAdminAccountsUi. Широкий IdentityUi, у которого есть доступ
 * к IdentityInfrastructure ради MeController, этого запроса не видит.
 *
 * Третий случай отличается от двух прежних одним признаком: чтение идёт
 * **из HTTP-контроллера**, чего буквальный текст правила не допускал.
 * Правило дополнено тем же изменением, что вводит этот класс, а решение
 * записано в ADR-018 — ссылаться на ADR вместо правила было бы тем же
 * обходом, только длиннее.
 *
 * build() отдаёт QueryBuilder, не массив (CLAUDE.md §5): страницу
 * и подсчёт строит вызывающий, иначе пагинация оказалась бы зашита
 * в запрос.
 */
final readonly class AllCompaniesForAdminQuery
{
    public const int DEFAULT_LIMIT = 50;
    public const int MAX_LIMIT = 200;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function build(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('c.id', 'c.name', 'c.status', 'c.created_at')
            ->addSelect(<<<'SQL'
                EXISTS (
                    SELECT 1
                    FROM company_member cm
                    JOIN "user" u ON u.id = cm.user_id
                    WHERE cm.company_id = c.id
                      AND u.email_confirmed_at IS NOT NULL
                ) AS has_confirmed_user
                SQL)
            ->from('company', 'c')
            // Свежие сверху: администратор заходит сюда после
            // регистрации, а не листать алфавит. id вторым ключом —
            // без него порядок строк с совпавшей секундой не определён,
            // и страницы могли бы повторять или терять записи.
            ->orderBy('c.created_at', 'DESC')
            ->addOrderBy('c.id', 'DESC');
    }

    /**
     * COUNT(*) здесь допустим: `company` — не таблица фактов, а
     * справочник, растущий числом клиентов, а не объёмом их данных
     * (CLAUDE.md §5 запрещает COUNT именно на фактах).
     */
    public function countAll(): int
    {
        $total = $this->connection->fetchOne('SELECT count(*) FROM company');

        if (!is_numeric($total)) {
            throw new \UnexpectedValueException('Expected a numeric count of companies.');
        }

        return (int) $total;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): AdminCompanyRow
    {
        return new AdminCompanyRow(
            id: self::stringValue($row['id']),
            name: self::stringValue($row['name']),
            status: self::stringValue($row['status']),
            createdAt: self::dateValue($row['created_at']),
            hasConfirmedUser: self::boolValue($row['has_confirmed_user']),
        );
    }

    private static function stringValue(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string value in a company row.');
        }

        return $value;
    }

    private static function dateValue(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string date in a company row.');
        }

        return (new \DateTimeImmutable($value))->format(\DATE_ATOM);
    }

    private static function boolValue(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (\in_array($value, [1, '1', 't', 'true'], true)) {
            return true;
        }

        if (\in_array($value, [0, '0', 'f', 'false'], true)) {
            return false;
        }

        throw new \UnexpectedValueException('Expected a boolean value in a company row.');
    }
}
