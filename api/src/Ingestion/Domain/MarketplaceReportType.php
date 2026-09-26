<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Тип отчёта площадки — то, что лежит в marketplace_raw_document.report_type.
 *
 * Появился на третьем употреблении строки, не раньше: её знают обработчик
 * отгрузок, обработчик каталога и контроль свежести данных, и последний
 * обязан различать их по-настоящему — иначе исправная синхронизация
 * каталога маскировала бы вставшую синхронизацию продаж.
 */
final class MarketplaceReportType
{
    public const string OzonPostingFboList = 'ozon_posting_fbo_list';

    public const string OzonProductList = 'ozon_product_list';

    /**
     * Наименования карточек. Отдельный тип, а не часть каталога:
     * это отдельный ответ площадки, и raw-слой хранит ответы, а не
     * представление о них (ADR-006).
     */
    public const string OzonProductInfoList = 'ozon_product_info_list';

    public const string OzonAccrualByDay = 'ozon_accrual_by_day';

    public const string OzonReturnsList = 'ozon_returns_list';

    /**
     * Остатки FBO по складам с кластером (ADR-034), /v1/analytics/stocks.
     * Период документа — дата снимка: даты в запросе нет, и без неё
     * неизменный ответ следующего дня не дал бы своего документа.
     */
    public const string OzonAnalyticsStocks = 'ozon_analytics_stocks';

    /**
     * Реклама Ozon, Performance API (ADR-026 п. 3): список кампаний,
     * расход кампаний за день и статистика кампаний за день. Расход
     * и статистика — два типа,
     * а не один: это два ответа площадки. Контроль свежести сторожит
     * `OzonAdExpense`.
     */
    public const string OzonAdCampaigns = 'ozon_ad_campaigns';

    public const string OzonAdExpense = 'ozon_ad_expense';

    public const string OzonAdDaily = 'ozon_ad_daily';

    /**
     * Кампания × SKU за один день (`products/sku`) — только сегодня
     * и вчера; более ранние дни метод не отдаёт.
     */
    public const string OzonAdSkuDay = 'ozon_ad_sku_day';

    /**
     * Асинхронный отчёт кампания × SKU × день (`statistics/json`) за дни
     * раньше вчерашнего; `period` — начало периода отчёта.
     */
    public const string OzonAdSkuReport = 'ozon_ad_sku_report';

    /**
     * Асинхронный отчёт заказов «Оплаты за заказ» по организации;
     * `period` — начало периода отчёта. Форма строки неизвестна —
     * хранится как есть.
     */
    public const string OzonAdCpoOrders = 'ozon_ad_cpo_orders';

    private function __construct()
    {
    }
}
