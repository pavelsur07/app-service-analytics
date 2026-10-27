<?php

declare(strict_types=1);

namespace App\SalesPlanning\Infrastructure\Query;

final readonly class SalesPlanCalendarPage
{
    /** @param list<SalesPlanCalendarEntryRow> $entries */
    public function __construct(public string $planId, public int $version, public string $status, public string $dateFrom, public string $dateTo, public array $entries, public ?string $nextCursor)
    {
    }
}
