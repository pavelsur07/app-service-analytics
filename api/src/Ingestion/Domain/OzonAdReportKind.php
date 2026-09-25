<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Виды асинхронных отчётов рекламы Ozon (ADR-026 п. 4). Цепочка
 * «заказать → проверить → скачать» у них общая, различаются запрос заказа
 * и raw-тип скачанного отчёта.
 */
final class OzonAdReportKind
{
    /** Кампания × SKU × день, `POST /api/client/statistics/json`. */
    public const string Sku = 'sku';

    /** Заказы «Оплаты за заказ» по организации, `POST /api/client/statistic/orders/generate/json`. */
    public const string CpoOrders = 'cpo_orders';

    private function __construct()
    {
    }

    /**
     * Сообщения, стоявшие в очереди до появления вида, — SKU-отчёты.
     */
    public static function of(?string $kind): string
    {
        return match ($kind) {
            null, self::Sku => self::Sku,
            self::CpoOrders => self::CpoOrders,
            default => throw new \InvalidArgumentException("Unknown Ozon advertising report kind '{$kind}'."),
        };
    }

    public static function rawType(string $kind): string
    {
        return self::CpoOrders === self::of($kind)
            ? MarketplaceReportType::OzonAdCpoOrders
            : MarketplaceReportType::OzonAdSkuReport;
    }
}
