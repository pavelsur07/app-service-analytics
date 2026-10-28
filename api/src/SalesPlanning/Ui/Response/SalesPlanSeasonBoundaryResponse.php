<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

use OpenApi\Attributes as OA;

final readonly class SalesPlanSeasonBoundaryResponse
{
    public function __construct(
        #[OA\Property(enum: ['season_start', 'season_end'])] public string $kind,
        #[OA\Property(format: 'date')] public string $date,
    ) {
    }
}
