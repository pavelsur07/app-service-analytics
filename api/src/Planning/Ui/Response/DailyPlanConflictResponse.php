<?php

declare(strict_types=1);

namespace App\Planning\Ui\Response;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['status', 'code', 'message', 'current'])]
final readonly class DailyPlanConflictResponse
{
    public function __construct(
        public int $status,
        public string $code,
        public string $message,
        public DailyPlanItemResponse $current,
    ) {
    }
}
