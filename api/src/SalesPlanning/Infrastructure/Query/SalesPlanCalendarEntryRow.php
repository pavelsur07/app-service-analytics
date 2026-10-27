<?php

declare(strict_types=1);

namespace App\SalesPlanning\Infrastructure\Query;

final readonly class SalesPlanCalendarEntryRow
{
    public function __construct(public string $id, public ?string $marketplaceSku, public string $kind, public string $dateFrom, public string $dateTo)
    {
    }
}
