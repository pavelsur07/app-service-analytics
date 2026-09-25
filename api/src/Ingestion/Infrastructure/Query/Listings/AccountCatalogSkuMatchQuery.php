<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Listings;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Проба рекламного ключа (ADR-026, п. 1): есть ли у подключения каталог
 * и встречается ли в нём хоть один из SKU рекламных кампаний.
 *
 * Два ответа, а не один: пустой каталог (ключ вводят до первой загрузки)
 * означает «проверять не с чем», а не «ключ чужой».
 *
 * Два EXISTS по первичному ключу (company_id, marketplace_account_id,
 * marketplace_sku), без COUNT и без чтения каталога целиком.
 */
final readonly class AccountCatalogSkuMatchQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param list<string> $skus
     */
    public function build(string $companyId, string $marketplaceAccountId, array $skus): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'EXISTS (SELECT 1 FROM marketplace_listing WHERE company_id = :companyId AND marketplace_account_id = :accountId) AS has_catalog',
                'EXISTS (SELECT 1 FROM marketplace_listing WHERE company_id = :companyId AND marketplace_account_id = :accountId AND marketplace_sku IN (:skus)) AS matched',
            )
            ->setParameter('companyId', $companyId)
            ->setParameter('accountId', $marketplaceAccountId)
            ->setParameter('skus', $skus, ArrayParameterType::STRING);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): AccountCatalogSkuMatchRow
    {
        $hasCatalog = $row['has_catalog'];
        $matched = $row['matched'];
        if (!\is_bool($hasCatalog) || !\is_bool($matched)) {
            throw new \UnexpectedValueException('Expected boolean flags in a catalog match row.');
        }

        return new AccountCatalogSkuMatchRow($hasCatalog, $matched);
    }
}
