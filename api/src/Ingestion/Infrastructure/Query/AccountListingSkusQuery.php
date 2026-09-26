<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * SKU каталога подключения — по ним запрашиваются остатки (ADR-034).
 * Каталог обновляется каждый тик синхронизации, поэтому лишних запросов
 * к площадке за списком товаров нет. company_id — первым условием (§1).
 * Отдаёт QueryBuilder (CLAUDE.md §5); +1 к потолку — чтобы вызывающий
 * отличил полный каталог от обрезанного.
 */
final readonly class AccountListingSkusQuery
{
    /** Потолок каталога — тот же, что у FetchOzonCatalogHandler: 100 страниц по 1000. */
    public const int MAX_SKUS = 100_000;

    public function __construct(private Connection $connection)
    {
    }

    public function build(string $companyId, string $marketplaceAccountId): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('marketplace_sku')
            ->from('marketplace_listing')
            ->where('company_id = :companyId')
            ->andWhere('marketplace_account_id = :accountId')
            ->setParameter('companyId', $companyId)
            ->setParameter('accountId', $marketplaceAccountId)
            ->orderBy('marketplace_sku')
            ->setMaxResults(self::MAX_SKUS + 1);
    }

    /**
     * @param list<mixed> $column
     *
     * @return list<string>
     */
    public static function mapColumn(array $column, string $marketplaceAccountId): array
    {
        if (\count($column) > self::MAX_SKUS) {
            // Тихая обрезка дала бы «ноль» по товарам за потолком.
            throw new \RuntimeException(\sprintf('Каталог подключения %s больше %d SKU — снимок остатков не соберётся целиком.', $marketplaceAccountId, self::MAX_SKUS));
        }

        return array_map(static function (mixed $sku): string {
            if (!\is_string($sku)) {
                throw new \UnexpectedValueException('Expected a string SKU in marketplace_listing.');
            }

            return $sku;
        }, $column);
    }
}
