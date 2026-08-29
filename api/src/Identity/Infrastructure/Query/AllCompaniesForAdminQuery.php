<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Межарендаторное перечисление клиентских аккаунтов для системного
 * раздела (CLAUDE.md §1, «Исключение — межарендаторное чтение...»,
 * случай «операционные системные задачи»; ADR-017).
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
 * Это третий случай исключения §1, и он отличается от двух прежних:
 * чтение идёт **из HTTP-контроллера**, чего буквальный текст правила
 * не допускал. Правило дополнено тем же изменением, что вводит этот
 * класс, — обходить его молча нельзя.
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
}
