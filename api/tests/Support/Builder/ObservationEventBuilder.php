<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Advertising\Domain\ObservationEvent;
use App\Advertising\Domain\ObservationPolicy;

final class ObservationEventBuilder
{
    private ObservationPolicy $policy;

    private function __construct()
    {
        $this->policy = ObservationPolicyBuilder::aPolicy()->build();
    }

    public static function anEvent(): self
    {
        return new self();
    }

    public function withPolicy(ObservationPolicy $policy): self
    {
        $copy = clone $this;
        $copy->policy = $policy;

        return $copy;
    }

    public function build(): ObservationEvent
    {
        return new ObservationEvent($this->policy->companyId(), $this->policy->id(), $this->policy->accountId(), $this->policy->campaignId(), $this->policy->version(), 'policy:1', 'policy_created', null, 'saved', 'user_change', ['start' => '09:00'], new \DateTimeImmutable('2026-11-08T10:00:00Z'), $this->policy->dailyLimit());
    }
}
