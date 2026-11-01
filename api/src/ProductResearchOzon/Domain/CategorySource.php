<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain;

use App\ProductResearchOzon\Domain\ValueObject\CatalogPage;

interface CategorySource
{
    public function page(string $companyId, ?string $cursor): CatalogPage;
}
