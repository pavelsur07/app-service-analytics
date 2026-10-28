<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

use App\SalesPlanning\Application\SalesPlanPerformanceView;
use OpenApi\Attributes as OA;

final readonly class SalesPlanPerformanceResponse
{
    /** @param list<PerformanceSkuResponse> $skus */
    public function __construct(
        public string $currentPlanId,
        public string $baselinePlanId,
        public string $metric,
        public string $currency,
        public string $accumulationFrom,
        public string $from,
        public string $to,
        #[OA\Property(enum: ['day', 'week', 'month', 'period'])] public string $groupBy,
        public string $calculatedAt,
        public string $methodology,
        public string $ordersCoverage,
        public string $returnsCoverage,
        #[OA\Property(maxItems: 50)] public array $skus,
        public ?string $nextCursor,
        public string $summaryScope,
    ) {
    }

    public static function of(SalesPlanPerformanceView $v): self
    {
        return new self(
            $v->currentPlanId,
            $v->baselinePlanId,
            $v->metric,
            $v->currency,
            $v->accumulationFrom,
            $v->from,
            $v->to,
            $v->groupBy,
            $v->calculatedAt->format(\DateTimeInterface::ATOM),
            $v->methodology,
            $v->ordersCoverage,
            $v->returnsCoverage,
            array_map(PerformanceSkuResponse::of(...), $v->skus),
            $v->nextCursor,
            $v->summaryScope,
        );
    }
}
