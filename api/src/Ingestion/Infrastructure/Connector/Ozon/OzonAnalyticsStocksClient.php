<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Connector\Ozon;

use App\Ingestion\Domain\OzonStockFetcher;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * /v1/analytics/stocks — остатки FBO по складам с кластером (ADR-034).
 * Тело запроса {skus: [...]} снято с живого кабинета (разведка
 * 2026-09-26). Лимит запросов — общая обёртка ozon.client (ADR-028).
 *
 * Возвращает тело ответа как есть: разбор — отдельный шаг, как у
 * соседних клиентов.
 */
final readonly class OzonAnalyticsStocksClient implements OzonStockFetcher
{
    private const string ENDPOINT = '/v1/analytics/stocks';

    public function __construct(
        #[Autowire(service: 'ozon.client')]
        private HttpClientInterface $httpClient,
    ) {
    }

    public function fetchStocks(string $clientId, string $apiKey, array $skus): string
    {
        $response = $this->httpClient->request('POST', self::ENDPOINT, [
            'headers' => [
                'Client-Id' => $clientId,
                'Api-Key' => $apiKey,
            ],
            'json' => [
                'skus' => $skus,
            ],
        ]);

        // 4xx/5xx — исключения symfony/http-client; состояние подключения
        // (ADR-007) решает вызывающий сценарий, как у остальных клиентов.
        return $response->getContent();
    }
}
