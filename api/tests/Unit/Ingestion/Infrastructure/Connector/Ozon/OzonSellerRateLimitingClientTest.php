<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Infrastructure\Connector\Ozon;

use App\Ingestion\Domain\OzonAuthorizationFailure;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonSellerRateLimited;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonSellerRateLimitingClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Лимит Seller API на нашей стороне (ADR-028): после 429 или
 * `Ratelimit-Remaining: 0` запрос того же Client-Id и метода в Ozon
 * не уходит, пока идёт пауза, — одинаковые запросы подряд Ozon
 * наказывает ограничением доступа.
 */
final class OzonSellerRateLimitingClientTest extends TestCase
{
    public function testRejectedRequestPausesTheSameClientAndMethod(): void
    {
        $calls = 0;
        $client = $this->client($calls, [new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['Retry-After' => '30']])]);

        $client->request('POST', '/v2/posting/fbo/list', $this->as('shop-1'));

        try {
            $client->request('POST', '/v2/posting/fbo/list', $this->as('shop-1'));
            self::fail('Запрос во время паузы ушёл в Ozon.');
        } catch (OzonSellerRateLimited $paused) {
            self::assertGreaterThanOrEqual(29, $paused->retryAfterSeconds);
            self::assertLessThanOrEqual(30, $paused->retryAfterSeconds);
        }
        self::assertSame(1, $calls);
    }

    public function testPauseDoesNotTouchOtherCabinetsOrMethods(): void
    {
        $calls = 0;
        $client = $this->client($calls, [
            new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['Retry-After' => '30']]),
            new MockResponse('{}'),
            new MockResponse('{}'),
        ]);

        $client->request('POST', '/v2/posting/fbo/list', $this->as('shop-1'));
        // Лимит — на Client-Id и метод: чужой кабинет и другой метод
        // того же кабинета идут как шли.
        $client->request('POST', '/v2/posting/fbo/list', $this->as('shop-2'));
        $client->request('POST', '/v1/finance/accrual/by-day', $this->as('shop-1'));

        self::assertSame(3, $calls);
    }

    public function testExhaustedRemainingIsWaitedOutWithoutBreakingThePage(): void
    {
        $calls = 0;
        $slept = [];
        $pauses = new ArrayAdapter();
        $client = $this->client($calls, [
            new MockResponse('{}', ['response_headers' => ['Ratelimit-Remaining' => '0']]),
            new MockResponse('{}'),
        ], $pauses, static function (int $seconds) use (&$slept, $pauses): void {
            $slept[] = $seconds;
            $pauses->clear();
        });

        $client->request('POST', '/v1/returns/list', $this->as('shop-1'));
        // Следующая страница той же выгрузки: секунду переждали, запрос ушёл —
        // выгрузка не обрывается и не начинается с первой страницы.
        $client->request('POST', '/v1/returns/list', $this->as('shop-1'));

        self::assertSame([1], $slept);
        self::assertSame(2, $calls);
    }

    public function testLongPauseIsNotWaitedInsideTheRequest(): void
    {
        $calls = 0;
        $slept = [];
        $client = $this->client($calls, [
            new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['Retry-After' => '30']]),
        ], null, static function (int $seconds) use (&$slept): void {
            $slept[] = $seconds;
        });

        $client->request('POST', '/v3/product/list', $this->as('shop-1'));

        try {
            $client->request('POST', '/v3/product/list', $this->as('shop-1'));
            self::fail('Запрос во время паузы ушёл в Ozon.');
        } catch (OzonSellerRateLimited) {
        }
        self::assertSame([], $slept);
    }

    public function testShortPauseDoesNotShortenALongOne(): void
    {
        $calls = 0;
        $client = $this->client($calls, [
            new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['Retry-After' => '600']]),
        ]);
        $client->request('POST', '/v2/posting/fbo/list', $this->as('shop-1'));

        // Пауза по Ratelimit-Remaining: 0 из соседнего воркера пришла позже,
        // но 10 минут, которые попросил Ozon, остаются.
        $reflection = new \ReflectionMethod($client, 'pause');
        $reflection->invoke($client, 'ozon_seller_pause.'.hash('sha256', 'shop-1|POST /v2/posting/fbo/list'), 1);

        try {
            $client->request('POST', '/v2/posting/fbo/list', $this->as('shop-1'));
            self::fail('Запрос во время паузы ушёл в Ozon.');
        } catch (OzonSellerRateLimited $paused) {
            self::assertGreaterThan(500, $paused->retryAfterSeconds);
        }
    }

    public function testOrdinaryResponseLeavesNoPause(): void
    {
        $calls = 0;
        $client = $this->client($calls, [
            new MockResponse('{}', ['response_headers' => ['Ratelimit-Remaining' => '12']]),
            new MockResponse('{}'),
        ]);

        $client->request('POST', '/v1/returns/list', $this->as('shop-1'));
        $client->request('POST', '/v1/returns/list', $this->as('shop-1'));

        self::assertSame(2, $calls);
    }

    public function testPauseReadsAsUnavailableNotAsARejectedKey(): void
    {
        $paused = new OzonSellerRateLimited(5, 'POST', '/v3/product/list');

        // Проба ключа при подключении относит транспортные сбои
        // к «площадка недоступна»; отказом авторизации пауза не считается.
        self::assertInstanceOf(TransportExceptionInterface::class, $paused);
        self::assertFalse(OzonAuthorizationFailure::isAuthorizationFailure($paused));
    }

    public function testRetryAfterIsReadSafely(): void
    {
        self::assertSame(30, OzonSellerRateLimitingClient::retryAfter('30'));
        self::assertSame(60, OzonSellerRateLimitingClient::retryAfter(null));
        self::assertSame(60, OzonSellerRateLimitingClient::retryAfter('скоро'));
        self::assertSame(3_600, OzonSellerRateLimitingClient::retryAfter('999999'));
        self::assertSame(1, OzonSellerRateLimitingClient::retryAfter('0'));

        $inAMinute = gmdate('D, d M Y H:i:s', time() + 60).' GMT';
        $seconds = OzonSellerRateLimitingClient::retryAfter($inAMinute);
        self::assertGreaterThanOrEqual(58, $seconds);
        self::assertLessThanOrEqual(60, $seconds);
    }

    /**
     * @param list<MockResponse>         $responses
     * @param (\Closure(int): void)|null $sleep
     */
    private function client(int &$calls, array $responses, ?ArrayAdapter $pauses = null, ?\Closure $sleep = null): OzonSellerRateLimitingClient
    {
        $mock = new MockHttpClient(static function () use (&$calls, &$responses): MockResponse {
            ++$calls;
            $response = array_shift($responses);
            self::assertInstanceOf(MockResponse::class, $response);

            return $response;
        }, 'https://api-seller.ozon.ru');

        return new OzonSellerRateLimitingClient(
            $mock,
            $pauses ?? new ArrayAdapter(),
            new LockFactory(new InMemoryStore()),
            $sleep ?? static function (int $seconds): void {
                self::fail("Короткая пауза неожиданно пережидается: {$seconds} с.");
            },
        );
    }

    /**
     * @return array{headers: array{Client-Id: string, Api-Key: string}}
     */
    private function as(string $clientId): array
    {
        return ['headers' => ['Client-Id' => $clientId, 'Api-Key' => 'key']];
    }
}
