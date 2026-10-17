<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Listings;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Каталог одного подключения для выбора SKU в план продаж (ADR-040 п. 4).
 * DBAL в readonly DTO, без гидрации (CLAUDE.md §5); build() отдаёт
 * QueryBuilder, выполнение — у Facade.
 *
 * Только каталог, без истории продаж, в отличие от CompanySkusQuery:
 * планируют то, что продавец может продать сейчас, а план — на
 * подключение, поэтому и каталог подключения, а не компании целиком.
 *
 * Компания **и** подключение в условии: подключение чужой компании
 * даёт пустой ответ, а не её артикулы (§1). Оба столбца — префикс
 * первичного ключа каталога, курсор по артикулу идёт по нему же.
 */
final readonly class AccountCatalogSkuSearchQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function build(string $companyId, string $marketplaceAccountId, string $search, ?string $cursor, int $limit): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('marketplace_sku', 'offer_id', 'name')
            ->from('marketplace_listing')
            ->where('company_id = :companyId')
            ->andWhere('marketplace_account_id = :accountId')
            ->setParameter('companyId', $companyId)
            ->setParameter('accountId', $marketplaceAccountId)
            ->orderBy('marketplace_sku', 'ASC')
            // +1 — узнать, есть ли следующая страница, без COUNT(*) (§5).
            ->setMaxResults($limit + 1);

        if ('' !== $search) {
            // Ввод — буквальный текст: `%` и `_` в нём ищутся как символы,
            // а не становятся шаблоном, который совпадает со всем.
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
            $qb->andWhere("(marketplace_sku ILIKE :pattern ESCAPE '!' OR offer_id ILIKE :pattern ESCAPE '!' OR name ILIKE :pattern ESCAPE '!')")
                ->setParameter('pattern', '%'.$escaped.'%');
        }

        if (null !== $cursor) {
            $qb->andWhere('marketplace_sku > :cursor')->setParameter('cursor', $cursor);
        }

        return $qb;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): AccountCatalogSkuRow
    {
        $sku = $row['marketplace_sku'];
        $offerId = $row['offer_id'];
        $name = $row['name'];
        if (!\is_string($sku) || (null !== $offerId && !\is_string($offerId)) || (null !== $name && !\is_string($name))) {
            throw new \UnexpectedValueException('Unexpected column types in a catalog SKU row.');
        }

        return new AccountCatalogSkuRow($sku, $offerId, $name);
    }
}
