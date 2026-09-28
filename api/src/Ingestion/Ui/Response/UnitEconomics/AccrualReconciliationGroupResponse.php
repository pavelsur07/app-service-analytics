<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\UnitEconomics;

use OpenApi\Attributes as OA;

final readonly class AccrualReconciliationGroupResponse
{
    /**
     * @param list<AccrualReconciliationItemResponse> $items
     */
    public function __construct(
        #[OA\Property(enum: ['sales', 'returns', 'commission', 'delivery', 'partners', 'fbo', 'promotion', 'other_services', 'compensations', 'ungrouped'])]
        public string $code,
        /** Название группы, как в кабинете. */
        public string $label,
        public int $totalMinor,
        /** Статьи группы, крупная первой. */
        public array $items,
    ) {
    }
}
