<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain\ValueObject;

final readonly class RunProvenance
{
    public function __construct(public string $origin, public \DateTimeImmutable $collectedAt, public \DateTimeImmutable $usedAt)
    {
        if (!\in_array($origin, ['api', 'cache'], true) || $collectedAt > $usedAt) {
            throw new \InvalidArgumentException('invalid_run_provenance');
        }
    }

    /** @return array{origin: string, collected_at: string, used_at: string} */
    public function payload(): array
    {
        return ['origin' => $this->origin, 'collected_at' => $this->collectedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'), 'used_at' => $this->usedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP')];
    }
}
