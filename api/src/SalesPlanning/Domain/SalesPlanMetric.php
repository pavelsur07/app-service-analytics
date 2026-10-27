<?php

declare(strict_types=1);

namespace App\SalesPlanning\Domain;

/** Явная интерпретация цели; legacy планы сохраняют NULL (ADR-046). */
enum SalesPlanMetric: string
{
    case RetainedSales = 'retained_sales';
    case OrdersExcludingPreHandoverCancellations = 'orders_excluding_pre_handover_cancellations';
}
