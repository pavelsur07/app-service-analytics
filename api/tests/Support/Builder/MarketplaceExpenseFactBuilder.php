<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Domain\MarketplaceExpenseFact;
use App\Ingestion\Domain\MarketplaceExpenseFactRepository;
use App\Shared\Domain\ValueObject\Money;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-005: валидные умолчания, неизменяем, разделяет build()
 * и persistWith().
 *
 * Умолчание — расход по товару: артикул заполнен, сумма отрицательная.
 * Общий расход (реклама, хранение) строится через withoutSku() — так
 * в тесте видно, что пустой артикул выбран, а не забыт.
 */
final class MarketplaceExpenseFactBuilder
{
    private Uuid $companyId;
    private Uuid $marketplaceAccountId;
    private int $accrualId = 55123734698;
    private \DateTimeImmutable $businessDate;
    private string $marketplaceSku = '1988145769';
    private int $feeTypeId = 32;
    private string $unitNumber = '0157015884-0130-1';
    private Money $amount;
    private Uuid $rawDocumentId;

    private function __construct()
    {
        $this->companyId = Uuid::v7();
        $this->marketplaceAccountId = Uuid::v7();
        $this->businessDate = new \DateTimeImmutable('2026-07-01');
        $this->amount = Money::ofMinor(-11500, 'RUB');
        $this->rawDocumentId = Uuid::v7();
    }

    public static function aMarketplaceExpenseFact(): self
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

    public function withAccrualId(int $accrualId): self
    {
        $clone = clone $this;
        $clone->accrualId = $accrualId;

        return $clone;
    }

    public function withBusinessDate(\DateTimeImmutable $businessDate): self
    {
        $clone = clone $this;
        $clone->businessDate = $businessDate;

        return $clone;
    }

    public function withMarketplaceSku(string $marketplaceSku): self
    {
        $clone = clone $this;
        $clone->marketplaceSku = $marketplaceSku;

        return $clone;
    }

    /**
     * Расход, не привязанный к товару: реклама, хранение, досрочная
     * выплата (ADR-012).
     */
    public function withoutSku(): self
    {
        return $this->withMarketplaceSku('');
    }

    public function withFeeTypeId(int $feeTypeId): self
    {
        $clone = clone $this;
        $clone->feeTypeId = $feeTypeId;

        return $clone;
    }

    public function withUnitNumber(string $unitNumber): self
    {
        $clone = clone $this;
        $clone->unitNumber = $unitNumber;

        return $clone;
    }

    public function withAmount(Money $amount): self
    {
        $clone = clone $this;
        $clone->amount = $amount;

        return $clone;
    }

    public function withRawDocumentId(Uuid $rawDocumentId): self
    {
        $clone = clone $this;
        $clone->rawDocumentId = $rawDocumentId;

        return $clone;
    }

    public function build(): MarketplaceExpenseFact
    {
        return MarketplaceExpenseFact::normalize(
            companyId: $this->companyId,
            marketplaceAccountId: $this->marketplaceAccountId,
            accrualId: $this->accrualId,
            businessDate: $this->businessDate,
            marketplaceSku: $this->marketplaceSku,
            feeTypeId: $this->feeTypeId,
            unitNumber: $this->unitNumber,
            amount: $this->amount,
            rawDocumentId: $this->rawDocumentId,
        );
    }

    public function persistWith(MarketplaceExpenseFactRepository $repository): MarketplaceExpenseFact
    {
        $fact = $this->build();
        $repository->upsertAll([$fact]);

        return $fact;
    }
}
