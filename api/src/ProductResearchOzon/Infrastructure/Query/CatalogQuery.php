<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Infrastructure\Query;

use App\ProductResearchOzon\Domain\CatalogSyncFailure;
use App\ProductResearchOzon\Domain\ValueObject\CatalogMetadata;
use App\ProductResearchOzon\Infrastructure\Repository\DbalCatalogProjectionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class CatalogQuery
{
    public function __construct(private Connection $db)
    {
    }

    public function search(string $companyId, string $sourceId, string $text, int $limit): QueryBuilder
    {
        if ($limit < 1 || $limit > 200) {
            throw new \InvalidArgumentException('invalid_limit');
        }
        $pattern = '%'.strtr($text, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';

        return $this->base($companyId, $sourceId)
            ->leftJoin('c', 'product_research_ozon_category', 'e', "e.company_id=c.company_id AND e.source_connection_id=c.source_connection_id AND e.path ILIKE :pattern ESCAPE '!'")
            ->setParameter('pattern', $pattern)->orderBy('e.path COLLATE "C"')->setMaxResults($limit);
    }

    public function category(string $companyId, string $sourceId, string $path): QueryBuilder
    {
        return $this->base($companyId, $sourceId)
            ->leftJoin('c', 'product_research_ozon_category', 'e', 'e.company_id=c.company_id AND e.source_connection_id=c.source_connection_id AND e.path_key=:key AND e.path=:path')
            ->setParameter('key', hash('sha256', $path))->setParameter('path', $path)->setMaxResults(1);
    }

    private function base(string $companyId, string $sourceId): QueryBuilder
    {
        return $this->db->createQueryBuilder()->select('c.revision', 'c.generated_at', 'e.path')
            ->from('product_research_ozon_catalog', 'c')->where('c.company_id=:company')->andWhere('c.source_connection_id=:source')
            ->setParameter('company', $companyId)->setParameter('source', $sourceId);
    }

    /** @param array<string,mixed>|false $row */
    public static function metadata(array|false $row): ?CatalogMetadata
    {
        if (false === $row || null === ($row['generated_at'] ?? null)) {
            return null;
        }

        return DbalCatalogProjectionRepository::map($row);
    }

    /** @param array<string,mixed> $row */
    public static function path(array $row): ?string
    {
        $path = $row['path'] ?? null;
        if (null !== $path && !\is_string($path)) {
            throw new CatalogSyncFailure('invalid_catalog_cache');
        }

        return $path;
    }
}
