<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\StockPlacement;

use OpenApi\Attributes as OA;

/** Правила расчёта — частью ответа (объяснимость расчёта, CLAUDE.md). */
#[OA\Schema(required: [
    'targetDays', 'leadDays', 'demandWindowDays', 'minSales', 'surplusFactor', 'abcABps', 'abcBBps',
    'demandBasis', 'stockBasis',
])]
final readonly class StockPlacementDefinitionsResponse
{
    public function __construct(
        public int $targetDays,
        public int $leadDays,
        public int $demandWindowDays,
        public int $minSales,
        public int $surplusFactor,
        public int $abcABps,
        public int $abcBBps,
        #[OA\Property(enum: ['delivery_cluster_sales_excluding_cancelled'])]
        public string $demandBasis,
        #[OA\Property(enum: ['latest_complete_snapshot_since_yesterday_including_pickup_points'])]
        public string $stockBasis,
    ) {
    }
}
