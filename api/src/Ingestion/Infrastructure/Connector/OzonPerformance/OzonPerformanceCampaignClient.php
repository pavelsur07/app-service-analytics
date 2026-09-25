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
