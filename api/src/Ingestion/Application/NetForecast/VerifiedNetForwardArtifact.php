<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\NetForecastArtifactPublication;

final readonly class VerifiedNetForwardArtifact
{
    /** @param array<array-key, mixed> $manifest */
    public function __construct(public NetForecastArtifactPublication $publication, public \DateTimeImmutable $readAt, public array $manifest)
    {
    }
}
