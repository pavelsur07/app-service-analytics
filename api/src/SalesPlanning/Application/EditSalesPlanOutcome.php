<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

enum EditSalesPlanOutcome
{
    case Saved;
    case NotFound;
    case VersionConflict;
    case NotEditable;
    case SkuNotInPlan;
    case DayOutOfPeriod;
    case TotalTooLarge;
}
