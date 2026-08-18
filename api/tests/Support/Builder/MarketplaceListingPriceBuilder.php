<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Domain\MarketplaceListingPrice;
use App\Shared\Domain\ValueObject\Money;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-005: валидные умолчания, неизменяем, каждый метод возвращает
 * новый экземпляр.
 *
 * Момент и цены задаются снаружи и билдером не вычисляются: именно их
 * проверяет тест «строка появляется только при изменении».
 */
final class MarketplaceListingPriceBuilder
{
    private ?Uuid $companyId = null;
    private ?Uuid $marketplaceAccountId = null;
    private string $marketplaceSku = '100000001';
    private ?\DateTimeImmutable $changedAt = null;
    private ?Money $price = null;
    private ?Money $oldPrice = null;

    private function __construct()
    {
    }

    public static function aMarketplaceListingPrice(): self
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

    public function withChangedAt(\DateTimeImmutable $changedAt): self
    {
        $clone = clone $this;
        $clone->changedAt = $changedAt;

        return $clone;
    }

    public function withPrice(Money $price, ?Money $oldPrice = null): self
    {
        $clone = clone $this;
        $clone->price = $price;
        $clone->oldPrice = $oldPrice;

        return $clone;
    }

    public function build(): MarketplaceListingPrice
    {
        return MarketplaceListingPrice::seen(
            companyId: $this->companyId ?? Uuid::v7(),
            marketplaceAccountId: $this->marketplaceAccountId ?? Uuid::v7(),
            marketplaceSku: $this->marketplaceSku,
            changedAt: $this->changedAt ?? new \DateTimeImmutable('2026-08-18 09:00:00'),
            price: $this->price ?? Money::ofMinor(253_700, 'RUB'),
            oldPrice: $this->oldPrice,
        );
    }
}
