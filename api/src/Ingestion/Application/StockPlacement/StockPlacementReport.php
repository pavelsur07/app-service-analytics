<?php

declare(strict_types=1);

namespace App\Ingestion\Application\StockPlacement;

use App\Ingestion\Infrastructure\Query\StockPlacement\StockPlacementCursor;
use App\Ingestion\Infrastructure\Query\StockPlacement\StockPlacementRow;

final readonly class StockPlacementReport
{
    /**
     * @param list<StockPlacementRow> $items
     */
    public function __construct(
        public ?string $snapshotDate,
        public int $completeSnapshotDays,
        public bool $correctionApplied,
        public int $deficitPositions,
        public int $deficitUnits,
        public int $surplusPositions,
        public int $recommendedPositions,
        public int $recommendedUnits,
        public int $unknownPositions,
        public array $items,
        public ?StockPlacementCursor $nextCursor,
    ) {
    }
}
