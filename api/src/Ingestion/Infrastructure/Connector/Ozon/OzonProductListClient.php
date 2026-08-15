<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Connector\Ozon;

use App\Ingestion\Domain\OzonCatalogFetcher;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * /v3/product/list — каталог кабинета.
 *
 * Отдаёт sku, артикул продавца и product_id. Наименования здесь нет —
 * его приходится брать вторым запросом (OzonProductInfoListClient).
 * Раньше в этом комментарии стояло, что одного эндпоинта достаточно;
 * достаточно было, пока карточку не требовалось показывать человеку.
 *
 * visibility=ALL, а не только выставленное на продажу: товар, снятый
 * с витрины, остаётся своим — его карточка открывается, и оверлей
 * на ней обязан показать данные, а не промолчать как на чужой.
 *
 * Возвращает тело ответа как есть (без json_decode), как и клиент
 * отгрузок: разбор — отдельный шаг, не в клиенте.
 */
final readonly class OzonProductListClient implements OzonCatalogFetcher
{
    private const string ENDPOINT = '/v3/product/list';

    public function __construct(
        #[Autowire(service: 'ozon.client')]
        private HttpClientInterface $httpClient,
    ) {
    }

    public function fetchPage(string $clientId, string $apiKey, string $lastId, int $limit = 1000): string
    {
        $response = $this->httpClient->request('POST', self::ENDPOINT, [
            'headers' => [
                'Client-Id' => $clientId,
                'Api-Key' => $apiKey,
            ],
            'json' => [
                'filter' => [
                    'visibility' => 'ALL',
                ],
                'limit' => $limit,
                'last_id' => $lastId,
            ],
        ]);

        // getContent() бросает исключения symfony/http-client на 4xx/5xx —
        // состояние подключения (ADR-007) решает вызывающий сценарий,
        // не клиент. Тот же контракт, что у OzonPostingFboListClient.
        return $response->getContent();
    }
}
