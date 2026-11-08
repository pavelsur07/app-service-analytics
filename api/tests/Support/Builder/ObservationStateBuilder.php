<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Advertising\Domain\ObservationPolicy;
use App\Advertising\Domain\ObservationState;
use App\Shared\Domain\ValueObject\Money;

final class ObservationStateBuilder
{
    private ObservationPolicy $policy;
    private Money $spend;

    private function __construct()
    {
        $this->policy = ObservationPolicyBuilder::aPolicy()->build();
        $this->spend = Money::ofMinor(103, 'RUB');
    }

    public static function aState(): self
    {
        return new self();
    }

    public function withPolicy(ObservationPolicy $policy): self
    {
        $copy = clone $this;
        $copy->policy = $policy;

        return $copy;
    }

    public function withSpend(Money $spend): self
    {
        $copy = clone $this;
        $copy->spend = $spend;

        return $copy;
    }

    public function build(): ObservationState
    {
        $at = new \DateTimeImmutable('2026-11-08T10:00:00Z');

        return new ObservationState($this->policy->companyId(), $this->policy->id(), $this->policy->accountId(), $this->policy->version(), new \DateTimeImmutable('2026-11-08'), $this->spend, null, 'blocked', 'reserve_unavailable', false, $at);
    }
}
