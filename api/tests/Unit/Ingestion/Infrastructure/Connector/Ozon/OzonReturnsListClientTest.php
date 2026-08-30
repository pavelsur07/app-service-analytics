<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Infrastructure\Connector\Ozon;

use App\Ingestion\Infrastructure\Connector\Ozon\OzonReturnsListClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OzonReturnsListClientTest extends TestCase
{
    public function testFetchPageSendsExactHeadersAndFilterAndReturnsRawBytes(): void
    {
        $capturedMethod = null;
        $capturedUrl = null;
        $capturedOptions = [];
        $raw = "{\n  \"returns\": [], \"has_next\": false\n}\n";
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedMethod, &$capturedUrl, &$capturedOptions, $raw): MockResponse {
            $capturedMethod = $method;
            $capturedUrl = $url;
            $capturedOptions = $options;

            return new MockResponse($raw, ['http_code' => 200]);
        }, 'https://api-seller.ozon.ru');

        $body = (new OzonReturnsListClient($httpClient))->fetchPage(
            clientId: 'seller-42',
            apiKey: 'secret-key',
            from: new \DateTimeImmutable('2026-08-01T00:00:00Z'),
            to: new \DateTimeImmutable('2026-08-30T23:59:59Z'),
            lastId: 900006,
        );

        self::assertSame($raw, $body);
        self::assertSame('POST', $capturedMethod);
        self::assertSame('https://api-seller.ozon.ru/v1/returns/list', $capturedUrl);
        self::assertContains('Client-Id: seller-42', $capturedOptions['headers']);
        self::assertContains('Api-Key: secret-key', $capturedOptions['headers']);
        $requestBody = $capturedOptions['body'] ?? null;
        self::assertIsString($requestBody);
        self::assertSame([
            'filter' => [
                'visual_status_change_moment' => [
                    'time_from' => '2026-08-01T00:00:00+00:00',
                    'time_to' => '2026-08-30T23:59:59+00:00',
                ],
            ],
            'limit' => 500,
            'last_id' => 900006,
        ], json_decode($requestBody, true, flags: \JSON_THROW_ON_ERROR));
    }

    /** @param class-string<\Throwable> $expectedException */
    #[DataProvider('httpFailures')]
    public function testFetchPagePropagatesHttpFailures(int $status, string $expectedException): void
    {
        $httpClient = new MockHttpClient(new MockResponse('{"message":"request failed"}', ['http_code' => $status]));
        $client = new OzonReturnsListClient($httpClient);

        $this->expectException($expectedException);
        $client->fetchPage(
            'seller',
            'key',
            new \DateTimeImmutable('2026-08-01T00:00:00Z'),
            new \DateTimeImmutable('2026-08-02T00:00:00Z'),
            0,
        );
    }

    /**
     * @return iterable<string, array{int, class-string<\Throwable>}>
     */
    public static function httpFailures(): iterable
    {
        yield 'authorization' => [401, ClientException::class];
        yield 'other client error' => [422, ClientException::class];
        yield 'server error' => [503, ServerException::class];
    }
}
