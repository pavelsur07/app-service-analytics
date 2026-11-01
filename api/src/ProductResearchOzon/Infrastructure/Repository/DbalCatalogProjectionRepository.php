<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Infrastructure\Repository;

use App\ProductResearchOzon\Domain\CatalogProjectionRepository;
use App\ProductResearchOzon\Domain\CatalogSyncFailure;
use App\ProductResearchOzon\Domain\ValueObject\CatalogCategory;
use App\ProductResearchOzon\Domain\ValueObject\CatalogMetadata;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class DbalCatalogProjectionRepository implements CatalogProjectionRepository
{
    public function __construct(private Connection $db)
    {
    }

    public function metadata(string $companyId, string $sourceId): ?CatalogMetadata
    {
        $row = $this->db->fetchAssociative('SELECT revision, generated_at FROM product_research_ozon_catalog WHERE company_id=:company AND source_connection_id=:source AND revision>0 LIMIT 1', ['company' => $companyId, 'source' => $sourceId]);
        if (false === $row) {
            return null;
        }

        return self::map($row);
    }

    public function claim(string $companyId, string $sourceId, string $owner, \DateTimeImmutable $now): bool
    {
        return false !== $this->db->fetchOne("INSERT INTO product_research_ozon_catalog (company_id,source_connection_id,revision,lease_id,lease_until) VALUES (:company,:source,0,:owner,clock_timestamp()+interval '120 seconds') ON CONFLICT (company_id,source_connection_id) DO UPDATE SET lease_id=EXCLUDED.lease_id,lease_until=EXCLUDED.lease_until,error_code=NULL WHERE (product_research_ozon_catalog.lease_until IS NULL OR product_research_ozon_catalog.lease_until<=clock_timestamp()) AND (product_research_ozon_catalog.next_attempt_at IS NULL OR product_research_ozon_catalog.next_attempt_at<=:now) AND (product_research_ozon_catalog.generated_at IS NULL OR product_research_ozon_catalog.generated_at<=:stale OR product_research_ozon_catalog.generated_at>:now) RETURNING lease_id", ['company' => $companyId, 'source' => $sourceId, 'owner' => $owner, 'now' => $now->format('Y-m-d H:i:s.uP'), 'stale' => $now->modify('-24 hours')->format('Y-m-d H:i:s.uP')]);
    }

    public function publish(string $companyId, string $sourceId, string $owner, CatalogMetadata $metadata, array $paths, \DateTimeImmutable $now): void
    {
        if (!$metadata->isFresh($now) || \count($paths) > 20000 || \count(array_unique($paths)) !== \count($paths)) {
            throw new CatalogSyncFailure('invalid_catalog_response');
        }
        $entries = [];
        $bytes = 0;
        foreach ($paths as $path) {
            $category = new CatalogCategory($path);
            $bytes += \strlen($path);
            if ($bytes > 16777216) {
                throw new CatalogSyncFailure('catalog_sync_limit');
            }
            $entries[] = ['id' => Uuid::v7()->toRfc4122(), 'key' => $category->key, 'path' => $path];
        }
        $this->db->transactional(function () use ($companyId, $sourceId, $owner, $metadata, $entries): void {
            $params = ['company' => $companyId, 'source' => $sourceId, 'owner' => $owner];
            $row = $this->db->fetchAssociative('SELECT revision, generated_at FROM product_research_ozon_catalog WHERE company_id=:company AND source_connection_id=:source AND lease_id=:owner AND lease_until>clock_timestamp() FOR UPDATE', $params);
            if (false === $row) {
                throw new CatalogSyncFailure('catalog_lease_lost');
            }
            $previous = null === $row['generated_at'] ? null : self::map($row);
            if (null !== $previous && ($previous->revision > $metadata->revision || $previous->generatedAt > $metadata->generatedAt || ($previous->revision === $metadata->revision && !$previous->sameGeneration($metadata)))) {
                throw new CatalogSyncFailure('catalog_revision_conflict');
            }
            $this->db->executeStatement('DELETE FROM product_research_ozon_category WHERE company_id=:company AND source_connection_id=:source', ['company' => $companyId, 'source' => $sourceId]);
            $this->db->executeStatement('INSERT INTO product_research_ozon_category (id,company_id,source_connection_id,path_key,path) SELECT e.id,:company,:source,e.key,e.path FROM jsonb_to_recordset(CAST(:entries AS jsonb)) AS e(id uuid,key char(64),path varchar(2048))', ['company' => $companyId, 'source' => $sourceId, 'entries' => json_encode($entries, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)]);
            $updated = $this->db->executeStatement('UPDATE product_research_ozon_catalog SET revision=:revision,generated_at=:generated,lease_id=NULL,lease_until=NULL,next_attempt_at=NULL,error_code=NULL WHERE company_id=:company AND source_connection_id=:source AND lease_id=:owner AND lease_until>clock_timestamp()', [...$params, 'revision' => $metadata->revision, 'generated' => $metadata->generatedAt->format('Y-m-d H:i:s.uP')]);
            if (1 !== $updated) {
                throw new CatalogSyncFailure('catalog_lease_lost');
            }
        });
    }

    public function fail(string $companyId, string $sourceId, string $owner, CatalogSyncFailure $failure, \DateTimeImmutable $now): void
    {
        $this->db->executeStatement('UPDATE product_research_ozon_catalog SET lease_id=NULL,lease_until=NULL,next_attempt_at=:next,error_code=:error WHERE company_id=:company AND source_connection_id=:source AND lease_id=:owner', ['company' => $companyId, 'source' => $sourceId, 'owner' => $owner, 'next' => ($failure->retryAt ?? $now->modify('+60 seconds'))->format('Y-m-d H:i:s.uP'), 'error' => $failure->getMessage()]);
    }

    /** @param array<string,mixed> $row */
    public static function map(array $row): CatalogMetadata
    {
        $revision = $row['revision'] ?? null;
        $generated = $row['generated_at'] ?? null;
        if ((!\is_int($revision) && (!\is_string($revision) || !ctype_digit($revision))) || !\is_string($generated)) {
            throw new CatalogSyncFailure('invalid_catalog_cache');
        }

        return new CatalogMetadata((int) $revision, new \DateTimeImmutable($generated));
    }
}
