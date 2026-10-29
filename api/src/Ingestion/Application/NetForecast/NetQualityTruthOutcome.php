<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\NetQualityExact;

final readonly class NetQualityTruthOutcome
{
    /** @param array<string, int> $quantities
     * @param list<string> $reasons */
    public function __construct(public string $state, public ?NetQualityExact $quantity, public ?NetQualityArtifactRef $truthRef, public array $quantities, public array $reasons, public bool $censored = false)
    {
    }
}
