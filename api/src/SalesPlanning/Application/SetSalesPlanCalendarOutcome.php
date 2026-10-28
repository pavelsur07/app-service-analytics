<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

enum SetSalesPlanCalendarOutcome: string
{
    case Saved = 'saved';
    case NotFound = 'sales_plan_not_found';
    case NotEditable = 'sales_plan_not_editable';
    case VersionConflict = 'version_conflict';
    case InvalidEntries = 'sales_plan_invalid_calendar';
    case UnknownSku = 'sales_plan_calendar_unknown_sku';
}
