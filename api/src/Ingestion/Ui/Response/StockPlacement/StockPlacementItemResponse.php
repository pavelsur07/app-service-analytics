<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\StockPlacement;

use OpenApi\Attributes as OA;

/**
 * Штуки — целые; спрос — штук в день × 1000 (без float в контракте);
 * покрытие — целые дни, null при нулевом спросе; рекомендация — null
 * при продажах ниже порога (definitions.minSales).
 */
#[OA\Schema(required: [
    'marketplaceSku', 'offerId', 'name', 'cluster', 'available', 'transit', 'requested', 'sold',
    'demandMilliPerDay', 'coverDays', 'recommended', 'status', 'abcClass', 'zeroDays', 'lostHours',
    'adsCluster', 'idcCluster',
])]
final readonly class StockPlacementItemResponse
{
    public function __construct(
        public string $marketplaceSku,
        public ?string $offerId,
        public ?string $name,
        public string $cluster,
        /** null — остаток неизвестен: SKU не было в запросе свежего полного снимка (ADR-034). */
        public ?int $available,
        public int $transit,
        public int $requested,
        public int $sold,
        public int $demandMilliPerDay,
        public ?int $coverDays,
        public ?int $recommended,
        #[OA\Property(enum: ['deficit', 'normal', 'surplus', 'insufficient_data', 'no_sales', 'unknown_stock'])]
        public string $status,
        #[OA\Property(enum: ['A', 'B', 'C'])]
        public string $abcClass,
        public int $zeroDays,
        public ?int $lostHours,
        /** Средние продажи в день по кластеру по методике Ozon — справочно, строкой numeric. */
        public ?string $adsCluster,
        public ?int $idcCluster,
    ) {
    }
}
