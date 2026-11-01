<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Infrastructure\Config;

use App\ProductResearchOzon\Domain\CatalogSyncFailure;

final readonly class CatalogContext
{
    public function __construct(private string $researchCatalogSourceId)
    {
    }

    public function sourceId(): string
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $this->researchCatalogSourceId)) {
            throw new CatalogSyncFailure('catalog_source_not_configured');
        }

        return $this->researchCatalogSourceId;
    }
}
