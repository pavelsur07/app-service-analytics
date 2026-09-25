<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Coverage;

use App\Ingestion\Domain\MarketplaceReportType;

/**
 * Источник данных в отчёте о полноте — один эндпоинт Ozon, один raw-тип.
 *
 * Как выгрузка ложится на дни (`kind`):
 * - `day` — выгрузка за один день, `period` — этот день;
 * - `snapshot` — снимок, `period` — день снимка;
 * - `range` — выгрузка за диапазон, в raw записано только его начало
 *   (`period`). Конец выводится из правил загрузки: не дальше
 *   `rangeDays` от начала и не позже дня получения минус `rangeEndLagDays`
 *   (у SKU-отчёта вчера и сегодня отдаёт `products/sku`). Ручной добор
 *   с концом в прошлом может показать день покрытым, хотя не был — цена
 *   отсутствия конца диапазона в raw (решение владельца 2026-09-25).
 */
final readonly class DataCoverageSource
{
    public const string KindDay = 'day';

    public const string KindSnapshot = 'snapshot';

    public const string KindRange = 'range';

    private function __construct(
        public string $reportType,
        public string $section,
        public string $endpoint,
        public string $kind,
        public int $rangeDays = 0,
        public int $rangeEndLagDays = 0,
        public bool $advertising = false,
        /**
         * Грузится только «на сейчас», задним числом его никто не загружает
         * (снимки, `products/sku`): дни до первой выгрузки — «ещё рано»,
         * а не дыра, которая не закроется никогда.
         */
        public bool $forwardOnly = false,
    ) {
    }

    /**
     * Все источники в порядке строк отчёта.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            new self(MarketplaceReportType::OzonPostingFboList, 'Продажи', 'POST /v2/posting/fbo/list', self::KindDay),
            new self(MarketplaceReportType::OzonAccrualByDay, 'Расходы', 'POST /v1/finance/accrual/by-day', self::KindDay),
            new self(MarketplaceReportType::OzonReturnsList, 'Возвраты', 'POST /v1/returns/list', self::KindRange, rangeDays: 90),
            new self(MarketplaceReportType::OzonProductList, 'Каталог', 'POST /v3/product/list', self::KindSnapshot, forwardOnly: true),
            new self(MarketplaceReportType::OzonProductInfoList, 'Каталог', 'POST /v3/product/info/list', self::KindSnapshot, forwardOnly: true),
            new self(MarketplaceReportType::OzonAdCampaigns, 'Реклама', 'GET /api/client/campaign', self::KindSnapshot, advertising: true, forwardOnly: true),
            new self(MarketplaceReportType::OzonAdExpense, 'Реклама', 'GET /api/client/statistics/expense/json', self::KindRange, rangeDays: 30, advertising: true),
            new self(MarketplaceReportType::OzonAdDaily, 'Реклама', 'GET /api/client/statistics/daily/json', self::KindRange, rangeDays: 30, advertising: true),
            new self(MarketplaceReportType::OzonAdSkuDay, 'Реклама', 'POST /api/client/statistics/products/sku', self::KindDay, advertising: true, forwardOnly: true),
            new self(MarketplaceReportType::OzonAdSkuReport, 'Реклама', 'POST /api/client/statistics/json', self::KindRange, rangeDays: 30, rangeEndLagDays: 2, advertising: true),
            new self(MarketplaceReportType::OzonAdCpoOrders, 'Реклама', 'POST /api/client/statistic/orders/generate/json', self::KindRange, rangeDays: 30, advertising: true),
        ];
    }

    /**
     * Самый длинный диапазон: насколько раньше месяца искать выгрузки,
     * которые на него заходят.
     */
    public static function longestRangeDays(): int
    {
        return max(0, ...array_map(static fn (self $source): int => $source->rangeDays, self::all()));
    }
}
