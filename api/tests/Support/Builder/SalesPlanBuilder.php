<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\SalesPlanning\Domain\SalesPlan;
use App\SalesPlanning\Domain\SalesPlanRepository;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-005: валидные умолчания, неизменяем, разделяет build()
 * и persistWith().
 *
 * Период по умолчанию — июль: 31 день, на нём видна раскладка
 * с остатком.
 */
final class SalesPlanBuilder
{
    private Uuid $companyId;
    private Uuid $marketplaceAccountId;
    private string $name = 'План на июль';
    private \DateTimeImmutable $dateFrom;
    private \DateTimeImmutable $dateTo;
    private string $currency = 'RUB';
    private Uuid $createdByUserId;
    private \DateTimeImmutable $createdAt;

    private function __construct()
    {
        $this->companyId = Uuid::v7();
        $this->marketplaceAccountId = Uuid::v7();
        $this->dateFrom = new \DateTimeImmutable('2026-07-01');
        $this->dateTo = new \DateTimeImmutable('2026-07-31');
        $this->createdByUserId = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable('2026-06-25 10:00:00');
    }

    public static function aSalesPlan(): self
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

    public function withName(string $name): self
    {
        $clone = clone $this;
        $clone->name = $name;

        return $clone;
    }

    public function withPeriod(\DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): self
    {
        $clone = clone $this;
        $clone->dateFrom = $dateFrom;
        $clone->dateTo = $dateTo;

        return $clone;
    }

    public function withCurrency(string $currency): self
    {
        $clone = clone $this;
        $clone->currency = $currency;

        return $clone;
    }

    public function withCreatedByUserId(Uuid $createdByUserId): self
    {
        $clone = clone $this;
        $clone->createdByUserId = $createdByUserId;

        return $clone;
    }

    public function withCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $clone = clone $this;
        $clone->createdAt = $createdAt;

        return $clone;
    }

    public function build(): SalesPlan
    {
        return SalesPlan::draft(
            $this->companyId,
            $this->marketplaceAccountId,
            $this->name,
            $this->dateFrom,
            $this->dateTo,
            $this->currency,
            $this->createdByUserId,
            $this->createdAt,
        );
    }

    /**
     * План без строк. План со строками сохраняется одним вызовом
     * `SalesPlanRepository::add`, строки — из `SalesPlanLineBuilder`.
     */
    public function persistWith(SalesPlanRepository $repository): SalesPlan
    {
        $plan = $this->build();
        $repository->add($plan, []);

        return $plan;
    }
}
