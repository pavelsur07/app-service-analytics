<?php

declare(strict_types=1);

namespace App\SalesPlanning\Domain;

enum SalesPlanCalendarKind: string
{
    case SeasonStart = 'season_start';
    case SeasonEnd = 'season_end';
    case Replenishment = 'replenishment';
    case Sales = 'sales';
    case ReplenishmentStop = 'replenishment_stop';
}
