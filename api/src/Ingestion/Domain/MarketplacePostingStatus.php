<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Наблюдение статуса FBO posting (ADR-019).
 *
 * Факт пишется только DBAL writer'ом. Entity нужен для проверки metadata
 * и схемы; ORM persist/flush для этой таблицы запрещён.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_posting_status')]
#[ORM\Index(name: 'idx_marketplace_posting_status_effective', columns: ['company_id', 'marketplace_account_id', 'posting_number', 'observed_at', 'raw_document_id'])]
#[ORM\Index(name: 'idx_marketplace_posting_status_maturity', columns: ['company_id', 'marketplace_account_id', 'observed_at', 'status'])]
#[ORM\Index(name: 'idx_marketplace_posting_status_raw', columns: ['company_id', 'raw_document_id'])]
class MarketplacePostingStatus
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $marketplaceAccountId;

    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private readonly string $postingNumber;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $rawDocumentId;

    #[ORM\Column(length: 64)]
    private readonly string $orderNumber;

    #[ORM\Column(length: 32)]
    private readonly string $status;

    #[ORM\Column(length: 64, nullable: true)]
    private readonly ?string $substatus;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private readonly ?int $cancelReasonId;

    #[ORM\Column(type: 'datetime_immutable')]
    private readonly \DateTimeImmutable $observedAt;

    private function __construct(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $postingNumber,
        string $orderNumber,
        string $status,
        ?string $substatus,
        ?int $cancelReasonId,
        \DateTimeImmutable $observedAt,
        Uuid $rawDocumentId,
    ) {
        if ('' === $postingNumber || '' === $orderNumber || '' === $status) {
            throw new \InvalidArgumentException('Posting number, order number and status must be non-empty.');
        }

        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->postingNumber = $postingNumber;
        $this->orderNumber = $orderNumber;
        $this->status = $status;
        $this->substatus = $substatus;
        $this->cancelReasonId = $cancelReasonId;
        $this->observedAt = $observedAt;
        $this->rawDocumentId = $rawDocumentId;
    }

    public static function observe(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $postingNumber,
        string $orderNumber,
        string $status,
        ?string $substatus,
        ?int $cancelReasonId,
        \DateTimeImmutable $observedAt,
        Uuid $rawDocumentId,
    ): self {
        return new self(
            $companyId,
            $marketplaceAccountId,
            $postingNumber,
            $orderNumber,
            $status,
            $substatus,
            $cancelReasonId,
            $observedAt,
            $rawDocumentId,
        );
    }

    public function companyId(): Uuid
    {
        return $this->companyId;
    }

    public function marketplaceAccountId(): Uuid
    {
        return $this->marketplaceAccountId;
    }

    public function postingNumber(): string
    {
        return $this->postingNumber;
    }

    public function orderNumber(): string
    {
        return $this->orderNumber;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function substatus(): ?string
    {
        return $this->substatus;
    }

    public function cancelReasonId(): ?int
    {
        return $this->cancelReasonId;
    }

    public function observedAt(): \DateTimeImmutable
    {
        return $this->observedAt;
    }

    public function rawDocumentId(): Uuid
    {
        return $this->rawDocumentId;
    }
}
