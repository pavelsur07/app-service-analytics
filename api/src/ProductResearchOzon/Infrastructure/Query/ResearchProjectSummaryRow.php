<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Infrastructure\Query;

final readonly class ResearchProjectSummaryRow
{
    public function __construct(public string $id, public string $categoryPath, public string $name, public int $periodCount, public int $version, public string $createdAt)
    {
    }
}
