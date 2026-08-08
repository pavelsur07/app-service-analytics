<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Connector\Ozon;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * /v2/posting/fbo/list с with.financial_data=true (ADR-009: выбран вместо
 * finance/transaction/list и отчёта о реализации — живёт годами, отдаёт
 * разбивку сумм по товару без аллокации).
 *
 * Возвращает тело ответа как есть (без json_decode): raw-слой (ADR-006)
 * хранит и хэширует точные байты ответа, повторное декодирование могло бы
 * изменить порядок ключей/пробелы и сломать идемпотентность по хэшу.
 * Разбор — отдельный шаг, не в клиенте.
 *
 * Общая абстракция коннектора — после второго коннектора, не до первого
 * (docs/structure.md), поэтому интерфейса здесь нет.
 */
final readonly class OzonPostingFboListClient
{
    private const string ENDPOINT = '/v2/posting/fbo/list';

    public function __construct(
        #[Autowire(service: 'http_client.ozon.client')]
        private HttpClientInterface $httpClient,
    ) {
    }

    public function fetch(
        string $clientId,
        string $apiKey,
        \DateTimeImmutable $since,
        \DateTimeImmutable $to,
        int $limit = 1000,
        int $offset = 0,
    ): string {
        $response = $this->httpClient->request('POST', self::ENDPOINT, [
            'headers' => [
                'Client-Id' => $clientId,
                'Api-Key' => $apiKey,
            ],
            'json' => [
                'dir' => 'ASC',
                'filter' => [
                    'since' => $since->format(\DateTimeInterface::ATOM),
                    'to' => $to->format(\DateTimeInterface::ATOM),
                ],
                'limit' => $limit,
                'offset' => $offset,
                'translit' => true,
                'with' => [
                    'analytics_data' => true,
                    'financial_data' => true,
                ],
            ],
        ]);

        // getContent() бросает исключения symfony/http-client на 4xx/5xx
        // (ClientExceptionInterface/ServerExceptionInterface) — свою
        // иерархию не заводим, состояние подключения (ADR-007: broken
        // на отказ авторизации) решает вызывающий Application-сценарий,
        // не этот клиент.
        return $response->getContent();
    }
}
