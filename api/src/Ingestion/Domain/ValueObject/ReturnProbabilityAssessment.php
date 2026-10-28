<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\ValueObject;

final readonly class ReturnProbabilityAssessment
{
    private function __construct(public ?ReturnProbability $probability, public ?string $unavailableReason)
    {
    }

    public static function available(ReturnProbability $probability): self
    {
        return new self($probability, null);
    }

    public static function unavailable(string $reason): self
    {
        if ('' === trim($reason)) {
            throw new \InvalidArgumentException('Unavailable probability needs a reason.');
        }

        return new self(null, $reason);
    }
}
