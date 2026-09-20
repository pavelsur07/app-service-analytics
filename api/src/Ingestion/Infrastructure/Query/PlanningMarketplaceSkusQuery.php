<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class PlanningMarketplaceSkusQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /** @param list<string> $skus */
    public function known(string $companyId, string $accountId, array $skus): QueryBuilder
    {
        $source = <<<'SQL'
            (
                SELECT marketplace_sku FROM marketplace_listing
                WHERE company_id = :company AND marketplace_account_id = :account AND marketplace_sku IN (:skus)
                UNION
                SELECT marketplace_sku FROM sales_fact
                WHERE company_id = :company AND marketplace_account_id = :account AND marketplace_sku IN (:skus)
            ) known_skus
            SQL;

        return $this->connection->createQueryBuilder()->select('marketplace_sku')->from($source)
            ->setParameter('company', $companyId)->setParameter('account', $accountId)
            ->setParameter('skus', $skus, ArrayParameterType::STRING)
            ->orderBy('marketplace_sku')
            ->setMaxResults(\count($skus));
    }

    public function search(string $companyId, string $accountId, string $search, int $limit, ?string $afterSku): QueryBuilder
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
        $cursorFilterListing = null === $afterSku ? '' : 'AND l.marketplace_sku > :afterSku';
        $cursorFilterHistory = null === $afterSku ? '' : 'AND s.marketplace_sku > :afterSku';
        $source = <<<SQL
            (
                WITH listing_matches AS (
                    SELECT l.marketplace_sku, l.offer_id, l.name FROM marketplace_listing l
                    WHERE l.company_id = :company AND l.marketplace_account_id = :account
                      {$cursorFilterListing}
                      AND (l.marketplace_sku ILIKE :search ESCAPE '\' OR l.offer_id ILIKE :search ESCAPE '\' OR l.name ILIKE :search ESCAPE '\')
                    ORDER BY l.marketplace_sku
                    LIMIT :limit
                ), historical_matches AS (
                    SELECT DISTINCT s.marketplace_sku, NULL::varchar AS offer_id, NULL::varchar AS name
                    FROM sales_fact s
                    WHERE s.company_id = :company AND s.marketplace_account_id = :account
                      {$cursorFilterHistory}
                      AND s.marketplace_sku ILIKE :skuPrefix ESCAPE '\'
                      AND NOT EXISTS (
                          SELECT 1 FROM marketplace_listing l
                          WHERE l.company_id = s.company_id AND l.marketplace_account_id = s.marketplace_account_id
                            AND l.marketplace_sku = s.marketplace_sku
                      )
                    ORDER BY s.marketplace_sku
                    LIMIT :limit
                )
                SELECT marketplace_sku, offer_id, name FROM (
                    SELECT marketplace_sku, offer_id, name FROM listing_matches
                    UNION ALL
                    SELECT marketplace_sku, offer_id, name FROM historical_matches
                ) known
                ORDER BY marketplace_sku
                LIMIT :limit
            ) searched_skus
            SQL;
        $query = $this->connection->createQueryBuilder()->select('marketplace_sku', 'offer_id', 'name')->from($source)
            ->setParameter('company', $companyId)->setParameter('account', $accountId)
            ->setParameter('search', '%'.$escaped.'%')->setParameter('skuPrefix', $escaped.'%')
            ->setParameter('limit', $limit, \Doctrine\DBAL\ParameterType::INTEGER)
            ->orderBy('marketplace_sku')->setMaxResults($limit);
        if (null !== $afterSku) {
            $query->setParameter('afterSku', $afterSku);
        }

        return $query;
    }
}
