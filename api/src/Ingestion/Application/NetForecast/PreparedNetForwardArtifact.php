<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\NetForecastArtifactPublication;

final readonly class PreparedNetForwardArtifact
{
    public function __construct(public NetForecastArtifactPublication $publication, public string $rootBytes, public NetForwardSpool $spool)
    {
        if (\strlen($rootBytes) !== $publication->manifestByteSize || hash('sha256', $rootBytes) !== $publication->root->sha256) {
            throw new \InvalidArgumentException('Prepared root integrity mismatch.');
        }
    }
}
