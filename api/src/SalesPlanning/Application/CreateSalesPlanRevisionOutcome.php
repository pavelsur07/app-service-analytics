<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

enum CreateSalesPlanRevisionOutcome: string
{
    case Created = 'created';
    case NotFound = 'sales_plan_not_found';
    case NotApproved = 'sales_plan_not_approved';
    case VersionConflict = 'version_conflict';
}
