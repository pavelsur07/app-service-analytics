<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

use App\Ingestion\Domain\OzonCampaignCatalogItem;
use OpenApi\Attributes as OA;

final readonly class OzonCampaignCatalogResponse
{
    /** @param list<OzonCampaignCatalogItem> $items */
    public function __construct(
        public array $items,
        #[OA\Property(enum: ['not_loaded', 'partial', 'ready'])]
        public string $status,
        public ?string $receivedAt,
        public int $savedCount,
        public ?int $total,
        public ?string $nextCursor,
    ) {
    }
}
