<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Connector\Ozon;

use App\Ingestion\Domain\OzonExpensesFetcher;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * /v1/finance/accrual/by-day — источник расходов (ADR-012).
 *
 * Выбран вместо /v3/finance/transaction/list, который отвечает, но
 * объявлен отключённым с 6 июля 2026, и вместо /v1/finance/accrual/postings,
 * который принимает список отправлений и потому не отдаёт расходы
 * без привязки к ним — рекламу и хранение.
 *
 * Возвращает тело ответа как есть (без json_decode), как и остальные
 * клиенты: raw-слой хранит и хэширует точные байты (ADR-006).
 */
final readonly class OzonAccrualByDayClient implements OzonExpensesFetcher
{
    private const string ENDPOINT = '/v1/finance/accrual/by-day';

    public function __construct(
        #[Autowire(service: 'ozon.client')]
        private HttpClientInterface $httpClient,
    ) {
    }

    public function fetchDay(string $clientId, string $apiKey, \DateTimeImmutable $day, string $lastId): string
    {
        $response = $this->httpClient->request('POST', self::ENDPOINT, [
            'headers' => [
                'Client-Id' => $clientId,
                'Api-Key' => $apiKey,
            ],
            'json' => [
                // Ровно десять символов — площадка проверяет длину строки,
                // а не разбирает дату (ADR-012).
                'date' => $day->format('Y-m-d'),
                'last_id' => $lastId,
            ],
        ]);

        // getContent() бросает исключения symfony/http-client на 4xx/5xx —
        // состояние подключения (ADR-007) решает вызывающий сценарий.
        return $response->getContent();
    }
}
