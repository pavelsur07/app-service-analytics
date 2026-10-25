<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Infrastructure\Connector\OzonPerformance;

use App\Ingestion\Infrastructure\Connector\OzonPerformance\OzonPerformanceHttp;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Рекламный ключ умеет менять кабинет клиента (ADR-026, п. 2), и три
 * таких метода — обычные GET. Этот тест — единственное, что держит
 * запрет записи: без него правка списка разрешённых путей прошла бы
 * незамеченной до первого изменённого кабинета.
 */
final class OzonPerformanceHttpTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function writeMethods(): iterable
    {
        yield 'CPO all SKU activate (GET!)' => ['GET', '/api/client/campaign/all_sku_promo/activate'];
        yield 'CPO all SKU deactivate (GET!)' => ['GET', '/api/client/campaign/all_sku_promo/deactivate'];
        yield 'CPO all SKU set bid (GET!)' => ['GET', '/api/client/campaign/all_sku_promo/set_bid'];
        yield 'activate campaign' => ['POST', '/api/client/campaign/123/activate'];
        yield 'deactivate campaign' => ['POST', '/api/client/campaign/123/deactivate'];
        yield 'patch campaign' => ['PATCH', '/api/client/campaign/123'];
        yield 'add products' => ['POST', '/api/client/campaign/123/products'];
        yield 'set bids' => ['PUT', '/api/client/campaign/123/products'];
        yield 'delete products' => ['POST', '/api/client/campaign/123/products/delete'];
        yield 'create CPC campaign' => ['POST', '/api/client/campaign/cpc/v2/product'];
        yield 'CPO enable product' => ['POST', '/api/client/search_promo/product/enable'];
        yield 'CPO set bids' => ['POST', '/api/client/campaign/search_promo/v2/bids/set'];
        yield 'products of campaign by POST' => ['POST', '/api/client/campaign/123/v2/products'];
        yield 'report path with a smuggled tail' => ['GET', "/api/client/statistics/0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b\n/api/client/campaign/all_sku_promo/activate"];
        yield 'query string in the path' => ['GET', '/api/client/campaign?x=/all_sku_promo/activate'];
        yield 'campaign id that is not a number' => ['GET', '/api/client/campaign/all_sku_promo/v2/products'];
    }

    #[DataProvider('writeMethods')]
    public function testWriteMethodsAreRefusedBeforeAnyRequest(string $method, string $path): void
    {
        self::assertFalse(OzonPerformanceHttp::isAllowed($method, $path));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function readMethods(): iterable
    {
        yield 'token' => ['POST', '/api/client/token'];
        yield 'campaigns' => ['GET', '/api/client/campaign'];
        yield 'campaign products' => ['GET', '/api/client/campaign/37583494/v2/products'];
        yield 'expense' => ['GET', '/api/client/statistics/expense/json'];
        yield 'daily' => ['GET', '/api/client/statistics/daily/json'];
        yield 'products by SKU' => ['POST', '/api/client/statistics/products/sku'];
        yield 'order a statistics report' => ['POST', '/api/client/statistics/json'];
        yield 'report status' => ['GET', '/api/client/statistics/8137f2e4-d9b4-4d71-881b-f8a11551994e'];
        yield 'download report' => ['GET', '/api/client/statistics/report'];
        yield 'order CPO orders report' => ['POST', '/api/client/statistic/orders/generate/json'];
    }

    #[DataProvider('readMethods')]
    public function testReadMethodsNamedInTheAdrAreAllowed(string $method, string $path): void
    {
        self::assertTrue(OzonPerformanceHttp::isAllowed($method, $path));
    }

    public function testRefusedPathNeverReachesTheNetwork(): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{}');
        });
        $http = new OzonPerformanceHttp($client, new Logger('test'));

        try {
            $http->get('token', '/api/client/campaign/all_sku_promo/activate');
            self::fail('A write method must be refused.');
        } catch (\LogicException) {
        }

        self::assertSame(0, $requests);
    }

    public function testTokenIsTakenFromTheResponse(): void
    {
        $http = new OzonPerformanceHttp(new MockHttpClient(
            new MockResponse('{"access_token":"jwt-token","expires_in":1800,"token_type":"Bearer"}'),
        ), new Logger('test'));

        self::assertSame('jwt-token', $http->token('client@advertising.performance.ozon.ru', 'secret'));
    }

    /**
     * ADR-043 п. 5: каждый полученный 429 и 403 — warning с API, методом
     * и кабинетом (client_id, которому выдан токен); ни токена, ни секрета
     * в записи. Ошибка ответа по-прежнему уходит вызывающему.
     */
    public function testEveryReceived429And403IsLoggedWithCabinetAndWithoutSecrets(): void
    {
        $logs = new TestHandler();
        $http = new OzonPerformanceHttp(new MockHttpClient([
            new MockResponse('{"access_token":"jwt-token-a","expires_in":1800,"token_type":"Bearer"}'),
            new MockResponse('{"error":"too many"}', ['http_code' => 429]),
            new MockResponse('{"error":"forbidden"}', ['http_code' => 403]),
            new MockResponse('[]'),
        ]), new Logger('test', [$logs]));

        $token = $http->token('perf-a@advertising.performance.ozon.ru', 'perf-secret-a');
        foreach (['/api/client/campaign', '/api/client/statistics/daily/json'] as $path) {
            try {
                $http->get($token, $path);
                self::fail('Ответ 4xx должен дойти до вызывающего.');
            } catch (\Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface) {
            }
        }
        $http->get($token, '/api/client/campaign');

        $records = $logs->getRecords();
        self::assertCount(2, $records);
        self::assertSame(['api' => 'performance', 'status' => 429, 'method' => 'GET', 'path' => '/api/client/campaign', 'client_id' => 'perf-a@advertising.performance.ozon.ru'], $records[0]->context);
        self::assertSame(403, $records[1]->context['status']);
        foreach ($records as $record) {
            self::assertSame('WARNING', $record->level->getName());
            $line = $record->message.json_encode($record->context);
            self::assertStringNotContainsString('jwt-token-a', $line);
            self::assertStringNotContainsString('perf-secret-a', $line);
        }
    }

    public function testRejectedTokenRequestNamesTheCabinetFromItsBody(): void
    {
        $logs = new TestHandler();
        $http = new OzonPerformanceHttp(new MockHttpClient(
            new MockResponse('{"error":"forbidden"}', ['http_code' => 403]),
        ), new Logger('test', [$logs]));

        try {
            $http->token('perf-b@advertising.performance.ozon.ru', 'perf-secret-b');
        } catch (\Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface) {
        }

        self::assertSame('perf-b@advertising.performance.ozon.ru', $logs->getRecords()[0]->context['client_id']);
        self::assertStringNotContainsString('perf-secret-b', (string) json_encode($logs->getRecords()[0]->context));
    }
}
