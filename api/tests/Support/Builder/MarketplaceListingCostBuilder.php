<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Domain\MarketplaceListingCost;
use App\Ingestion\Domain\MarketplaceListingCostRepository;
use App\Shared\Domain\ValueObject\Money;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-005: валидные умолчания, неизменяем, разделяет build()
 * и persistWith().
 *
 * Сумму и дату начала действия задаёт тест: это ровно те значения,
 * которые он и проверяет, — билдер не должен вычислять проверяемое.
 */
final class MarketplaceListingCostBuilder
{
    private Uuid $companyId;
    private Uuid $marketplaceAccountId;
    private string $marketplaceSku = '1988146647';
    private \DateTimeImmutable $effectiveFrom;
    private Money $unitCost;
    private \DateTimeImmutable $recordedAt;

    private function __construct()
    {
        $this->companyId = Uuid::v7();
        $this->marketplaceAccountId = Uuid::v7();
        $this->effectiveFrom = new \DateTimeImmutable('2026-07-01');
        $this->unitCost = Money::ofMinor(42_000, 'RUB');
        $this->recordedAt = new \DateTimeImmutable('2026-07-01 10:00:00');
    }

    public static function aMarketplaceListingCost(): self
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

    public function withEffectiveFrom(\DateTimeImmutable $effectiveFrom): self
    {
        $clone = clone $this;
        $clone->effectiveFrom = $effectiveFrom;

        return $clone;
    }

    public function withUnitCost(Money $unitCost): self
    {
        $clone = clone $this;
        $clone->unitCost = $unitCost;

        return $clone;
    }

    public function withRecordedAt(\DateTimeImmutable $recordedAt): self
    {
        $clone = clone $this;
        $clone->recordedAt = $recordedAt;

        return $clone;
    }

    public function build(): MarketplaceListingCost
    {
        return MarketplaceListingCost::pricedFrom(
            $this->companyId,
            $this->marketplaceAccountId,
            $this->marketplaceSku,
            $this->effectiveFrom,
            $this->unitCost,
            $this->recordedAt,
        );
    }

    public function persistWith(MarketplaceListingCostRepository $repository): MarketplaceListingCost
    {
        $cost = $this->build();
        $repository->add($cost);

        return $cost;
    }
}
