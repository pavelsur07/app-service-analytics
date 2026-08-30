<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Domain\MarketplacePostingStatus;
use Symfony\Component\Uid\Uuid;

final class MarketplacePostingStatusBuilder
{
    private Uuid $companyId;
    private Uuid $marketplaceAccountId;
    private string $postingNumber = 'TEST-POSTING-1';
    private string $orderNumber = 'TEST-ORDER-1';
    private string $status = 'awaiting_packaging';
    private ?string $substatus = 'posting_created';
    private ?int $cancelReasonId = null;
    private \DateTimeImmutable $observedAt;
    private Uuid $rawDocumentId;

    private function __construct()
    {
        $this->companyId = Uuid::v7();
        $this->marketplaceAccountId = Uuid::v7();
        $this->observedAt = new \DateTimeImmutable('2026-08-30 09:00:00');
        $this->rawDocumentId = Uuid::v7();
    }

    public static function aMarketplacePostingStatus(): self
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

    public function withPostingNumber(string $postingNumber): self
    {
        $clone = clone $this;
        $clone->postingNumber = $postingNumber;

        return $clone;
    }

    public function withOrderNumber(string $orderNumber): self
    {
        $clone = clone $this;
        $clone->orderNumber = $orderNumber;

        return $clone;
    }

    public function withStatus(string $status, ?string $substatus = null, ?int $cancelReasonId = null): self
    {
        $clone = clone $this;
        $clone->status = $status;
        $clone->substatus = \func_num_args() >= 2 ? $substatus : match ($status) {
            'awaiting_packaging' => 'posting_created',
            'awaiting_deliver' => 'posting_transferring_to_delivery',
            'delivering' => 'posting_on_way_to_city',
            'delivered' => 'posting_received',
            'cancelled' => 'posting_canceled',
            default => null,
        };
        $clone->cancelReasonId = $cancelReasonId;

        return $clone;
    }

    public function withObservedAt(\DateTimeImmutable $observedAt): self
    {
        $clone = clone $this;
        $clone->observedAt = $observedAt;

        return $clone;
    }

    public function withRawDocumentId(Uuid $rawDocumentId): self
    {
        $clone = clone $this;
        $clone->rawDocumentId = $rawDocumentId;

        return $clone;
    }

    public function build(): MarketplacePostingStatus
    {
        return MarketplacePostingStatus::observe(
            companyId: $this->companyId,
            marketplaceAccountId: $this->marketplaceAccountId,
            postingNumber: $this->postingNumber,
            orderNumber: $this->orderNumber,
            status: $this->status,
            substatus: $this->substatus,
            cancelReasonId: $this->cancelReasonId,
            observedAt: $this->observedAt,
            rawDocumentId: $this->rawDocumentId,
        );
    }
}
