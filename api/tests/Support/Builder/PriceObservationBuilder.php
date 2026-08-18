<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Identity\Domain\Company;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\User;
use App\PriceMonitoring\Domain\PriceObservation;
use App\PriceMonitoring\Domain\PriceObservationRepository;
use App\Shared\Domain\ValueObject\Money;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-005: валидные умолчания, неизменяем, каждый метод возвращает
 * новый экземпляр.
 *
 * Связанные Company/User/MarketplaceAccount не создаёт сам, как
 * и `TrackedSkuBuilder`: наблюдение всегда живёт внутри уже собранного
 * окружения, и билдер, порождающий его молча, прятал бы от теста те
 * самые связи, изоляцию которых он проверяет.
 *
 * Цены задаются целиком через Money — билдер не вычисляет ни СПП,
 * ни разницу, потому что именно их проверяет тест (ADR-005).
 */
final class PriceObservationBuilder
{
    private ?Uuid $companyId = null;
    private ?Uuid $marketplaceAccountId = null;
    private string $marketplaceSku = '100000001';
    private ?\DateTimeImmutable $observedAt = null;
    private ?Money $displayedPrice = null;
    private ?Money $sellerPrice = null;
    private ?Uuid $capturedByUserId = null;
    private string $extensionVersion = '0.1.0';
    private ?\DateTimeImmutable $receivedAt = null;

    private function __construct()
    {
    }

    public static function aPriceObservation(): self
    {
        return new self();
    }

    public function withCompany(Company $company): self
    {
        $clone = clone $this;
        $clone->companyId = $company->id();

        return $clone;
    }

    public function withCompanyId(Uuid $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withMarketplaceAccount(MarketplaceAccount $account): self
    {
        $clone = clone $this;
        $clone->marketplaceAccountId = $account->id();

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

    public function withObservedAt(\DateTimeImmutable $observedAt): self
    {
        $clone = clone $this;
        $clone->observedAt = $observedAt;

        return $clone;
    }

    public function withPrices(Money $displayedPrice, Money $sellerPrice): self
    {
        $clone = clone $this;
        $clone->displayedPrice = $displayedPrice;
        $clone->sellerPrice = $sellerPrice;

        return $clone;
    }

    public function withCapturedBy(User $user): self
    {
        $clone = clone $this;
        $clone->capturedByUserId = $user->id();

        return $clone;
    }

    public function withExtensionVersion(string $extensionVersion): self
    {
        $clone = clone $this;
        $clone->extensionVersion = $extensionVersion;

        return $clone;
    }

    public function withReceivedAt(\DateTimeImmutable $receivedAt): self
    {
        $clone = clone $this;
        $clone->receivedAt = $receivedAt;

        return $clone;
    }

    public function build(): PriceObservation
    {
        $observedAt = $this->observedAt ?? new \DateTimeImmutable('2026-08-17 09:00:00');

        return PriceObservation::captured(
            companyId: $this->companyId ?? Uuid::v7(),
            marketplaceAccountId: $this->marketplaceAccountId ?? Uuid::v7(),
            marketplaceSku: $this->marketplaceSku,
            observedAt: $observedAt,
            displayedPrice: $this->displayedPrice ?? Money::ofMinor(129_900, 'RUB'),
            sellerPrice: $this->sellerPrice ?? Money::ofMinor(139_900, 'RUB'),
            capturedByUserId: $this->capturedByUserId ?? Uuid::v7(),
            extensionVersion: $this->extensionVersion,
            receivedAt: $this->receivedAt ?? $observedAt,
        );
    }

    public function persistWith(PriceObservationRepository $observations): PriceObservation
    {
        $observation = $this->build();
        $observations->record($observation);

        return $observation;
    }
}
