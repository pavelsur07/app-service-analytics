<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\ProductResearchOzon\Domain\CategoryLookup;
use App\ProductResearchOzon\Domain\CategoryReference;
use App\ProductResearchOzon\Domain\ResearchProblem;

final class ResearchCategories implements CategoryLookup
{
    /** @var array<string, list<string>> */
    private array $categories = [];

    /** @param list<string> $paths */
    public function publish(string $companyId, array $paths): void
    {
        $this->categories[$companyId] = $paths;
    }

    public function forget(string $companyId): void
    {
        unset($this->categories[$companyId]);
    }

    public function resolve(string $companyId, string $path): ?CategoryReference
    {
        if (!isset($this->categories[$companyId])) {
            throw new ResearchProblem('catalog_not_ready');
        }

        return \in_array($path, $this->categories[$companyId], true) ? new CategoryReference($path, 1) : null;
    }
}
