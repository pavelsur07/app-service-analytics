<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\StockPlacement;

use OpenApi\Attributes as OA;

#[OA\Schema(required: [
    'today', 'definitions', 'snapshotDate', 'completeSnapshotDays', 'correctionApplied', 'deficitPositions',
    'deficitUnits', 'surplusPositions', 'recommendedPositions', 'recommendedUnits', 'unknownPositions', 'items', 'nextCursor',
])]
final readonly class StockPlacementReportResponse
{
    /**
     * @param list<StockPlacementItemResponse> $items
     */
    public function __construct(
        public string $today,
        public StockPlacementDefinitionsResponse $definitions,
        /** Самый старый из использованных свежих снимков (не старше вчера); null — свежих нет. */
        public ?string $snapshotDate,
        public int $completeSnapshotDays,
        /** Поправка на дефицит применена: полных снимков — все дни окна спроса. */
        public bool $correctionApplied,
        public int $deficitPositions,
        public int $deficitUnits,
        public int $surplusPositions,
        public int $recommendedPositions,
        public int $recommendedUnits,
        public int $unknownPositions,
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
