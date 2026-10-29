<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

interface NetForecastArtifactRepository
{
    public function publishFirst(string $companyId, string $accountId, NetForecastArtifactPublication $candidate, int $deadlineNs): NetForecastArtifactPublication;

    public function find(string $companyId, string $accountId, NetForwardArtifactKind $kind, string $requestId): ?NetForecastArtifactPublication;

    /** @param list<array{kind: NetForwardArtifactKind, requestId: string}> $identities
     * @return list<NetForecastArtifactPublication> */
    public function findBatch(string $companyId, string $accountId, array $identities): array;
}
