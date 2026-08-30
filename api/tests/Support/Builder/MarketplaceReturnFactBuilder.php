<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Domain\MarketplaceReturnFact;
use App\Ingestion\Domain\MarketplaceReturnFactRepository;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-005: валидные умолчания, неизменяемый fluent builder.
 */
final class MarketplaceReturnFactBuilder
{
    private Uuid $companyId;
    private Uuid $marketplaceAccountId;
    private string $sourceRowId = '900001';
    private string $orderNumber = 'TEST-ORDER-1';
    private string $marketplaceSku = '100001';
    private string $returnType = 'Cancellation';
    private string $returnReasonName = 'Покупатель отказался при вручении: товар не подошел';
    private string $postingNumber = 'TEST-ORDER-1-RETURN-1';
    private int $sourceId = 800001;
    private int $quantity = 1;
    private int $visualStatusId = 1;
    private string $visualStatus = 'Completed';
    private \DateTimeImmutable $visualStatusChangedAt;
    private Uuid $rawDocumentId;

    private function __construct()
    {
        $this->companyId = Uuid::v7();
        $this->marketplaceAccountId = Uuid::v7();
        $this->visualStatusChangedAt = new \DateTimeImmutable('2026-08-11T10:00:00Z');
        $this->rawDocumentId = Uuid::v7();
    }

    public static function aMarketplaceReturnFact(): self
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

    public function withOrderNumber(string $orderNumber): self
    {
        $clone = clone $this;
        $clone->orderNumber = $orderNumber;

        return $clone;
    }

    public function withMarketplaceSku(string $marketplaceSku): self
    {
        $clone = clone $this;
        $clone->marketplaceSku = $marketplaceSku;

        return $clone;
    }

    public function withReturnType(string $returnType): self
    {
        $clone = clone $this;
        $clone->returnType = $returnType;

        return $clone;
    }

    public function withReturnReasonName(string $returnReasonName): self
    {
        $clone = clone $this;
        $clone->returnReasonName = $returnReasonName;

        return $clone;
    }

    public function withPostingNumber(string $postingNumber): self
    {
        $clone = clone $this;
        $clone->postingNumber = $postingNumber;

        return $clone;
    }

    public function withSourceId(int $sourceId): self
    {
        $clone = clone $this;
        $clone->sourceId = $sourceId;

        return $clone;
    }

    public function withQuantity(int $quantity): self
    {
        $clone = clone $this;
        $clone->quantity = $quantity;

        return $clone;
    }

    public function withVisualStatus(int $id, string $status, \DateTimeImmutable $changedAt): self
    {
        $clone = clone $this;
        $clone->visualStatusId = $id;
        $clone->visualStatus = $status;
        $clone->visualStatusChangedAt = $changedAt;

        return $clone;
    }

    public function withRawDocumentId(Uuid $rawDocumentId): self
    {
        $clone = clone $this;
        $clone->rawDocumentId = $rawDocumentId;

        return $clone;
    }

    public function build(): MarketplaceReturnFact
    {
        return MarketplaceReturnFact::normalize(
            companyId: $this->companyId,
            marketplaceAccountId: $this->marketplaceAccountId,
            sourceRowId: $this->sourceRowId,
            orderNumber: $this->orderNumber,
            marketplaceSku: $this->marketplaceSku,
            returnType: $this->returnType,
            returnReasonName: $this->returnReasonName,
            postingNumber: $this->postingNumber,
            sourceId: $this->sourceId,
            quantity: $this->quantity,
            visualStatusId: $this->visualStatusId,
            visualStatus: $this->visualStatus,
            visualStatusChangedAt: $this->visualStatusChangedAt,
            rawDocumentId: $this->rawDocumentId,
        );
    }

    public function persistWith(MarketplaceReturnFactRepository $repository): MarketplaceReturnFact
    {
        $fact = $this->build();
        $repository->upsertAll([$fact]);

        return $fact;
    }
}
