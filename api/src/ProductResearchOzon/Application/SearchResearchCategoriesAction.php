<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Application;

use App\ProductResearchOzon\Domain\CatalogSyncFailure;
use App\ProductResearchOzon\Domain\ResearchProblem;
use App\ProductResearchOzon\Domain\ValueObject\CatalogSearchResult;
use App\ProductResearchOzon\Infrastructure\Config\CatalogContext;
use App\ProductResearchOzon\Infrastructure\Query\CatalogQuery;
use Psr\Clock\ClockInterface;

final readonly class SearchResearchCategoriesAction
{
    public function __construct(private CatalogQuery $query, private CatalogContext $context, private ClockInterface $clock)
    {
    }

    public function __invoke(string $companyId, string $text, int $limit): CatalogSearchResult
    {
        try {
            $rows = $this->query->search($companyId, $this->context->sourceId(), $text, $limit)->executeQuery()->fetchAllAssociative();
            $metadata = CatalogQuery::metadata($rows[0] ?? false);
            if (null === $metadata || !$metadata->isFresh($this->clock->now())) {
                throw new ResearchProblem('catalog_not_ready');
            }
            $paths = [];
            foreach ($rows as $row) {
                $path = CatalogQuery::path($row);
                if (null !== $path) {
                    $paths[] = $path;
                }
            }

            return new CatalogSearchResult($metadata, $paths);
        } catch (CatalogSyncFailure) {
            throw new ResearchProblem('catalog_not_ready');
        }
    }
}
