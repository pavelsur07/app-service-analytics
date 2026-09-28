<?php

declare(strict_types=1);

namespace App\Ingestion\Application\UnitEconomics;

final readonly class AccrualReconciliationGroup
{
    /**
     * @param list<AccrualReconciliationItem> $items
     */
    public function __construct(
        /** Код группы из `OzonAccrualGroups`. */
        public string $code,
        public string $label,
        public int $totalMinor,
        public array $items,
    ) {
    }
}
