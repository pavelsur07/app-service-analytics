<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\ProductResearchOzon\Domain\CatalogSyncFailure;
use App\ProductResearchOzon\Domain\CategorySource;
use App\ProductResearchOzon\Domain\ValueObject\CatalogPage;

final class ResearchCategorySource implements CategorySource
{
    /** @var list<CatalogPage|CatalogSyncFailure> */
    public array $pages = [];
    /** @var list<array{company:string,cursor:?string}> */
    public array $requests = [];

    public function page(string $companyId, ?string $cursor): CatalogPage
    {
        $this->requests[] = ['company' => $companyId, 'cursor' => $cursor];
        $next = array_shift($this->pages);
        if ($next instanceof CatalogSyncFailure) {
            throw $next;
        }

        return $next ?? throw new CatalogSyncFailure('unexpected_source_call');
    }
}
