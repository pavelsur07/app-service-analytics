<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

final readonly class NetForecastArtifactPublication
{
    public function __construct(public NetForwardArtifactKey $root, public string $requestHash, public int $manifestByteSize)
    {
        if (null !== $root->ordinal || 1 !== preg_match('/^[a-f0-9]{64}$/D', $requestHash) || $manifestByteSize < 1 || $manifestByteSize > 1048576) {
            throw new \InvalidArgumentException('Invalid publication anchor.');
        }
    }
}
