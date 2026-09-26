<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Domain\StockQuantities;
use App\Ingestion\Domain\StockSnapshotFact;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-005: валидные умолчания (первая строка живой фикстуры
 * analytics-stocks-2026-09-26.json), неизменяем. persistWith нет:
 * снимочный факт пишется только заменой дня (DoctrineStockSnapshotWriter),
 * и тест готовит день тем же путём.
 */
final class StockSnapshotFactBuilder
{
    private Uuid $companyId;
    private Uuid $marketplaceAccountId;
    private \DateTimeImmutable $snapshotDate;
    private string $marketplaceSku = '220279573';
    private int $warehouseId = 1020000115166000;
    private string $warehouseName = 'ЖУКОВСКИЙ_РФЦ';
    private int $clusterId = 154;
    private string $clusterName = 'Москва, МО и Дальние регионы';
    private int $available = 1;
    private int $transit = 0;
    private int $requested = 0;
    private Uuid $rawDocumentId;

    private function __construct()
    {
        $this->companyId = Uuid::v7();
        $this->marketplaceAccountId = Uuid::v7();
        $this->snapshotDate = new \DateTimeImmutable('2026-09-26');
        $this->rawDocumentId = Uuid::v7();
    }

    public static function aStockSnapshotFact(): self
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

    public function withSnapshotDate(\DateTimeImmutable $snapshotDate): self
    {
        $clone = clone $this;
        $clone->snapshotDate = $snapshotDate;

        return $clone;
    }

    public function withSku(string $marketplaceSku): self
    {
        $clone = clone $this;
        $clone->marketplaceSku = $marketplaceSku;

        return $clone;
    }

    public function withWarehouse(int $warehouseId, string $warehouseName): self
    {
        $clone = clone $this;
        $clone->warehouseId = $warehouseId;
        $clone->warehouseName = $warehouseName;

        return $clone;
    }

    public function withCluster(int $clusterId, string $clusterName): self
    {
        $clone = clone $this;
        $clone->clusterId = $clusterId;
        $clone->clusterName = $clusterName;

        return $clone;
    }

    public function withAvailable(int $available): self
    {
        $clone = clone $this;
        $clone->available = $available;

        return $clone;
    }

    public function withInbound(int $transit, int $requested): self
    {
        $clone = clone $this;
        $clone->transit = $transit;
        $clone->requested = $requested;

        return $clone;
    }

    public function withRawDocumentId(Uuid $rawDocumentId): self
    {
        $clone = clone $this;
        $clone->rawDocumentId = $rawDocumentId;

        return $clone;
    }

    public function build(): StockSnapshotFact
    {
        return new StockSnapshotFact(
            companyId: $this->companyId,
            marketplaceAccountId: $this->marketplaceAccountId,
            snapshotDate: $this->snapshotDate,
            marketplaceSku: $this->marketplaceSku,
            warehouseId: $this->warehouseId,
            warehouseName: $this->warehouseName,
            clusterId: $this->clusterId,
            clusterName: $this->clusterName,
            quantities: new StockQuantities(
                available: $this->available,
                transit: $this->transit,
                requested: $this->requested,
                returnFromCustomer: 0,
                returnToSeller: 0,
                defect: 0,
                other: 0,
            ),
            adsCluster: null,
            idcCluster: null,
            turnoverGradeCluster: null,
            rawDocumentId: $this->rawDocumentId,
        );
    }
}
