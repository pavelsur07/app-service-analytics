<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\ValueObject;

final readonly class NetForwardReturnDelta
{
    public function __construct(public int $quantity, public \DateTimeImmutable $capturedAt, public bool $visibleAtInput = false, public bool $proven = true, public ?string $returnType = null)
    {
    }
}
