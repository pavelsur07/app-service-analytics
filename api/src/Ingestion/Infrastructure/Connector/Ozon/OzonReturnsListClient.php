<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Connector\Ozon;

use App\Ingestion\Domain\OzonReturnsFetcher;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Одна страница /v1/returns/list. Возвращает точные байты для raw-слоя;
 * cursor/pagination и состояние подключения решает Application handler.
 */
final readonly class OzonReturnsListClient implements OzonReturnsFetcher
{
    private const string ENDPOINT = '/v1/returns/list';

    public function __construct(
        #[Autowire(service: 'ozon.client')]
        private HttpClientInterface $httpClient,
    ) {
    }

    public function fetchPage(
        string $clientId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $lastId,
        int $limit = OzonReturnsFetcher::MAX_LIMIT,
    ): string {
        $response = $this->httpClient->request('POST', self::ENDPOINT, [
            'headers' => [
                'Client-Id' => $clientId,
                'Api-Key' => $apiKey,
            ],
            'json' => [
                'filter' => [
                    'visual_status_change_moment' => [
                        'time_from' => $from->format(\DateTimeInterface::ATOM),
                        'time_to' => $to->format(\DateTimeInterface::ATOM),
                    ],
                ],
                'limit' => $limit,
                'last_id' => $lastId,
            ],
        ]);

        return $response->getContent();
    }
}
