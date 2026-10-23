<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DemandMatrix;

use App\Ingestion\Infrastructure\Query\DemandMatrix\DemandMatrixCellRow;
use App\Ingestion\Infrastructure\Query\DemandMatrix\DemandMatrixSkuRow;

final readonly class DemandMatrixReportItem
{
    /**
     * @param list<DemandMatrixCellRow> $cells
     */
    public function __construct(
        public DemandMatrixSkuRow $sku,
        public array $cells,
    ) {
    }
}
