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

    public const string OzonAccrualByDay = 'ozon_accrual_by_day';

    private function __construct()
    {
    }
}
