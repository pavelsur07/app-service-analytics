<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain;

final readonly class CategoryReference
{
    public function __construct(public string $path, public int $revision)
    {
        if ('' === trim($path) || mb_strlen($path) > 2048 || $revision < 1) {
            throw new \InvalidArgumentException('invalid_category');
        }
    }
}
