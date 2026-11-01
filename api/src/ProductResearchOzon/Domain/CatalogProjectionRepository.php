<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain;

use App\ProductResearchOzon\Domain\ValueObject\CatalogMetadata;

interface CatalogProjectionRepository
{
    public function metadata(string $companyId, string $sourceId): ?CatalogMetadata;

    public function claim(string $companyId, string $sourceId, string $owner, \DateTimeImmutable $now): bool;

    /** @param list<string> $paths */
    public function publish(string $companyId, string $sourceId, string $owner, CatalogMetadata $metadata, array $paths, \DateTimeImmutable $now): void;

    public function fail(string $companyId, string $sourceId, string $owner, CatalogSyncFailure $failure, \DateTimeImmutable $now): void;
}
