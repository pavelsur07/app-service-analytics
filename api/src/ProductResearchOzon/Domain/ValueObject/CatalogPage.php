<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain\ValueObject;

use App\ProductResearchOzon\Domain\CatalogSyncFailure;

final readonly class CatalogPage
{
    /** @param list<string> $paths */
    public function __construct(public int $revision, public \DateTimeImmutable $generatedAt, public array $paths, public ?string $nextCursor)
    {
        new CatalogMetadata($revision, $generatedAt);
        if (\count($paths) > 100 || \count(array_unique($paths)) !== \count($paths)) {
            throw new CatalogSyncFailure('invalid_catalog_response');
        }
        foreach ($paths as $path) {
            new CatalogCategory($path);
        }
        if (null !== $nextCursor && (\strlen($nextCursor) > 256 || 1 !== preg_match('/^[a-zA-Z0-9_-]+$/D', $nextCursor))) {
            throw new CatalogSyncFailure('invalid_catalog_response');
        }
    }
}
