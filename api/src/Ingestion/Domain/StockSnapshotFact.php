<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Снимочный факт (ADR-034, CLAUDE.md §2): остаток FBO одного SKU на одном
 * складе на дату снимка. Кластер — колонкой строки, как отдаёт площадка.
 *
 * Первичный ключ — (company_id, marketplace_account_id, snapshot_date,
 * source_row_id), source_row_id = sku|warehouse_id. Дата — отдельной
 * колонкой ключа, под партиционирование (ADR-034).
 *
 * Не пишется ORM: день заменяется целиком DoctrineStockSnapshotWriter
 * (CLAUDE.md §6, снимочный факт). Нулевой остаток площадка не отдаёт —
 * строки нет; ноль отличим от «неизвестно» только по отметке дня
 * (StockSnapshotRun). Класс существует для migrations:diff,
 * schema:validate и Builder тестов.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stock_snapshot_fact')]
#[ORM\Index(name: 'idx_stock_snapshot_fact_raw_document_id', columns: ['raw_document_id'])]
class StockSnapshotFact
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $marketplaceAccountId;

    #[ORM\Id]
    #[ORM\Column(type: 'date_immutable')]
    private readonly \DateTimeImmutable $snapshotDate;

    #[ORM\Id]
    #[ORM\Column(type: 'text')]
    private readonly string $sourceRowId;

    #[ORM\Column(length: 64)]
    private readonly string $marketplaceSku;

    /** Отрицательный у пунктов выдачи (ПВЗ_<n>) — так отдаёт площадка. */
    #[ORM\Column(type: 'bigint')]
    private readonly int $warehouseId;

    #[ORM\Column(type: 'text')]
    private readonly string $warehouseName;

    #[ORM\Column(type: 'bigint')]
    private readonly int $clusterId;

    /** Совпадает с sales_fact.cluster_to побайтово (разведка ADR-034). */
    #[ORM\Column(type: 'text')]
    private readonly string $clusterName;

    #[ORM\Column]
    private readonly int $available;

    #[ORM\Column]
    private readonly int $transit;

    #[ORM\Column]
    private readonly int $requested;

    #[ORM\Column]
    private readonly int $returnFromCustomer;

    #[ORM\Column]
    private readonly int $returnToSeller;

    #[ORM\Column]
    private readonly int $defect;

    #[ORM\Column]
    private readonly int $other;

    /**
     * Средние продажи в день по кластеру, как их считает Ozon, — справочно,
     * для сверки с кабинетом. Не деньги; строкой фиксированной точности,
     * чтобы не нести float в хранилище.
     */
    #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
    private readonly ?string $adsCluster;

    #[ORM\Column(nullable: true)]
    private readonly ?int $idcCluster;

    #[ORM\Column(length: 32, nullable: true)]
    private readonly ?string $turnoverGradeCluster;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $rawDocumentId;

    public function __construct(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        \DateTimeImmutable $snapshotDate,
        string $marketplaceSku,
        int $warehouseId,
        string $warehouseName,
        int $clusterId,
        string $clusterName,
        StockQuantities $quantities,
        ?string $adsCluster,
        ?int $idcCluster,
        ?string $turnoverGradeCluster,
        Uuid $rawDocumentId,
    ) {
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->snapshotDate = $snapshotDate->setTime(0, 0);
        $this->sourceRowId = $marketplaceSku.'|'.$warehouseId;
        $this->marketplaceSku = $marketplaceSku;
        $this->warehouseId = $warehouseId;
        $this->warehouseName = $warehouseName;
        $this->clusterId = $clusterId;
        $this->clusterName = $clusterName;
        $this->available = $quantities->available;
        $this->transit = $quantities->transit;
        $this->requested = $quantities->requested;
        $this->returnFromCustomer = $quantities->returnFromCustomer;
        $this->returnToSeller = $quantities->returnToSeller;
        $this->defect = $quantities->defect;
        $this->other = $quantities->other;
        $this->adsCluster = $adsCluster;
        $this->idcCluster = $idcCluster;
        $this->turnoverGradeCluster = $turnoverGradeCluster;
        $this->rawDocumentId = $rawDocumentId;
    }

    public function companyId(): Uuid
    {
        return $this->companyId;
    }

    public function marketplaceAccountId(): Uuid
    {
        return $this->marketplaceAccountId;
    }

    public function snapshotDate(): \DateTimeImmutable
    {
        return $this->snapshotDate;
    }

    public function sourceRowId(): string
    {
        return $this->sourceRowId;
    }

    public function marketplaceSku(): string
    {
        return $this->marketplaceSku;
    }

    public function warehouseId(): int
    {
        return $this->warehouseId;
    }

    public function warehouseName(): string
    {
        return $this->warehouseName;
    }

    public function clusterId(): int
    {
        return $this->clusterId;
    }

    public function clusterName(): string
    {
        return $this->clusterName;
    }

    public function quantities(): StockQuantities
    {
        return new StockQuantities(
            available: $this->available,
            transit: $this->transit,
            requested: $this->requested,
            returnFromCustomer: $this->returnFromCustomer,
            returnToSeller: $this->returnToSeller,
            defect: $this->defect,
            other: $this->other,
        );
    }

    public function adsCluster(): ?string
    {
        return $this->adsCluster;
    }

    public function idcCluster(): ?int
    {
        return $this->idcCluster;
    }

    public function turnoverGradeCluster(): ?string
    {
        return $this->turnoverGradeCluster;
    }

    public function rawDocumentId(): Uuid
    {
        return $this->rawDocumentId;
    }
}
