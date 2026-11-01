<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain\ValueObject;

final readonly class RunDelivery
{
    public function __construct(public string $companyId, public string $runId, public string $projectId, public string $sourceId, public string $payload, public string $payloadHash, public int $attempts)
    {
        if (!RunProgress::validId($companyId) || !RunProgress::validId($runId) || !RunProgress::validId($projectId) || !RunProgress::validId($sourceId) || \strlen($payload) > 65536 || hash('sha256', $payload) !== $payloadHash || $attempts < 0) {
            throw new \InvalidArgumentException('invalid_run_delivery');
        }
    }
}
