<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Application;

final readonly class ReconciliationResult
{
    public function __construct(public ?string $nextCursor, public int $attempted, public int $failed)
    {
    }
}
