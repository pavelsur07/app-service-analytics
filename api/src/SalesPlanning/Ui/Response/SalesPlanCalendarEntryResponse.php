<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

use App\SalesPlanning\Infrastructure\Query\SalesPlanCalendarEntryRow;
use OpenApi\Attributes as OA;

final readonly class SalesPlanCalendarEntryResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')] public string $id,
        public ?string $marketplaceSku,
        #[OA\Property(enum: ['replenishment', 'sales', 'replenishment_stop'])] public string $kind,
        #[OA\Property(format: 'date')] public string $dateFrom,
        #[OA\Property(format: 'date')] public string $dateTo,
    ) {
    }

    public static function of(SalesPlanCalendarEntryRow $row): self
    {
        return new self($row->id, $row->marketplaceSku, $row->kind, $row->dateFrom, $row->dateTo);
    }
}
