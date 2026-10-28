<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

enum SetSalesPlanMetricOutcome: string
{
    case Saved = 'saved';
    case NotFound = 'sales_plan_not_found';
    case NotEditable = 'sales_plan_not_editable';
    case VersionConflict = 'version_conflict';
    case MetricImmutable = 'sales_plan_metric_immutable';
}
