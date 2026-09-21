<?php

declare(strict_types=1);

namespace App\Planning\Application;

enum PlanImportPreviewOutcome
{
    case Ready;
    case Invalid;
    case AccountNotFound;
}
