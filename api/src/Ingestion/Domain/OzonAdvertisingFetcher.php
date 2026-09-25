<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Рекламный API Ozon (ADR-026). Тела ответов — как есть, без разбора:
 * raw-слой хранит точные байты (ADR-006).
 *
 * Токен — отдельным вызовом, а не внутри каждого метода: он живёт
 * 30 минут, и сценарий из нескольких запросов берёт его один раз.
 */
interface OzonAdvertisingFetcher
{
    public function token(string $clientId, string $clientSecret): string;

    public function campaigns(string $token): string;

    public function campaignProducts(string $token, string $campaignId): string;

    /**
     * Расход кампаний за дни `[from, to]` включительно
     * (`GET /api/client/statistics/expense/json`), тело как есть.
     */
    public function expense(string $token, \DateTimeImmutable $from, \DateTimeImmutable $to): string;

    /**
     * Статистика кампаний за дни `[from, to]` включительно
     * (`GET /api/client/statistics/daily/json`), тело как есть.
     */
    public function daily(string $token, \DateTimeImmutable $from, \DateTimeImmutable $to): string;

    /**
     * Кампания × SKU за один день (`POST /api/client/statistics/products/sku`),
     * тело как есть. Площадка отдаёт только сегодня и вчера.
     *
     * @param list<string> $campaignIds не больше `OzonAdvertisingWindows::SKU_CAMPAIGNS_PER_REQUEST`
     */
    public function productsSku(string $token, array $campaignIds, \DateTimeImmutable $day): string;

    /**
     * Заказ асинхронного отчёта кампания × SKU × день за `[from, to]`
     * (`POST /api/client/statistics/json`), тело ответа как есть — в нём UUID.
     *
     * @param list<string> $campaignIds не больше `OzonAdvertisingWindows::SKU_CAMPAIGNS_PER_REQUEST`
     */
    public function orderSkuReport(string $token, array $campaignIds, \DateTimeImmutable $from, \DateTimeImmutable $to): string;

    /**
     * Состояние заказанного отчёта (`GET /api/client/statistics/{UUID}`).
     */
    public function reportState(string $token, string $uuid): string;

    /**
     * Готовый отчёт (`GET /api/client/statistics/report?UUID=…`), тело как есть.
     */
    public function report(string $token, string $uuid): string;
}
