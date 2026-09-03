<?php

declare(strict_types=1);

namespace App\Links\Ui\Response;

use App\Links\Application\MonthlyClicks;

final readonly class MonthlyClicksResponse
{
    /**
     * @param list<DailyClicksResponse> $items
     */
    public function __construct(
        public string $linkId,
        public string $month,
        public array $items,
    ) {
    }

    public static function fromResult(MonthlyClicks $result): self
    {
        return new self(
            linkId: $result->linkId,
            month: $result->month,
            items: array_map(
                static fn (array $item): DailyClicksResponse => new DailyClicksResponse(
                    $item['date'],
                    $item['clicks'],
                ),
                $result->items,
            ),
        );
    }
}
