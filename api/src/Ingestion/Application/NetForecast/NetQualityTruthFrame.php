<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

/** @phpstan-type TruthPart array{orderNumber: ?string, sku: string, day: string, quantity: ?int, state: string, reason: ?string, readyAtUtc: ?string, unitPriceMinor: ?string, currency: ?string} */
final readonly class NetQualityTruthFrame
{
    /** @param array<string, TruthPart> $parts */
    public function __construct(public NetQualityArtifactRef $reference, public NetQualityArtifactRef $inputRef, public \DateTimeImmutable $readAt, public array $parts, public string $softwareRelease)
    {
    }
}
