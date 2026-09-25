<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Connector\OzonPerformance;

use App\Ingestion\Domain\OzonAdvertisingFetcher;

/**
 * Кампании рекламного кабинета (ADR-026). Все вызовы — через
 * OzonPerformanceHttp и его список разрешённых путей.
 */
final readonly class OzonPerformanceCampaignClient implements OzonAdvertisingFetcher
{
    public function __construct(
        private OzonPerformanceHttp $http,
    ) {
    }

    public function token(string $clientId, string $clientSecret): string
    {
        return $this->http->token($clientId, $clientSecret);
    }

    public function campaigns(string $token): string
    {
        return $this->http->get($token, '/api/client/campaign');
    }

    public function campaignProducts(string $token, string $campaignId): string
    {
        return $this->http->get($token, "/api/client/campaign/{$campaignId}/v2/products");
    }

    public function expense(string $token, \DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        return $this->http->get($token, '/api/client/statistics/expense/json', self::period($from, $to));
    }

    public function daily(string $token, \DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        return $this->http->get($token, '/api/client/statistics/daily/json', self::period($from, $to));
    }

    public function productsSku(string $token, array $campaignIds, \DateTimeImmutable $day): string
    {
        // Форма запроса — ровно та, что сняла разведка: поле `campaignIds`,
        // один день (`dateFrom = dateTo`).
        return $this->http->post($token, '/api/client/statistics/products/sku', [
            'campaignIds' => $campaignIds,
            'dateFrom' => $day->format('Y-m-d'),
            'dateTo' => $day->format('Y-m-d'),
        ]);
    }

    public function orderSkuReport(string $token, array $campaignIds, \DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        // Форма — та, что сняла разведка: `campaigns`, дни и `groupBy = DATE`.
        return $this->http->post($token, '/api/client/statistics/json', [
            'campaigns' => $campaignIds,
            'dateFrom' => $from->format('Y-m-d'),
            'dateTo' => $to->format('Y-m-d'),
            'groupBy' => 'DATE',
        ]);
    }

    public function orderCpoOrdersReport(string $token, \DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        // Форма — та, что сняла разведка: границы днями в полночь UTC,
        // площадка переводит их в московские сутки, `to` включительно.
        return $this->http->post($token, '/api/client/statistic/orders/generate/json', [
            'from' => $from->format('Y-m-d').'T00:00:00Z',
            'to' => $to->format('Y-m-d').'T00:00:00Z',
        ]);
    }

    public function reportState(string $token, string $uuid): string
    {
        return $this->http->get($token, "/api/client/statistics/{$uuid}");
    }

    public function report(string $token, string $uuid): string
    {
        return $this->http->get($token, '/api/client/statistics/report', ['UUID' => $uuid]);
    }

    /**
     * Даты — днями `Y-m-d`, как их снимал `bin/ozon-performance-fixture.sh`:
     * площадка считает их днями по Москве (ADR-026).
     *
     * @return array{dateFrom: string, dateTo: string}
     */
    private static function period(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return ['dateFrom' => $from->format('Y-m-d'), 'dateTo' => $to->format('Y-m-d')];
    }
}
