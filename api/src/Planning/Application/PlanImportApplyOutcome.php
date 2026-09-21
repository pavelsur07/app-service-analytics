<?php

declare(strict_types=1);

namespace App\Planning\Application;

enum PlanImportApplyOutcome
{
    case Applied;
    case Conflict;
    case NotFound;
    case AccountNotFound;
}
