<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Список компаний пользователя — не company-scoped: это сам источник,
 * из какого companyId выбирать (CLAUDE.md §5 — DBAL, не гидрация Company).
 * userId первым параметром, не companyId, по той же причине, что
 * у UserRepository::findByEmail — межарендаторный запрос по своей природе.
 *
 * build() отдаёт QueryBuilder, не массив (CLAUDE.md §5) — выполнение
 * и сборка результата в DTO — дело вызывающего кода (MeController), как
 * у SalesFactListQuery. Лимит — не курсорная пагинация: членство не растёт
 * с объёмом данных клиента, это справочник ограниченного размера
 * (docs/patterns.md, «Пагинация»), но список без лимита не отдаётся
 * никогда, даже внутренний (CLAUDE.md §5) — 200 как защитный потолок,
 * не как раскрытая наружу страница.
 */
final readonly class UserCompaniesQuery
{
    private const int MAX_RESULTS = 200;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function build(string $userId): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('c.id', 'c.name')
            ->from('company', 'c')
            ->innerJoin('c', 'company_member', 'cm', 'cm.company_id = c.id')
            ->where('cm.user_id = :userId')
            ->orderBy('c.name')
            ->setParameter('userId', $userId)
            ->setMaxResults(self::MAX_RESULTS);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): UserCompanyRow
    {
        return new UserCompanyRow(
            id: self::stringValue($row['id']),
            name: self::stringValue($row['name']),
        );
    }

    private static function stringValue(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string value in a company row.');
        }

        return $value;
    }
}
