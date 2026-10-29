<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\NetForecastArtifactPublication;
use App\Ingestion\Domain\NetForwardArtifactKind;

/** Invocation-local immutable anchors only. S3 bytes are always verified and charged again. */
final class NetQualityArtifactCache
{
    /** @var array<string, NetForecastArtifactPublication> */
    private array $anchors = [];

    public function find(string $companyId, string $accountId, NetForwardArtifactKind $kind, string $requestId): ?NetForecastArtifactPublication
    {
        return $this->anchors[$companyId.'|'.$accountId.'|'.$kind->value.'|'.$requestId] ?? null;
    }

    public function remember(NetForecastArtifactPublication $publication): void
    {
        $root = $publication->root;
        $key = $root->companyId.'|'.$root->accountId.'|'.$root->kind->value.'|'.$root->requestId;
        if (!isset($this->anchors[$key]) && \count($this->anchors) >= 512) {
            throw new \OverflowException('Quality publication anchor cache limit exceeded.');
        }
        $existing = $this->anchors[$key] ?? null;
        if (null !== $existing && ($existing->root->sha256 !== $publication->root->sha256 || $existing->root->attemptId !== $publication->root->attemptId || $existing->requestHash !== $publication->requestHash || $existing->manifestByteSize !== $publication->manifestByteSize)) {
            throw new \UnexpectedValueException('Immutable publication anchor changed.');
        }
        $this->anchors[$key] = $publication;
    }
}
