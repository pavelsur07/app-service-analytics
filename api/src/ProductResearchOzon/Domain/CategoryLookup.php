<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain;

interface CategoryLookup
{
    /** @throws ResearchProblem catalog_not_ready */
    public function resolve(string $companyId, string $path): ?CategoryReference;
}
