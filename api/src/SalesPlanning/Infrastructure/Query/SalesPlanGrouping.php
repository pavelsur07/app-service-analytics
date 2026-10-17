<?php

declare(strict_types=1);

namespace App\SalesPlanning\Infrastructure\Query;

/**
 * Группировка дней плана при чтении (ADR-040 п. 3): хранится всегда
 * день, неделя и месяц — сумма дней. `period` — только итог SKU,
 * без корзин.
 */
enum SalesPlanGrouping: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Period = 'period';

    /**
     * Выражение корзины. ISO-неделя — с понедельника (`date_trunc('week')`),
     * месяц — календарный. Границы периода плана корзину обрезают сами:
     * строки плотные, и MIN/MAX дат корзины — уже её обрезанные края.
     */
    public function bucketExpression(): ?string
    {
        return match ($this) {
            self::Day => 'plan_date',
            self::Week => "date_trunc('week', plan_date)",
            self::Month => "date_trunc('month', plan_date)",
            self::Period => null,
        };
    }
}
