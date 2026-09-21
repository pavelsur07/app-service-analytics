<?php

declare(strict_types=1);

namespace App\Planning\Domain;

enum DailyPlanMutationOutcome
{
    case Saved;
    case AccountNotFound;
    case UnknownSku;
    case VersionConflict;
}
