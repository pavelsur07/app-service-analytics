<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Connector\Ozon;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Запрос к Seller API не отправлен: у этого Client-Id и метода действует
 * пауза лимита после 429 или `Ratelimit-Remaining: 0` (ADR-028).
 *
 * Транспортное исключение HTTP-клиента, а не новая ветка иерархии:
 * для вызывающего это «площадка сейчас недоступна» — проба ключа
 * показывает клиенту «недоступен», а не «ключ отклонён»
 * (`OzonAuthorizationFailure` кода ответа у него не найдёт), а очередь
 * повторяет сообщение через `retryAfterSeconds` (`RateLimitAwareRetryStrategy`).
 */
final class OzonSellerRateLimited extends \RuntimeException implements TransportExceptionInterface
{
    public function __construct(
        public readonly int $retryAfterSeconds,
        string $method,
        string $path,
    ) {
        parent::__construct("Ozon Seller API {$method} {$path} is paused by rate limit for {$retryAfterSeconds} s.");
    }
}
