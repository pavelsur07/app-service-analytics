<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DemandMatrix;

use App\Ingestion\Infrastructure\Query\DemandMatrix\DemandMatrixClusterRow;
use App\Ingestion\Infrastructure\Query\DemandMatrix\DemandMatrixCursor;

final readonly class DemandMatrixReport
{
    /**
     * @param list<DemandMatrixClusterRow> $clusters
     * @param list<DemandMatrixReportItem> $items
     */
    public function __construct(
        public array $clusters,
        public array $items,
        public int $incompleteSalesAccounts,
        public ?DemandMatrixCursor $nextCursor,
    ) {
    }
}
