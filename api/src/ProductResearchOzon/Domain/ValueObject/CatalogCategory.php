<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain\ValueObject;

use App\ProductResearchOzon\Domain\CatalogSyncFailure;

final readonly class CatalogCategory
{
    public string $key;

    public function __construct(public string $path)
    {
        if ('' === trim($path) || mb_strlen($path) > 2048 || str_contains($path, "\0")) {
            throw new CatalogSyncFailure('invalid_catalog_response');
        }
        $this->key = hash('sha256', $path);
    }
}
