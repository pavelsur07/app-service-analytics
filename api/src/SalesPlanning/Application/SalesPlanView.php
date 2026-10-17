<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\SalesPlanning\Infrastructure\Query\SalesPlanBucketRow;
use App\SalesPlanning\Infrastructure\Query\SalesPlanRow;
use App\SalesPlanning\Infrastructure\Query\SalesPlanSkuRow;

/**
 * План для экрана: шапка с версией и итогами, страница SKU и корзины
 * периода по SKU страницы.
 */
final readonly class SalesPlanView
{
    /**
     * @param list<SalesPlanSkuRow>                   $skus
     * @param array<string, list<SalesPlanBucketRow>> $buckets по SKU; пусто при группировке по всему периоду
     */
    public function __construct(
        public SalesPlanRow $plan,
        public array $skus,
        public array $buckets,
        public ?string $nextCursor,
    ) {
    }
}
