<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

interface NetForwardArtifactStorage
{
    public function putContent(string $companyId, string $accountId, NetForwardArtifactKey $key, string $body): void;

    public function get(string $companyId, string $accountId, NetForwardArtifactKey $key, int $maxBytes): string;
}
