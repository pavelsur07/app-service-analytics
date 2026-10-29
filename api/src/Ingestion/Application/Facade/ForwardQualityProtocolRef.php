<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

final readonly class ForwardQualityProtocolRef
{
    public function __construct(public string $requestId, public string $sha256)
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $requestId) || 1 !== preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
            throw new \InvalidArgumentException('Invalid quality protocol reference.');
        }
    }
}
