<?php

declare(strict_types=1);

namespace App\Planning\Ui\Response;

use App\Planning\Application\DailyPlanItem;
use OpenApi\Attributes as OA;

#[OA\Schema(required: ['date', 'quantity', 'version'])]
final readonly class DailyPlanItemResponse
{
    public function __construct(
        public string $date,
        public ?int $quantity,
        public int $version,
    ) {
    }

    public static function fromItem(DailyPlanItem $item): self
    {
        return new self($item->date, $item->quantity, $item->version);
    }
}
