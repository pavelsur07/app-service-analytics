<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Response;

use App\ProductResearchOzon\Domain\MonthSlice;

final readonly class MonthResponse
{
    public string $month;
    public string $dateFrom;
    public string $dateTo;
    public int $days;
    public bool $partial;

    public function __construct(MonthSlice $month)
    {
        $this->month = $month->month;
        $this->dateFrom = $month->dateFrom;
        $this->dateTo = $month->dateTo;
        $this->days = $month->days;
        $this->partial = $month->partial;
    }
}
