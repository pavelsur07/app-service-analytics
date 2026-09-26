<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * SKU каталога подключения — по ним запрашиваются остатки (ADR-034).
 * Каталог обновляется каждый тик синхронизации, поэтому лишних запросов
 * к площадке за списком товаров нет. company_id — первым условием (§1).
 */
final readonly class AccountListingSkusQuery
{
    /** Потолок каталога — тот же, что у FetchOzonCatalogHandler: 100 страниц по 1000. */
    public const int MAX_SKUS = 100_000;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<string>
     */
    public function fetch(string $companyId, string $marketplaceAccountId): array
    {
        /** @var list<string> $skus */
        $skus = $this->connection->createQueryBuilder()
            ->select('marketplace_sku')
            ->from('marketplace_listing')
            ->where('company_id = :companyId')
            ->andWhere('marketplace_account_id = :accountId')
            ->setParameter('companyId', $companyId)
            ->setParameter('accountId', $marketplaceAccountId)
            ->orderBy('marketplace_sku')
            ->setMaxResults(self::MAX_SKUS + 1)
            ->executeQuery()
            ->fetchFirstColumn();

        if (\count($skus) > self::MAX_SKUS) {
            // Тихая обрезка дала бы «ноль» по товарам за потолком.
            throw new \RuntimeException(\sprintf('Каталог подключения %s больше %d SKU — снимок остатков не соберётся целиком.', $marketplaceAccountId, self::MAX_SKUS));
        }

        return array_values(array_map(static fn (mixed $sku): string => (string) $sku, $skus));
    }
}
