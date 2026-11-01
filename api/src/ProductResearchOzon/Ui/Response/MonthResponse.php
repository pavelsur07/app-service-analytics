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

    public function __construct(MonthSlice $slice)
    {
        $this->month = $slice->month;
        $this->dateFrom = $slice->dateFrom;
        $this->dateTo = $slice->dateTo;
        $this->days = $slice->days;
        $this->partial = $slice->partial;
    }
}
