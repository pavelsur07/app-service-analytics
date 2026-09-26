<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\Localization;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['cluster', 'quantity', 'shareBps'])]
final readonly class LocalizationSourceClusterResponse
{
    public function __construct(
        public string $cluster,
        public int $quantity,
        public int $shareBps,
    ) {
    }
}
