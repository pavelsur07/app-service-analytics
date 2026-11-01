<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain\ValueObject;

final readonly class CatalogSearchResult
{
    /** @param list<string> $paths */
    public function __construct(public CatalogMetadata $metadata, public array $paths)
    {
    }
}
