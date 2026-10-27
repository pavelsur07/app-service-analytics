<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

use App\SalesPlanning\Application\SalesPlanPerformanceSku;
use OpenApi\Attributes as OA;

final readonly class PerformanceSkuResponse
{
    /** @param list<PerformancePointResponse> $points */
    public function __construct(public string $marketplaceSku, #[OA\Property(maxItems: 184)] public array $points)
    {
    }

    public static function of(SalesPlanPerformanceSku $sku): self
    {
        return new self($sku->marketplaceSku, array_map(PerformancePointResponse::of(...), $sku->points));
    }
}
