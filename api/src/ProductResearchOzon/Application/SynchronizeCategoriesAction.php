<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Application;

use App\ProductResearchOzon\Domain\CatalogProjectionRepository;
use App\ProductResearchOzon\Domain\CatalogSyncFailure;
use App\ProductResearchOzon\Domain\CategorySource;
use App\ProductResearchOzon\Domain\ValueObject\CatalogMetadata;
use App\ProductResearchOzon\Domain\ValueObject\CatalogSyncResult;
use App\ProductResearchOzon\Infrastructure\Config\CatalogContext;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Invoked only by the explicitly company-scoped background command. */
final readonly class SynchronizeCategoriesAction
{
    public function __construct(private CatalogProjectionRepository $cache, private CategorySource $source, private CatalogContext $context, private ClockInterface $clock)
    {
    }

    public function __invoke(string $companyId): CatalogSyncResult
    {
        try {
            $connection = $this->context->sourceId();
        } catch (CatalogSyncFailure $failure) {
            return new CatalogSyncResult($failure->getMessage());
        }
        $now = $this->clock->now();
        $existing = $this->cache->metadata($companyId, $connection);
        if (null !== $existing && $existing->isFresh($now)) {
            return new CatalogSyncResult('fresh', $existing->revision);
        }
        $owner = Uuid::v7()->toRfc4122();
        if (!$this->cache->claim($companyId, $connection, $owner, $now)) {
            return new CatalogSyncResult('busy');
        }
        try {
            $deadline = hrtime(true) + 90000000000;
            $cursor = null;
            $seen = [];
            $paths = [];
            $pathBytes = 0;
            $metadata = null;
            for ($pageNumber = 0; $pageNumber < 200; ++$pageNumber) {
                if (hrtime(true) >= $deadline) {
                    throw new CatalogSyncFailure('catalog_sync_timeout');
                }
                $page = $this->source->page($companyId, $cursor);
                $current = new CatalogMetadata($page->revision, $page->generatedAt);
                $metadata ??= $current;
                if (!$metadata->sameGeneration($current) || (!$current->isFresh($this->clock->now())) || ([] === $page->paths && (null !== $cursor || null !== $page->nextCursor))) {
                    throw new CatalogSyncFailure('invalid_catalog_response');
                }
                foreach ($page->paths as $path) {
                    $key = hash('sha256', $path);
                    if (isset($paths[$key])) {
                        throw new CatalogSyncFailure('invalid_catalog_response');
                    }
                    $pathBytes += \strlen($path);
                    if ($pathBytes > 16777216) {
                        throw new CatalogSyncFailure('catalog_sync_limit');
                    }
                    $paths[$key] = $path;
                }
                if (\count($paths) > 20000 || hrtime(true) >= $deadline) {
                    throw new CatalogSyncFailure('catalog_sync_limit');
                }
                if (null === $page->nextCursor) {
                    $this->cache->publish($companyId, $connection, $owner, $metadata, array_values($paths), $this->clock->now());

                    return new CatalogSyncResult('updated', $metadata->revision, \count($paths));
                }
                if (isset($seen[$page->nextCursor])) {
                    throw new CatalogSyncFailure('catalog_cursor_cycle');
                }
                $seen[$page->nextCursor] = true;
                $cursor = $page->nextCursor;
            }
            throw new CatalogSyncFailure('catalog_sync_limit');
        } catch (CatalogSyncFailure $failure) {
            $this->cache->fail($companyId, $connection, $owner, $failure, $this->clock->now());

            return new CatalogSyncResult($failure->getMessage());
        }
    }
}
