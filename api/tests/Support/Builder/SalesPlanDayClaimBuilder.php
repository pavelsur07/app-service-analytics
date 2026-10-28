<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\SalesPlanning\Domain\SalesPlan;
use App\SalesPlanning\Domain\SalesPlanDayClaim;

/**
 * ADR-005: неизменяемый Builder с валидными умолчаниями.
 * Claims пишет DBAL: Builder только собирает metadata Entity, не сохраняет её ORM.
 */
final class SalesPlanDayClaimBuilder
{
    private string $marketplaceSku = '1988146647';
    private \DateTimeImmutable $date;

    private function __construct(private readonly SalesPlan $plan)
    {
        $this->date = $plan->dateFrom();
    }

    public static function aDayClaim(SalesPlan $plan): self
    {
        return new self($plan);
    }

    public function withMarketplaceSku(string $marketplaceSku): self
    {
        $clone = clone $this;
        $clone->marketplaceSku = $marketplaceSku;

        return $clone;
    }

    public function withDate(\DateTimeImmutable $date): self
    {
        $clone = clone $this;
        $clone->date = $date;

        return $clone;
    }

    public function build(): SalesPlanDayClaim
    {
        return new SalesPlanDayClaim($this->plan, $this->marketplaceSku, $this->date);
    }
}
