<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Planning\Domain\DailyPlan;
use Symfony\Component\Uid\Uuid;

final class DailyPlanBuilder
{
    private Uuid $companyId;
    private Uuid $marketplaceAccountId;
    private string $marketplaceSku = 'SKU-1';
    private \DateTimeImmutable $businessDate;
    private int $quantity = 12;
    private Uuid $updatedBy;
    private \DateTimeImmutable $createdAt;

    private function __construct()
    {
        $this->companyId = Uuid::v7();
        $this->marketplaceAccountId = Uuid::v7();
        $this->businessDate = new \DateTimeImmutable('2026-09-21');
        $this->updatedBy = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable('2026-09-20 10:00:00 UTC');
    }

    public static function aDailyPlan(): self
    {
        return new self();
    }

    public function withCompanyId(Uuid $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withMarketplaceAccountId(Uuid $marketplaceAccountId): self
    {
        $clone = clone $this;
        $clone->marketplaceAccountId = $marketplaceAccountId;

        return $clone;
    }

    public function withMarketplaceSku(string $marketplaceSku): self
    {
        $clone = clone $this;
        $clone->marketplaceSku = $marketplaceSku;

        return $clone;
    }

    public function withBusinessDate(\DateTimeImmutable $businessDate): self
    {
        $clone = clone $this;
        $clone->businessDate = $businessDate;

        return $clone;
    }

    public function withQuantity(int $quantity): self
    {
        $clone = clone $this;
        $clone->quantity = $quantity;

        return $clone;
    }

    public function withUpdatedBy(Uuid $updatedBy): self
    {
        $clone = clone $this;
        $clone->updatedBy = $updatedBy;

        return $clone;
    }

    public function withCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $clone = clone $this;
        $clone->createdAt = $createdAt;

        return $clone;
    }

    public function build(): DailyPlan
    {
        return DailyPlan::create(
            $this->companyId,
            $this->marketplaceAccountId,
            $this->marketplaceSku,
            $this->businessDate,
            $this->quantity,
            $this->updatedBy,
            $this->createdAt,
        );
    }
}
