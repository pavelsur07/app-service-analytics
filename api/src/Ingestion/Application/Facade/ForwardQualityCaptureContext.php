<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

/** Verified bounded protocol configuration only; no source/model graph crosses the Facade. */
final readonly class ForwardQualityCaptureContext
{
    public function __construct(public ForwardQualityProtocolRef $protocol, public string $frozenAtUtc, public string $protocolJson)
    {
    }
}
