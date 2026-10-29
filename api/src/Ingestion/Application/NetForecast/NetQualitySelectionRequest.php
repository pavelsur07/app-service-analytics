<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\NetForwardArtifactKind;

final readonly class NetQualitySelectionRequest
{
    public function __construct(public string $requestId, public NetQualityArtifactRef $protocol, public NetQualityArtifactRef $inventory)
    {
        NetQualitySchema::uuid($requestId, true);
        if (NetForwardArtifactKind::QualityProtocol !== $protocol->kind || NetForwardArtifactKind::QualityInventory !== $inventory->kind) {
            throw new \InvalidArgumentException('Selection requires protocol and capture inventory references.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['requestId' => $this->requestId, 'protocol' => $this->protocol->toArray(), 'inventory' => $this->inventory->toArray()];
    }
}
