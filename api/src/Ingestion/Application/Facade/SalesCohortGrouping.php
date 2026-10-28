<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

enum SalesCohortGrouping: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Period = 'period';
}
