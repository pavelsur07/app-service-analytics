<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain\ValueObject;

use App\ProductResearchOzon\Domain\CatalogSyncFailure;

final readonly class CatalogMetadata
{
    public \DateTimeImmutable $generatedAt;

    public function __construct(public int $revision, \DateTimeImmutable $generated)
    {
        if ($revision < 1) {
            throw new CatalogSyncFailure('invalid_catalog_response');
        }
        $this->generatedAt = $generated->setTimezone(new \DateTimeZone('UTC'));
    }

    public function sameGeneration(self $other): bool
    {
        return $this->revision === $other->revision && $this->generatedAt->format('U.u') === $other->generatedAt->format('U.u');
    }

    public function isFresh(\DateTimeImmutable $now): bool
    {
        return $this->generatedAt <= $now && $now < $this->expiresAt();
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->generatedAt->add(new \DateInterval('PT24H'));
    }
}
