<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Межарендаторное перечисление для планировщика синхронизации
 * (CLAUDE.md §1, «Исключение — межарендаторное чтение...»; тот же
 * приём, что у UserCompaniesQuery — DBAL-запрос вне репозитория,
 * не метод MarketplaceAccountRepository: интерфейс репозитория
 * остаётся без исключений, каждый его метод по-прежнему требует
 * companyId).
 *
 * build() отдаёт QueryBuilder, не массив (CLAUDE.md §5) — выполнение
 * и сборка результата в DTO — дело вызывающего кода (IdentityFacade).
 * Лимит запрошен на единицу больше MAX_RESULTS: если вернулось больше
 * MAX_RESULTS строк, это сигнал, что реальных подключений стало больше
 * защитного потолка, и вызывающий код обязан отреагировать громко
 * (исключение, не тихая отдача первых 200 — часть компаний тогда
 * молча перестала бы синхронизироваться).
 */
final readonly class ActiveOzonAccountsQuery
{
    public const int MAX_RESULTS = 200;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function build(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('company_id', 'id')
            ->from('marketplace_account')
            ->where('marketplace = :marketplace')
            ->andWhere('state = :state')
            ->setParameter('marketplace', 'ozon')
            ->setParameter('state', 'active')
            ->setMaxResults(self::MAX_RESULTS + 1);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): ActiveOzonAccountRow
    {
        return new ActiveOzonAccountRow(
            companyId: self::stringValue($row['company_id']),
            marketplaceAccountId: self::stringValue($row['id']),
        );
    }

    private static function stringValue(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string value in an active marketplace account row.');
        }

        return $value;
    }
}
