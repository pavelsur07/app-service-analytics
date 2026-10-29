<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetRequest;

final readonly class NetForecastSeriesRequest
{
    public \DateTimeImmutable $from;
    public \DateTimeImmutable $to;
    public ?\DateTimeImmutable $accumulationFrom;

    /** @param list<string> $marketplaceSkus */
    public function __construct(public array $marketplaceSkus, \DateTimeImmutable $from, \DateTimeImmutable $to, ?\DateTimeImmutable $accumulationFrom = null, public string $groupBy = 'day')
    {
        $target = new NetTargetRequest($marketplaceSkus, $from, $to, $accumulationFrom);
        if (!\in_array($groupBy, ['day', 'week', 'month', 'period'], true)) {
            throw new \InvalidArgumentException('Unsupported net forecast grouping.');
        }
        $this->from = $target->from;
        $this->to = $target->to;
        $this->accumulationFrom = $target->accumulationFrom;
    }

    public function assertMatches(NetTargetRequest $target): void
    {
        $actual = $target->marketplaceSkus;
        $expected = $this->marketplaceSkus;
        sort($actual, \SORT_STRING);
        sort($expected, \SORT_STRING);
        if ($actual !== $expected || $target->from->format('Y-m-d') !== $this->from->format('Y-m-d') || $target->to->format('Y-m-d') !== $this->to->format('Y-m-d') || $target->accumulationFrom?->format('Y-m-d') !== $this->accumulationFrom?->format('Y-m-d')) {
            throw new \InvalidArgumentException('Series must describe the exact target request.');
        }
    }
}
