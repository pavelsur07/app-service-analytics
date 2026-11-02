<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Infrastructure\Query;

final readonly class ResearchProjectRow
{
    public function __construct(public string $id, public string $categoryPath, public int $categoryRevision, public string $name, public string $comment, public bool $includeCurrentMonth, public int $periodCount, public int $version, public string $createdByUserId, public string $createdAt, public string $updatedAt, public string $status = 'draft')
    {
    }
}
