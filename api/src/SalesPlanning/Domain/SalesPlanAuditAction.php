<?php

declare(strict_types=1);

namespace App\SalesPlanning\Domain;

/**
 * Действия над планом продаж в аудит-журнале (ADR-040 п. 10).
 *
 * Строки, а не enum-колонка, — как у соседей: список событий растёт
 * вместе с продуктом. Живут здесь, а не в Identity: смысл действия
 * принадлежит модулю, который его совершает.
 */
final class SalesPlanAuditAction
{
    public const string Created = 'sales_plan.created';

    /**
     * Ввод SKU за период: запись на каждый SKU, «было» и «стало» —
     * точные значения всех дней SKU, а не итог периода.
     */
    public const string SkuSet = 'sales_plan.sku_set';

    private function __construct()
    {
    }
}
