<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

enum CloseSalesPlanOutcome: string
{
    case Changed = 'changed';
    case NotFound = 'sales_plan_not_found';
    case VersionConflict = 'version_conflict';
    case InvalidStatus = 'sales_plan_invalid_status';
}
