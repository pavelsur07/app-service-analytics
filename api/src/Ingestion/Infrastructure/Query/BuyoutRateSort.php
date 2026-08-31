<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/** Numeric aggregate used to order the complete SKU cohort. */
enum BuyoutRateSort: string
{
    case Ordered = 'ordered';
    case ActualBuyout = 'actual_buyout';

    public function column(): string
    {
        return match ($this) {
            self::Ordered => 'ordered_quantity',
            self::ActualBuyout => 'actual_buyout_rate_bps',
        };
    }

    public function valueOf(BuyoutRateRow $row): ?int
    {
        return match ($this) {
            self::Ordered => $row->orderedQuantity,
            self::ActualBuyout => $row->actualBuyoutRateBps,
        };
    }
}
