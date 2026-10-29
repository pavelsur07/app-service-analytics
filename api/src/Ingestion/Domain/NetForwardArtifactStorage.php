<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

interface NetForwardArtifactStorage
{
    /** @param (\Closure(int): void)|null $beforeRead */
    public function putContent(string $companyId, string $accountId, NetForwardArtifactKey $key, string $body, ?\Closure $beforeRead = null): void;

    public function get(string $companyId, string $accountId, NetForwardArtifactKey $key, int $maxBytes): string;
}
