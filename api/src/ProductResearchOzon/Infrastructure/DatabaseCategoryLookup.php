<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Infrastructure;

use App\ProductResearchOzon\Domain\CatalogSyncFailure;
use App\ProductResearchOzon\Domain\CategoryLookup;
use App\ProductResearchOzon\Domain\CategoryReference;
use App\ProductResearchOzon\Domain\ResearchProblem;
use App\ProductResearchOzon\Infrastructure\Config\CatalogContext;
use App\ProductResearchOzon\Infrastructure\Query\CatalogQuery;
use Psr\Clock\ClockInterface;

final readonly class DatabaseCategoryLookup implements CategoryLookup
{
    public function __construct(private CatalogQuery $query, private CatalogContext $context, private ClockInterface $clock)
    {
    }

    public function resolve(string $companyId, string $path): ?CategoryReference
    {
        try {
            $row = $this->query->category($companyId, $this->context->sourceId(), $path)->executeQuery()->fetchAssociative();
            $metadata = CatalogQuery::metadata($row);
            if (null === $metadata || !$metadata->isFresh($this->clock->now())) {
                throw new ResearchProblem('catalog_not_ready');
            }
            $found = false === $row ? null : CatalogQuery::path($row);

            return null === $found ? null : new CategoryReference($found, $metadata->revision);
        } catch (CatalogSyncFailure) {
            throw new ResearchProblem('catalog_not_ready');
        }
    }
}
