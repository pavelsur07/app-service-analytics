<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetBatch;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetLimits;

/** Actual inputs and the original mapping budget; never refresh these before aggregate. */
final readonly class NetForecastReadFrame
{
    public function __construct(public NetTrainingBatch $training, public ?NetTargetBatch $targets, public int $computeStartedNs, public int $allocationBaselineBytes, public NetTargetLimits $limits, public ?string $unavailableReason = null)
    {
    }
}
