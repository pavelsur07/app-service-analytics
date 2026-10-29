<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

final readonly class NetQualityMetricsResult
{
    /** @param array<string, mixed> $method
     * @param array<string, mixed> $simple
     * @param array<string, array<string, mixed>> $money */
    public function __construct(public int $count, public array $method, public array $simple, public array $money)
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['count' => $this->count, 'method' => $this->method, 'simple' => $this->simple, 'money' => $this->money];
    }
}
