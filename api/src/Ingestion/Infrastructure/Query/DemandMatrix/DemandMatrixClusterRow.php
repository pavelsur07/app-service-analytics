<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\DemandMatrix;

final readonly class DemandMatrixClusterRow
{
    public function __construct(
        /** null — Ozon не прислал кластер покупателя. */
        public ?string $cluster,
        public int $orders28,
    ) {
    }
}
