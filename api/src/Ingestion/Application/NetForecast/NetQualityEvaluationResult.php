<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

final readonly class NetQualityEvaluationResult
{
    /** @param array<string, int> $counts */
    public function __construct(public NetQualityArtifactRef $datasetRef, public NetQualityArtifactRef $reportRef, public string $decision, public array $counts)
    {
    }
}
