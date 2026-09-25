<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Connector\Ozon;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Лимит запросов к Seller API на нашей стороне (ADR-028) — одна обёртка
 * над `ozon.client` для всех коннекторов Seller API.
 *
 * Ozon сам сообщает, когда остановиться: 429 с `Retry-After` и заголовок
 * `Ratelimit-Remaining` в ответах. Цифр лимитов по методам мы не знаем,
 * поэтому подстраиваемся под ответ, а не под выдуманный потолок.
 *
 * - 429 — пауза для пары «Client-Id + метод» на `Retry-After` секунд
 *   (нет заголовка — 60).
 * - `Ratelimit-Remaining: 0` — пауза на секунду, не дожидаясь 429.
 * - Перед запросом пауза проверяется: пока она идёт, запрос в Ozon
 *   не уходит — `OzonSellerRateLimited`. Иначе после первого 429 остальные
 *   сообщения того же кабинета долбили бы метод одинаковыми запросами,
 *   а за это Ozon ограничивает доступ к Seller API без предупреждения.
 *
 * Пауза лежит в Redis (`cache.ozon_seller_limit`): её видят оба воркера
 * загрузки и сценарий подключения кабинета.
 */
#[AsDecorator(decorates: 'ozon.client')]
final class OzonSellerRateLimitingClient implements HttpClientInterface
{
    use DecoratorTrait;

    private const int DEFAULT_RETRY_AFTER_SECONDS = 60;

    private const int MAX_RETRY_AFTER_SECONDS = 3_600;

    public function __construct(
        HttpClientInterface $client,
        #[Autowire(service: 'cache.ozon_seller_limit')]
        private readonly CacheItemPoolInterface $pauses,
    ) {
        $this->client = $client;
    }

    /**
     * @param array<mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $path = (string) (parse_url($url, \PHP_URL_PATH) ?? $url);
        $key = self::key(self::clientId($options), $method, $path);

        $remaining = $this->pauseRemaining($key);
        if (null !== $remaining) {
            throw new OzonSellerRateLimited($remaining, $method, $path);
        }

        $response = $this->client->request($method, $url, $options);

        // Ответ ленивый: код ждём здесь — вызывающий всё равно сразу читает
        // тело, а решать о паузе нужно до следующего запроса.
        $headers = $response->getHeaders(false);
        if (429 === $response->getStatusCode()) {
            $this->pause($key, self::retryAfter($headers['retry-after'][0] ?? null));
        } elseif ('0' === ($headers['ratelimit-remaining'][0] ?? null)) {
            $this->pause($key, 1);
        }

        return $response;
    }

    /**
     * Секунды из `Retry-After`: число или HTTP-дата. Нет или не разобрать —
     * минута; сверху — час, чтобы испорченный заголовок не остановил
     * кабинет на сутки.
     */
    public static function retryAfter(?string $value): int
    {
        if (null === $value || '' === trim($value)) {
            return self::DEFAULT_RETRY_AFTER_SECONDS;
        }

        $value = trim($value);
        if (ctype_digit($value)) {
            $seconds = (int) $value;
        } else {
            $at = strtotime($value);
            $seconds = false === $at ? self::DEFAULT_RETRY_AFTER_SECONDS : $at - time();
        }

        return max(1, min($seconds, self::MAX_RETRY_AFTER_SECONDS));
    }

    private function pauseRemaining(string $key): ?int
    {
        $item = $this->pauses->getItem($key);
        if (!$item->isHit()) {
            return null;
        }

        $until = $item->get();
        $left = \is_int($until) || \is_float($until) ? (int) ceil($until - microtime(true)) : 0;

        return $left > 0 ? $left : null;
    }

    /**
     * Более длинная пауза не укорачивается более короткой.
     */
    private function pause(string $key, int $seconds): void
    {
        $until = microtime(true) + $seconds;
        $item = $this->pauses->getItem($key);
        $current = $item->isHit() ? $item->get() : null;
        if ((\is_int($current) || \is_float($current)) && $current >= $until) {
            return;
        }

        $this->pauses->save($item->set($until)->expiresAfter($seconds));
    }

    /**
     * @param array<mixed> $options
     */
    private static function clientId(array $options): string
    {
        $headers = $options['headers'] ?? [];
        if (\is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (\is_string($name) && 'client-id' === strtolower($name) && \is_scalar($value)) {
                    return (string) $value;
                }
            }
        }

        return '';
    }

    /**
     * Client-Id — не секрет, но в ключ кэша он идёт хэшем: ключ PSR-6
     * не принимает часть символов, а хэш одинаково короткий у любого значения.
     */
    private static function key(string $clientId, string $method, string $path): string
    {
        return 'ozon_seller_pause.'.hash('sha256', $clientId.'|'.strtoupper($method).' '.$path);
    }
}
