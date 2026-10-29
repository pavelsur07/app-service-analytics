<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\ValueObject;

final readonly class NetForwardTruthResult
{
    public function __construct(public string $state, public ?int $quantity, public ?string $reason, public ?\DateTimeImmutable $readyAt)
    {
        if (!\in_array($state, ['ready', 'pending', 'ineligible'], true) || ('ready' === $state) !== (null !== $quantity) || (null !== $quantity && $quantity < 0)) {
            throw new \InvalidArgumentException('Invalid truth result.');
        }
    }
}
