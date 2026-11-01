<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain\ValueObject;

final readonly class RunView
{
    public function __construct(public string $projectId, public RunProgress $progress, public bool $delivered, public \DateTimeImmutable $createdAt)
    {
    }
}
