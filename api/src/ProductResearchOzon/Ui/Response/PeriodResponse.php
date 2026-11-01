<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Response;

use App\ProductResearchOzon\Domain\PeriodRange;

final readonly class PeriodResponse
{
    public string $fromMonth;
    public string $toMonth;
    public string $name;

    public function __construct(PeriodRange $range)
    {
        $this->fromMonth = $range->fromMonth;
        $this->toMonth = $range->toMonth;
        $this->name = $range->name;
    }
}
