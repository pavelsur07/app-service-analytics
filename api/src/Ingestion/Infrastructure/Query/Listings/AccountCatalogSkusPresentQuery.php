<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Listings;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Какие SKU из переданного набора есть в каталоге подключения
 * (ADR-040 п. 4): создание плана и импорт проверяют весь набор одним
 * запросом, а не SKU за запросом (CLAUDE.md §6) и не обходом страниц.
 *
 * Поиск по первичному ключу каталога `(company_id,
 * marketplace_account_id, marketplace_sku)`; компания и подключение
 * в условии — чужое подключение даёт пустой ответ (§1).
 *
 * Лимит ответа — сам набор: вызывающий ограничивает его размер
 * (Facade), и больше строк, чем SKU во входе, запрос не вернёт.
 */
final readonly class AccountCatalogSkusPresentQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param non-empty-list<string> $skus
     */
    public function build(string $companyId, string $marketplaceAccountId, array $skus): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('marketplace_sku')
            ->from('marketplace_listing')
            ->where('company_id = :companyId')
            ->andWhere('marketplace_account_id = :accountId')
            ->andWhere('marketplace_sku IN (:skus)')
            ->setParameter('companyId', $companyId)
            ->setParameter('accountId', $marketplaceAccountId)
            ->setParameter('skus', $skus, ArrayParameterType::STRING)
            ->orderBy('marketplace_sku', 'ASC');
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): CompanySkuRow
    {
        $value = $row['marketplace_sku'];
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string marketplace_sku.');
        }

        return new CompanySkuRow($value);
    }
}
