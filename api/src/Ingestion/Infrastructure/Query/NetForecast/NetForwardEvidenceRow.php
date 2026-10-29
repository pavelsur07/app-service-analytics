<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

/** @phpstan-type EvidenceValues array<string, mixed> */
final readonly class NetForwardEvidenceRow
{
    /** @param array<string, mixed> $values */
    public function __construct(public string $relation, public array $values, public string $cursor)
    {
    }
}
