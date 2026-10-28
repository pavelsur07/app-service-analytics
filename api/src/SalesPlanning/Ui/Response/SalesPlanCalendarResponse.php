<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

use App\SalesPlanning\Infrastructure\Query\SalesPlanCalendarPage;
use OpenApi\Attributes as OA;

final readonly class SalesPlanCalendarResponse
{
    /** @param list<SalesPlanCalendarEntryResponse> $entries */
    public function __construct(
        #[OA\Property(format: 'uuid')] public string $planId,
        public int $version,
        #[OA\Property(enum: ['draft', 'approved', 'superseded', 'cancelled', 'discarded'])] public string $status,
        public SalesPlanSeasonBoundaryResponse $seasonStart,
        public SalesPlanSeasonBoundaryResponse $seasonEnd,
        #[OA\Property(maxItems: 200)] public array $entries,
        public ?string $nextCursor,
    ) {
    }

    public static function of(SalesPlanCalendarPage $page): self
    {
        return new self($page->planId, $page->version, $page->status,
            new SalesPlanSeasonBoundaryResponse('season_start', $page->dateFrom),
            new SalesPlanSeasonBoundaryResponse('season_end', $page->dateTo),
            array_map(SalesPlanCalendarEntryResponse::of(...), $page->entries), $page->nextCursor);
    }
}
