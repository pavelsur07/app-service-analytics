<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

final readonly class NetQualityTruthInventory
{
    /** @param list<array<string, mixed>> $slots */
    public function __construct(public NetQualityArtifactRef $reference, public NetQualityArtifactRef $protocolRef, public \DateTimeImmutable $cutoff, public array $slots, public \DateTimeImmutable $sealedAt)
    {
    }
}
