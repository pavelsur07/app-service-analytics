<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Advertising\Domain\ObservationPolicy;
use App\Shared\Domain\ValueObject\Money;
use Symfony\Component\Uid\Uuid;

final class ObservationPolicyBuilder
{
    private Uuid $company;
    private Uuid $account;
    private string $campaign = '49761882';

    private function __construct()
    {
        $this->company = Uuid::v7();
        $this->account = Uuid::v7();
    }

    public static function aPolicy(): self
    {
        return new self();
    }

    public function withScope(Uuid $company, Uuid $account): self
    {
        $copy = clone $this;
        $copy->company = $company;
        $copy->account = $account;

        return $copy;
    }

    public function withCampaign(string $campaign): self
    {
        $copy = clone $this;
        $copy->campaign = $campaign;

        return $copy;
    }

    public function build(): ObservationPolicy
    {
        return new ObservationPolicy($this->company, $this->account, $this->campaign, Money::ofMinor(10000, 'RUB'), '09:00', '21:00', 600, true, Uuid::v7(), new \DateTimeImmutable('2026-11-08T10:00:00Z'));
    }
}
