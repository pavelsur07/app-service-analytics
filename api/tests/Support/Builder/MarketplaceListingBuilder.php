<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Domain\MarketplaceListing;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-005: валидные умолчания, неизменяем.
 *
 * Момент первой встречи задаётся снаружи: это единственная неключевая
 * колонка таблицы и ровно то, что проверяет тест идемпотентности —
 * билдер не должен вычислять проверяемое значение сам.
 */
final class MarketplaceListingBuilder
{
    private Uuid $companyId;
    private Uuid $marketplaceAccountId;
    private string $marketplaceSku = '1988146647';
    private ?string $offerId = 'WJ1621101211-черный-M';
    private ?string $name = 'Топ Womjoy Logo Basic';
    private \DateTimeImmutable $seenAt;

    private function __construct()
    {
        $this->companyId = Uuid::v7();
        $this->marketplaceAccountId = Uuid::v7();
        $this->seenAt = new \DateTimeImmutable('2026-08-13 10:00:00');
    }

    public static function aMarketplaceListing(): self
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

    public function withOfferId(?string $offerId): self
    {
        $clone = clone $this;
        $clone->offerId = $offerId;

        return $clone;
    }

    public function withName(?string $name): self
    {
        $clone = clone $this;
        $clone->name = $name;

        return $clone;
    }

    public function withSeenAt(\DateTimeImmutable $seenAt): self
    {
        $clone = clone $this;
        $clone->seenAt = $seenAt;

        return $clone;
    }

    public function build(): MarketplaceListing
    {
        return MarketplaceListing::seen(
            $this->companyId,
            $this->marketplaceAccountId,
            $this->marketplaceSku,
            $this->offerId,
            $this->name,
            $this->seenAt,
        );
    }
}
