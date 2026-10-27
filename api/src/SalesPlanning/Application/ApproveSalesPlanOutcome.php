<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

enum ApproveSalesPlanOutcome: string
{
    case ClosedLineage = 'sales_plan_lineage_closed';
    case Approved = 'approved';
    case NotFound = 'sales_plan_not_found';
    case NotDraft = 'sales_plan_not_draft';
    case VersionConflict = 'version_conflict';
    case MissingMetric = 'sales_plan_metric_required';
    case EmptyPlan = 'sales_plan_empty';
    case DayConflict = 'sales_plan_day_conflict';
}
