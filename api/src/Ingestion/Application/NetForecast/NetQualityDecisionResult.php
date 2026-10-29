<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

final readonly class NetQualityDecisionResult
{
    public bool $numericRolloutAllowed;

    /** @param list<string> $failedMetrics
     * @param list<string> $insufficientReasons
     * @param list<string> $violations */
    public function __construct(public string $decision, public array $failedMetrics, public array $insufficientReasons, public array $violations)
    {
        $this->numericRolloutAllowed = false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['decision' => $this->decision, 'failedMetrics' => $this->failedMetrics, 'insufficientReasons' => $this->insufficientReasons, 'violations' => $this->violations, 'numericRolloutAllowed' => false];
    }
}
