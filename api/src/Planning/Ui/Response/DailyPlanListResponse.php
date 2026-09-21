<?php

declare(strict_types=1);

namespace App\Planning\Ui\Response;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['items'])]
final readonly class DailyPlanListResponse
{
    /** @param list<DailyPlanItemResponse> $items */
    public function __construct(public array $items)
    {
    }
}
