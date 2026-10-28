<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Immutable accepted-state ledger ADR-049; writes only through atomic DBAL CTE. */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_return_fact_revision')]
#[ORM\UniqueConstraint(name: 'uq_return_revision_id', columns: ['id'])]
#[ORM\Index(name: 'idx_return_revision_order_sku', columns: ['company_id', 'marketplace_account_id', 'order_number', 'marketplace_sku', 'captured_at'])]
#[ORM\Index(name: 'idx_return_revision_captured', columns: ['company_id', 'marketplace_account_id', 'captured_at', 'id'])]
class MarketplaceReturnFactRevision
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $marketplaceAccountId;

    #[ORM\Id]
    #[ORM\Column(type: 'text')]
    private readonly string $sourceRowId;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    private readonly int $factRevision;

    #[ORM\Column(length: 64)]
    private readonly string $orderNumber;

    #[ORM\Column(length: 64)]
    private readonly string $marketplaceSku;

    #[ORM\Column(length: 64)]
    private readonly string $returnType;

    #[ORM\Column(type: 'text')]
    private readonly string $returnReasonName;

    /**
     * Наблюдаемый posting_number сохраняется для trace. API не доказывает,
     * что это «дочернее отправление», поэтому такой семантики у поля нет.
     */
    #[ORM\Column(length: 64)]
    private readonly string $postingNumber;

    #[ORM\Column(type: 'bigint')]
    private readonly int $sourceId;

    #[ORM\Column]
    private readonly int $quantity;

    #[ORM\Column]
    private readonly int $visualStatusId;

    #[ORM\Column(length: 64)]
    private readonly string $visualStatus;

    /**
     * Последняя смена обработки возврата, не business/outcome date.
     * Дата когорты берётся из связанной sales_fact.
     */
    #[ORM\Column]
    private readonly \DateTimeImmutable $visualStatusChangedAt;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $rawDocumentId;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private readonly string $rowHash;

    #[ORM\Column]
    private readonly \DateTimeImmutable $firstLoadedAt;

    #[ORM\Column]
    private readonly \DateTimeImmutable $lastUpdatedAt;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $id;

    #[ORM\Column(type: 'datetime_immutable')]
    private readonly \DateTimeImmutable $capturedAt;

    #[ORM\Column(length: 32)]
    private readonly string $captureVersion;

    #[ORM\Column(length: 32)]
    private readonly string $capturePath;

    #[ORM\Column(length: 32, enumType: FactCaptureOrigin::class)]
    private readonly FactCaptureOrigin $captureOrigin;

    #[ORM\Column]
    private readonly bool $captureIsInitial;

    private function __construct(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $sourceRowId,
        int $factRevision,
        string $orderNumber,
        string $marketplaceSku,
        string $returnType,
        string $returnReasonName,
        string $postingNumber,
        int $sourceId,
        int $quantity,
        int $visualStatusId,
        string $visualStatus,
        \DateTimeImmutable $visualStatusChangedAt,
        Uuid $rawDocumentId,
        string $rowHash,
        \DateTimeImmutable $firstLoadedAt,
        \DateTimeImmutable $lastUpdatedAt,
        Uuid $id,
        \DateTimeImmutable $capturedAt,
        string $captureVersion,
        string $capturePath,
        FactCaptureOrigin $captureOrigin,
        bool $captureIsInitial,
    ) {
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->sourceRowId = $sourceRowId;
        $this->factRevision = $factRevision;
        $this->orderNumber = $orderNumber;
        $this->marketplaceSku = $marketplaceSku;
        $this->returnType = $returnType;
        $this->returnReasonName = $returnReasonName;
        $this->postingNumber = $postingNumber;
        $this->sourceId = $sourceId;
        $this->quantity = $quantity;
        $this->visualStatusId = $visualStatusId;
        $this->visualStatus = $visualStatus;
        $this->visualStatusChangedAt = $visualStatusChangedAt;
        $this->rawDocumentId = $rawDocumentId;
        $this->rowHash = $rowHash;
        $this->firstLoadedAt = $firstLoadedAt;
        $this->lastUpdatedAt = $lastUpdatedAt;
        $this->id = $id;
        $this->capturedAt = $capturedAt;
        $this->captureVersion = $captureVersion;
        $this->capturePath = $capturePath;
        $this->captureOrigin = $captureOrigin;
        $this->captureIsInitial = $captureIsInitial;
    }
}
