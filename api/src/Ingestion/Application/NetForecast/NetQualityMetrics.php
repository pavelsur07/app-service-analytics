<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

final readonly class NetQualityMetrics
{
    public function accumulator(): NetQualityMetricsAccumulator
    {
        return new NetQualityMetricsAccumulator();
    }

    /** @param iterable<NetQualityPairedObservation> $pairedObservations */
    public function calculate(iterable $pairedObservations, ?NetQualityBudget $budget = null): NetQualityMetricsResult
    {
        $accumulator = $this->accumulator();
        foreach ($pairedObservations as $row) {
            $budget?->guard();
            $accumulator->add($row);
        }

        return $accumulator->result();
    }
}
