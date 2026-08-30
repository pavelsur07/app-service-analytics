<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Domain\SalesFact;
use App\Ingestion\Domain\SalesFactRepository;
use App\Shared\Domain\ValueObject\Money;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-005: валидные умолчания, неизменяем, разделяет build() и persistWith().
 */
final class SalesFactBuilder
{
    private Uuid $companyId;
    private Uuid $marketplaceAccountId;
    private string $sourceRowId = '40705738-0407-1|4404411581';
    private \DateTimeImmutable $businessDate;
    private string $status = 'delivered';
    private string $marketplaceSku = '4404411581';
    private ?string $postingNumber = '40705738-0407-1';
    private ?string $orderNumber = '40705738-0407';
    private int $quantity = 1;
    private Money $amount;
    private Money $commissionAmount;
    private Uuid $rawDocumentId;

    private function __construct()
    {
        $this->companyId = Uuid::v7();
        $this->marketplaceAccountId = Uuid::v7();
        $this->businessDate = new \DateTimeImmutable('2026-07-01');
        $this->amount = Money::ofMinor(216_000, 'RUB');
        $this->commissionAmount = Money::ofMinor(-32_400, 'RUB');
        $this->rawDocumentId = Uuid::v7();
    }

    public static function aSalesFact(): self
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

    public function withSourceRowId(string $sourceRowId): self
    {
        $clone = clone $this;
        $clone->sourceRowId = $sourceRowId;

        return $clone;
    }

    public function withStatus(string $status): self
    {
        $clone = clone $this;
        $clone->status = $status;

        return $clone;
    }

    public function withMarketplaceSku(string $marketplaceSku): self
    {
        $clone = clone $this;
        $clone->marketplaceSku = $marketplaceSku;

        return $clone;
    }

    public function withPostingNumber(?string $postingNumber): self
    {
        $clone = clone $this;
        $clone->postingNumber = $postingNumber;

        return $clone;
    }

    public function withOrderNumber(?string $orderNumber): self
    {
        $clone = clone $this;
        $clone->orderNumber = $orderNumber;

        return $clone;
    }

    /**
     * Дата в часовом поясе площадки (ADR-009). Задаётся снаружи, потому что
     * попадание в окно расчёта — то, что проверяет тест, и билдер не должен
     * вычислять проверяемое значение сам (ADR-005).
     */
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

    public function withAmount(Money $amount): self
    {
        $clone = clone $this;
        $clone->amount = $amount;

        return $clone;
    }

    public function withCommissionAmount(Money $commissionAmount): self
    {
        $clone = clone $this;
        $clone->commissionAmount = $commissionAmount;

        return $clone;
    }

    public function withRawDocumentId(Uuid $rawDocumentId): self
    {
        $clone = clone $this;
        $clone->rawDocumentId = $rawDocumentId;

        return $clone;
    }

    public function build(): SalesFact
    {
        return SalesFact::normalize(
            companyId: $this->companyId,
            marketplaceAccountId: $this->marketplaceAccountId,
            sourceRowId: $this->sourceRowId,
            businessDate: $this->businessDate,
            status: $this->status,
            marketplaceSku: $this->marketplaceSku,
            quantity: $this->quantity,
            amount: $this->amount,
            commissionAmount: $this->commissionAmount,
            rawDocumentId: $this->rawDocumentId,
            postingNumber: $this->postingNumber,
            orderNumber: $this->orderNumber,
        );
    }

    public function persistWith(SalesFactRepository $repository): SalesFact
    {
        $fact = $this->build();
        $repository->upsertAll([$fact]);

        return $fact;
    }
}
