<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain;

final readonly class MonthSlice
{
    public function __construct(public string $month, public string $dateFrom, public string $dateTo, public int $days, public bool $partial)
    {
    }
}
