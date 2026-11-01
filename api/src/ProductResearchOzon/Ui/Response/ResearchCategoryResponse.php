<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Response;

final readonly class ResearchCategoryResponse
{
    public string $path;

    public function __construct(string $fullPath)
    {
        $this->path = $fullPath;
    }
}
