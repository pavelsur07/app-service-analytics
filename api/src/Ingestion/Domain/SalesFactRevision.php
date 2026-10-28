<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Immutable accepted-state ledger ADR-049; writes only through atomic DBAL CTE. */
#[ORM\Entity]
#[ORM\Table(name: 'sales_fact_revision')]
#[ORM\UniqueConstraint(name: 'uq_sales_revision_id', columns: ['id'])]
#[ORM\Index(name: 'idx_sales_revision_order_sku', columns: ['company_id', 'marketplace_account_id', 'order_number', 'marketplace_sku', 'captured_at'])]
#[ORM\Index(name: 'idx_sales_revision_captured', columns: ['company_id', 'marketplace_account_id', 'captured_at', 'id'])]
class SalesFactRevision
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

    #[ORM\Column(type: 'date_immutable')]
    private readonly \DateTimeImmutable $businessDate;

    #[ORM\Column(length: 32)]
    private readonly string $status;

    #[ORM\Column(length: 64)]
    private readonly string $marketplaceSku;

    #[ORM\Column(length: 64, nullable: true)]
    private readonly ?string $postingNumber;

    #[ORM\Column(length: 64, nullable: true)]
    private readonly ?string $orderNumber;

    #[ORM\Column]
    private readonly int $quantity;

    // Атрибуты доставки из analytics_data Ozon — только для показа,
    // по ним не фильтруют, поэтому индексов нет. warehouse_id — id склада
    // площадки (до ~1e15), а не ссылка на нашу сущность.
    #[ORM\Column(type: 'bigint', nullable: true)]
    private readonly ?int $warehouseId;

    // text, не varchar(N): длина названия — не наш инвариант, и слишком
    // длинное значение не должно ронять вставку всего пакета фактов.
    #[ORM\Column(type: 'text', nullable: true)]
    private readonly ?string $warehouseName;

    #[ORM\Column(type: 'text', nullable: true)]
    private readonly ?string $deliveryCity;

    // Кластеры Ozon из financial_data: откуда отгружено и куда доставляется.
    // Равенство — локальная продажа (отчёт «Локализация»). Название
    // кластера — строка площадки, справочника у нас нет.
    #[ORM\Column(type: 'text', nullable: true)]
    private readonly ?string $clusterFrom;

    #[ORM\Column(type: 'text', nullable: true)]
    private readonly ?string $clusterTo;

    // Момент заказа (in_process_at, UTC) — точка отсчёта скорости доставки.
    // Неизменен, как и выведенная из него business_date, поэтому в row_hash
    // не входит.
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private readonly ?\DateTimeImmutable $orderedAt;

    #[ORM\Column(type: 'money_minor_amount')]
    private readonly int $amountMinor;

    #[ORM\Column(type: 'money_minor_amount')]
    private readonly int $commissionAmountMinor;

    // options: ['fixed' => true] — ADR-004 требует именно char(3), не
    // varchar(3): без fixed Doctrine генерирует VARCHAR на любой длине.
    #[ORM\Column(length: 3, options: ['fixed' => true])]
    private readonly string $currency;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $rawDocumentId;

    #[ORM\Column(length: 64)]
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
        \DateTimeImmutable $businessDate,
        string $status,
        string $marketplaceSku,
        ?string $postingNumber,
        ?string $orderNumber,
        int $quantity,
        ?int $warehouseId,
        ?string $warehouseName,
        ?string $deliveryCity,
        ?string $clusterFrom,
        ?string $clusterTo,
        ?\DateTimeImmutable $orderedAt,
        int $amountMinor,
        int $commissionAmountMinor,
        string $currency,
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
        $this->businessDate = $businessDate;
        $this->status = $status;
        $this->marketplaceSku = $marketplaceSku;
        $this->postingNumber = $postingNumber;
        $this->orderNumber = $orderNumber;
        $this->quantity = $quantity;
        $this->warehouseId = $warehouseId;
        $this->warehouseName = $warehouseName;
        $this->deliveryCity = $deliveryCity;
        $this->clusterFrom = $clusterFrom;
        $this->clusterTo = $clusterTo;
        $this->orderedAt = $orderedAt;
        $this->amountMinor = $amountMinor;
        $this->commissionAmountMinor = $commissionAmountMinor;
        $this->currency = $currency;
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
