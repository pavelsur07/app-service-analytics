<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Domain\AdSkuExpenseFact;
use App\Ingestion\Domain\AdSkuExpenseFactRepository;
use App\Ingestion\Domain\OzonAdSkuExpense;
use App\Shared\Domain\ValueObject\Money;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-005: валидные умолчания, неизменяем, разделяет build()
 * и persistWith().
 *
 * Сумма задаётся уже со знаком расхода — отрицательной, как её хранит
 * факт (ADR-035 п. 2): Builder не переводит знак за тест.
 */
final class AdSkuExpenseFactBuilder
{
    private Uuid $companyId;
    private Uuid $marketplaceAccountId;
    private string $campaignId = '14275771';
    private \DateTimeImmutable $businessDate;
    private string $marketplaceSku = '286085455';
    private Money $amount;
    private Uuid $rawDocumentId;
    private \DateTimeImmutable $sourceReceivedAt;

    private function __construct()
    {
        $this->companyId = Uuid::v7();
        $this->marketplaceAccountId = Uuid::v7();
        $this->businessDate = new \DateTimeImmutable('2026-09-23');
        $this->amount = Money::ofMinor(-119339, 'RUB');
        $this->rawDocumentId = Uuid::v7();
        $this->sourceReceivedAt = new \DateTimeImmutable('2026-09-24 08:00:00');
    }

    public static function anAdSkuExpenseFact(): self
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

    public function withCampaignId(string $campaignId): self
    {
        $clone = clone $this;
        $clone->campaignId = $campaignId;

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

    public function withSourceReceivedAt(\DateTimeImmutable $sourceReceivedAt): self
    {
        $clone = clone $this;
        $clone->sourceReceivedAt = $sourceReceivedAt;

        return $clone;
    }

    public function build(): AdSkuExpenseFact
    {
        return AdSkuExpenseFact::normalize(
            companyId: $this->companyId,
            marketplaceAccountId: $this->marketplaceAccountId,
            expense: new OzonAdSkuExpense($this->campaignId, $this->businessDate, $this->marketplaceSku, $this->amount),
            rawDocumentId: $this->rawDocumentId,
            sourceReceivedAt: $this->sourceReceivedAt,
        );
    }

    public function persistWith(AdSkuExpenseFactRepository $repository): AdSkuExpenseFact
    {
        $fact = $this->build();
        $repository->upsertAll([$fact]);

        return $fact;
    }
}
