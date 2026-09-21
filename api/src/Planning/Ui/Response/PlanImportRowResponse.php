<?php

declare(strict_types=1);

namespace App\Planning\Ui\Response;

use App\Planning\Domain\PlanImportPreviewRow;
use OpenApi\Attributes as OA;

final readonly class PlanImportRowResponse
{
    public function __construct(
        public int $rowNumber,
        public string $marketplaceSku,
        #[OA\Property(nullable: true)] public ?string $sellerArticle,
        #[OA\Property(format: 'date')] public string $businessDate,
        public int $quantity,
        public int $expectedVersion,
        #[OA\Property(nullable: true)] public ?int $currentQuantity,
        #[OA\Property(enum: ['new', 'changed', 'unchanged'])] public string $change,
    ) {
    }

    public static function fromRow(PlanImportPreviewRow $row): self
    {
        return new self(...array_values($row->toArray()));
    }
}
