<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\DemandMatrix;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['cluster', 'orders28'])]
final readonly class DemandMatrixClusterResponse
{
    public function __construct(
        /** null — Ozon не прислал кластер покупателя. */
        public ?string $cluster,
        public int $orders28,
    ) {
    }
}
