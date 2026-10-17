<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

final readonly class SalesPlanDetailResponse
{
    /**
     * @param list<SalesPlanSkuResponse> $skus
     */
    public function __construct(
        public SalesPlanResponse $plan,
        /** day | week | month | period */
        public string $groupBy,
        public array $skus,
        public ?string $nextCursor,
    ) {
    }
}
