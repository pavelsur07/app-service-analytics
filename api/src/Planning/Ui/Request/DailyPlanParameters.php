<?php

declare(strict_types=1);

namespace App\Planning\Ui\Request;

use App\Planning\Domain\DailyPlan;

final class DailyPlanParameters
{
    public static function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Moscow'));
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('date_invalid');
        }

        return $date;
    }

    public static function sku(string $value): string
    {
        if (!DailyPlan::isMarketplaceSkuValid($value)) {
            throw new \InvalidArgumentException('marketplace_sku_invalid');
        }

        return $value;
    }

    private function __construct()
    {
    }
}
