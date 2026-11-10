<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

final readonly class OzonCampaignCatalogData
{
    public const int MAX_ITEMS = 10000;

    /** @param list<OzonCampaignCatalogItem> $items */
    public function __construct(public array $items, public ?int $total, public bool $complete)
    {
    }
}
