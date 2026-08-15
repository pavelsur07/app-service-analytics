<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Connector\Ozon;

use App\Ingestion\Domain\OzonProductInfoFetcher;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * /v3/product/info/list — наименования карточек.
 *
 * Второй запрос к площадке в одной синхронизации каталога, и другого
 * способа нет: /v3/product/list поля name не отдаёт вовсе — проверено
 * на снятой фикстуре, в ответе только идентификаторы, признаки остатков
 * и архива.
 *
 * Нарезки на пачки здесь нет: страница каталога — 1000 товаров, ровно
 * столько же принимает этот эндпоинт, и пара «страница → один запрос
 * имён» получается сама.
 *
 * Возвращает тело ответа как есть (без json_decode), как и соседние
 * клиенты: разбор — отдельный шаг.
 */
final readonly class OzonProductInfoListClient implements OzonProductInfoFetcher
{
    private const string ENDPOINT = '/v3/product/info/list';

    public function __construct(
        #[Autowire(service: 'ozon.client')]
        private HttpClientInterface $httpClient,
    ) {
    }

    public function fetchNames(string $clientId, string $apiKey, array $productIds): string
    {
        $response = $this->httpClient->request('POST', self::ENDPOINT, [
            'headers' => [
                'Client-Id' => $clientId,
                'Api-Key' => $apiKey,
            ],
            'json' => [
                'product_id' => $productIds,
            ],
        ]);

        // getContent() бросает исключения symfony/http-client на 4xx/5xx —
        // состояние подключения (ADR-007) решает вызывающий сценарий,
        // не клиент. Тот же контракт, что у остальных клиентов Ozon.
        return $response->getContent();
    }
}
