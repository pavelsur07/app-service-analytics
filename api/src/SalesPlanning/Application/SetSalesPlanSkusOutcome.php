<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

enum SetSalesPlanSkusOutcome
{
    case Saved;
    case NotFound;
    case VersionConflict;
    case NotEditable;
    case UnknownSkus;
    case TooManySkus;
    case TooManyLines;
}
