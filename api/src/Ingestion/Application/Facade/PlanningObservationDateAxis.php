<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

enum PlanningObservationDateAxis: string
{
    case FIRST_KNOWN_OUTCOME = 'first_known_outcome_at';
    case FIRST_REGULAR_OBSERVATION = 'first_regularly_observed_at';
}
