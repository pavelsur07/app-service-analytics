<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

final readonly class NetForecastComponent
{
    /** @param list<array{scope: string, reason: ?string, sampleQuantity: ?int, samplePostings: ?int}> $attempts */
    public function __construct(
        public ?string $basis,
        public ?int $ageDays,
        public ?string $probabilityNumerator,
        public ?string $probabilityDenominator,
        public ?string $cumulativeReturnNumerator,
        public ?string $cumulativeReturnDenominator,
        public ?string $unavailableReason,
        public array $attempts,
    ) {
        if (!array_is_list($attempts) || \count($attempts) > 2) {
            throw new \InvalidArgumentException('Compact attempts must be a list of at most two scopes.');
        }
    }
}
