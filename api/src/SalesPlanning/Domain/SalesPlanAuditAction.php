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
    public const string CalendarCreated = 'sales_plan.calendar_created';

    public const string CalendarChanged = 'sales_plan.calendar_changed';

    public const string CalendarRemoved = 'sales_plan.calendar_removed';

    public const string Cancelled = 'sales_plan.cancelled';

    public const string Discarded = 'sales_plan.discarded';

    public const string MetricSelected = 'sales_plan.metric_selected';

    public const string RevisionCreated = 'sales_plan.revision_created';

    public const string Superseded = 'sales_plan.superseded';

    public const string Approved = 'sales_plan.approved';

    public const string Created = 'sales_plan.created';

    /**
     * Ввод SKU за период: запись на каждый SKU, «было» и «стало» —
     * точные значения всех дней SKU, а не итог периода.
     */
    public const string SkuSet = 'sales_plan.sku_set';

    /** Правка одного дня: «было/стало» — значения этого дня. */
    public const string DayChanged = 'sales_plan.day_changed';

    /** SKU убран из черновика: «было» — все его дни, «стало» — null. */
    public const string SkuRemoved = 'sales_plan.sku_removed';

    private function __construct()
    {
    }
}
