<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Connector\OzonPerformance;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Единственная точка вызова рекламного API Ozon (ADR-026, п. 2).
 *
 * У рекламного ключа есть право менять кабинет: включать и выключать
 * кампании, ставить ставки. Три таких метода (`all_sku_promo/activate`,
 * `deactivate`, `set_bid`) — обычные GET, поэтому правило «только GET»
 * не защищает. Защищает список ниже: пара «метод путь» сверяется целиком,
 * от начала до конца строки, переменные сегменты — строгими шаблонами.
 * Всё, чего в списке нет, отклоняется исключением до отправки запроса.
 *
 * Путь передаётся без строки запроса, параметры — отдельно: иначе хвост
 * после `?` проходил бы мимо сверки.
 *
 * Тела ответов возвращаются как есть, без json_decode, как у клиентов
 * Seller API: raw-слой хранит и хэширует точные байты (ADR-006).
 * Исключения symfony/http-client на 4xx/5xx пробрасываются — что с ними
 * делать, решает вызывающий сценарий (ADR-007).
 *
 * Каждый полученный ответ 429 и 403 — `warning` в журнал (ADR-043 п. 5),
 * как у Seller API: обёрткой лимита Seller (ADR-028) этот клиент не покрыт.
 * Кабинет в записи — client_id рекламного ключа: у запросов после токена
 * его нет, поэтому клиент помнит, какому client_id выдан токен.
 */
final class OzonPerformanceHttp
{
    /**
     * Разрешённые пары: метод и шаблон пути целиком. Добавить путь сюда —
     * решение о том, что код вправе делать с кабинетом клиента: только
     * чтение, и только то, что названо в ADR-026.
     */
    private const array ALLOWED = [
        ['POST', '#\A/api/client/token\z#'],
        ['GET', '#\A/api/client/campaign\z#'],
        ['GET', '#\A/api/client/campaign/[0-9]{1,20}/v2/products\z#'],
        ['GET', '#\A/api/client/statistics/expense/json\z#'],
        ['GET', '#\A/api/client/statistics/daily/json\z#'],
        ['POST', '#\A/api/client/statistics/products/sku\z#'],
        ['POST', '#\A/api/client/statistics/json\z#'],
        ['GET', '#\A/api/client/statistics/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z#'],
        ['GET', '#\A/api/client/statistics/report\z#'],
        ['POST', '#\A/api/client/statistic/orders/generate/json\z#'],
    ];

    private const string TOKEN_PATH = '/api/client/token';

    /**
     * Сколько последних токенов помнить: воркер живёт долго и обходит
     * кабинеты по очереди — без потолка карта росла бы без конца.
     */
    private const int REMEMBERED_TOKENS = 64;

    /**
     * sha256 токена → client_id, которому он выдан. Только для записи
     * в журнал; сам токен ключом не хранится.
     *
     * @var array<string, string>
     */
    private array $tokenOwners = [];

    public function __construct(
        #[Autowire(service: 'ozon_performance.client')]
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Bearer-токен на 30 минут. Запрашивается на каждый запуск
     * и нигде не хранится (ADR-026, п. 1).
     */
    public function token(string $clientId, string $clientSecret): string
    {
        $body = $this->send('POST', self::TOKEN_PATH, [
            'json' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'client_credentials',
            ],
        ]);

        $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        $token = \is_array($decoded) ? ($decoded['access_token'] ?? null) : null;
        if (!\is_string($token) || '' === $token) {
            // Не отказ авторизации — площадка ответила 200 без токена.
            // Это наш вопрос к форме ответа, а не повод объявить ключ
            // сломанным.
            throw new \UnexpectedValueException('Ozon Performance token response has no access_token.');
        }

        $this->tokenOwners[hash('sha256', $token)] = $clientId;
        if (\count($this->tokenOwners) > self::REMEMBERED_TOKENS) {
            array_shift($this->tokenOwners);
        }

        return $token;
    }

    /**
     * @param array<string, string> $query
     */
    public function get(string $token, string $path, array $query = []): string
    {
        return $this->send('GET', $path, [
            'auth_bearer' => $token,
            'query' => $query,
        ]);
    }

    /**
     * @param array<string, mixed> $json
     */
    public function post(string $token, string $path, array $json): string
    {
        return $this->send('POST', $path, [
            'auth_bearer' => $token,
            'json' => $json,
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $path, array $options): string
    {
        if (!self::isAllowed($method, $path)) {
            // LogicException, а не отказ площадки: попытка вызвать
            // неразрешённый путь — дефект нашего кода, и он обязан дойти
            // до трекера, а не превратиться в «Ozon недоступен».
            throw new \LogicException(\sprintf('Ozon Performance: %s %s is not in the read-only allow-list (ADR-026).', $method, $path));
        }

        $response = $this->httpClient->request($method, $path, $options);
        $status = $response->getStatusCode();
        if (429 === $status || 403 === $status) {
            $this->logger->warning('Ozon Performance API answered {status}', [
                'api' => 'performance',
                'status' => $status,
                'method' => $method,
                'path' => $path,
                'client_id' => self::clientId($options, $this->tokenOwners),
            ]);
        }

        return $response->getContent();
    }

    /**
     * client_id кабинета: у запроса токена — из тела, у остальных — по
     * выданному токену. Неизвестен — пустая строка, не выдуманное значение.
     *
     * @param array<string, mixed>  $options
     * @param array<string, string> $tokenOwners
     */
    private static function clientId(array $options, array $tokenOwners): string
    {
        $json = $options['json'] ?? null;
        if (\is_array($json) && \is_string($json['client_id'] ?? null)) {
            return $json['client_id'];
        }
        $token = $options['auth_bearer'] ?? null;

        return \is_string($token) ? ($tokenOwners[hash('sha256', $token)] ?? '') : '';
    }

    public static function isAllowed(string $method, string $path): bool
    {
        foreach (self::ALLOWED as [$allowedMethod, $pattern]) {
            if ($allowedMethod === $method && 1 === preg_match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
