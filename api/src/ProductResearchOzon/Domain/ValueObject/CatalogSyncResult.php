<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain\ValueObject;

final readonly class CatalogSyncResult
{
    public function __construct(public string $outcome, public ?int $revision = null, public int $categories = 0)
    {
    }
}
