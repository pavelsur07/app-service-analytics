<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

/** A sentinel establishes a lower bound, not the total size of the source table. */
final readonly class NetTrainingBudgetFailure
{
    public function __construct(public string $input, public int $observedLowerBound, public int $limit)
    {
    }
}
