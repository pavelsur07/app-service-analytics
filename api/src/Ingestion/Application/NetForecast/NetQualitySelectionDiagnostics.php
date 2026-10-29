<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

final class NetQualitySelectionDiagnostics
{
    /** @var array<string, int> */
    private array $counts = [];
    /** @var array<string, string> */
    private array $orderDates = [];

    public function observeOrder(string $cluster, string $businessDate): void
    {
        if (isset($this->orderDates[$cluster]) && $this->orderDates[$cluster] !== $businessDate) {
            throw new \DomainException('One order cannot move between calibration and holdout dates.');
        }
        if (!isset($this->orderDates[$cluster]) && \count($this->orderDates) >= 20000) {
            throw new \OverflowException('Quality order-cluster population limit exceeded.');
        }
        $this->orderDates[$cluster] = $businessDate;
    }

    public function add(string $reason): void
    {
        $this->counts[$reason] = ($this->counts[$reason] ?? 0) + 1;
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        $counts = $this->counts;
        ksort($counts, \SORT_STRING);

        return $counts;
    }
}
