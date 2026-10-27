<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

use App\SalesPlanning\Infrastructure\Query\SalesPlanRow;

/**
 * Шапка плана с итогами. Деньги — минорные единицы и валюта (ADR-004);
 * `version` — обязательна в запросе на изменение (ADR-008).
 */
final readonly class SalesPlanResponse
{
    public function __construct(
        public string $id,
        public string $marketplaceAccountId,
        public string $name,
        public string $dateFrom,
        public string $dateTo,
        public string $currency,
        /** draft | approved | superseded | discarded | cancelled */
        public string $status,
        public string $lineageId,
        public int $versionNumber,
        public int $version,
        public string $createdAt,
        public int $skuCount,
        public int $totalQuantity,
        public int $revenueMinor,
        public ?\App\SalesPlanning\Domain\SalesPlanMetric $metric,
    ) {
    }

    public static function fromRow(SalesPlanRow $row): self
    {
        return new self(
            id: $row->id,
            marketplaceAccountId: $row->marketplaceAccountId,
            name: $row->name,
            dateFrom: $row->dateFrom,
            dateTo: $row->dateTo,
            currency: $row->currency,
            status: $row->status,
            lineageId: $row->lineageId,
            versionNumber: $row->versionNumber,
            version: $row->version,
            createdAt: $row->createdAt,
            skuCount: $row->skuCount,
            totalQuantity: $row->totalQuantity,
            revenueMinor: $row->revenueMinor,
            metric: null === $row->metric ? null : \App\SalesPlanning\Domain\SalesPlanMetric::from($row->metric),
        );
    }
}
