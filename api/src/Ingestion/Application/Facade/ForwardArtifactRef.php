<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

final readonly class ForwardArtifactRef
{
    public function __construct(public string $kind, public string $requestId, public string $artifactHash, public \DateTimeImmutable $actualReadAt, public int $chunks)
    {
    }
}
