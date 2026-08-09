<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Список компаний пользователя — не company-scoped: это сам источник,
 * из какого companyId выбирать (CLAUDE.md §5 — DBAL, не гидрация Company).
 * companyId первым параметром не требуется по той же причине, что
 * у UserRepository::findByEmail — межарендаторный запрос по своей природе.
 */
final readonly class UserCompaniesQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<UserCompanyRow>
     */
    public function forUser(string $userId): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('c.id', 'c.name')
            ->from('company', 'c')
            ->innerJoin('c', 'company_member', 'cm', 'cm.company_id = c.id')
            ->where('cm.user_id = :userId')
            ->orderBy('c.name')
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(self::mapRow(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function mapRow(array $row): UserCompanyRow
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
