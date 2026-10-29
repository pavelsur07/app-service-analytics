<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\NetForwardArtifactKind;

final readonly class NetQualityArtifactDocument
{
    /** @param array<string, mixed> $metadata
     * @param iterable<array{type: string, row: array<array-key, mixed>}> $segments */
    public function __construct(public NetForwardArtifactKind $kind, public string $requestId, public string $requestHash, public ?NetQualityArtifactRef $parent, public array $metadata, public iterable $segments)
    {
        new NetQualityArtifactRef($kind, $requestId, $requestHash);
        if (\in_array($kind, [NetForwardArtifactKind::Input, NetForwardArtifactKind::Truth], true) || $kind->parentKind() !== $parent?->kind) {
            throw new \InvalidArgumentException('Invalid quality document kind or parent.');
        }
    }
}
