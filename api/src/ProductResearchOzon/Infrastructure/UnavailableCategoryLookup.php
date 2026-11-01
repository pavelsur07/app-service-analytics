<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Infrastructure;

use App\ProductResearchOzon\Domain\CategoryLookup;
use App\ProductResearchOzon\Domain\CategoryReference;
use App\ProductResearchOzon\Domain\ResearchProblem;

/** Stage 3 replaces this adapter with a complete local catalog projection. */
final readonly class UnavailableCategoryLookup implements CategoryLookup
{
    public function resolve(string $companyId, string $path): ?CategoryReference
    {
        throw new ResearchProblem('catalog_not_ready');
    }
}
