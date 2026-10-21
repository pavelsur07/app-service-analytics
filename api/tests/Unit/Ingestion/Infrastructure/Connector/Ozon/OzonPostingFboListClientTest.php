<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Infrastructure\Connector\Ozon;

use App\Ingestion\Infrastructure\Connector\Ozon\OzonPostingFboListClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OzonPostingFboListClientTest extends TestCase
{
    private const string FIXTURE = __DIR__.'/../../../../../Fixtures/Marketplace/ozon/posting-fbo-list-v3-2026-10-19-page-001.json';

    public function testFetchReturnsResponseBodyByteForByteUnparsed(): void
    {
        $fixtureBody = file_get_contents(self::FIXTURE);
        self::assertIsString($fixtureBody);

        $httpClient = new MockHttpClient(new MockResponse($fixtureBody, ['http_code' => 200]));
        $client = new OzonPostingFboListClient($httpClient);

        $body = $client->fetchPage(
            clientId: 'client-1',
            apiKey: 'key-1',
            since: new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            to: new \DateTimeImmutable('2026-07-02T00:00:00Z'),
            cursor: '',
        );

        // Не json_decode+сравнение массивов: raw-слой (ADR-006) хэширует
        // точные байты ответа, важна побайтовая идентичность.
        self::assertSame($fixtureBody, $body);
    }

    public function testFetchSendsClientIdAndApiKeyAsHeaders(): void
    {
        $capturedHeaders = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedHeaders): MockResponse {
            $capturedHeaders = $options['headers'];

            return new MockResponse('{"postings":[],"has_next":false,"cursor":""}', ['http_code' => 200]);
        });
        $client = new OzonPostingFboListClient($httpClient);

        $client->fetchPage(
            clientId: 'client-42',
            apiKey: 'secret-key',
            since: new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            to: new \DateTimeImmutable('2026-07-02T00:00:00Z'),
            cursor: '',
        );

        self::assertContains('Client-Id: client-42', $capturedHeaders);
        self::assertContains('Api-Key: secret-key', $capturedHeaders);
    }

    /**
     * Форма запроса v3 (ADR-042): курсор вместо offset, страница 100,
     * translit: false — иначе кластеры приходят латиницей.
     */
    public function testFetchPageSendsV3RequestWithCursor(): void
    {
        $captured = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? null];

            return new MockResponse('{"postings":[],"has_next":false,"cursor":""}', ['http_code' => 200]);
        });
        $client = new OzonPostingFboListClient($httpClient);

        $client->fetchPage(
            clientId: 'client-1',
            apiKey: 'key-1',
            since: new \DateTimeImmutable('2026-10-19T00:00:00+03:00'),
            to: new \DateTimeImmutable('2026-10-20T00:00:00+03:00'),
            cursor: 'cursor-page-2',
        );

        self::assertSame('POST', $captured['method']);
        self::assertStringEndsWith('/v3/posting/fbo/list', (string) $captured['url']);
        self::assertIsString($captured['body']);
        self::assertSame([
            'cursor' => 'cursor-page-2',
            'dir' => 'ASC',
            'filter' => ['since' => '2026-10-19T00:00:00+03:00', 'to' => '2026-10-20T00:00:00+03:00'],
            'limit' => 100,
            'translit' => false,
            'with' => ['analytics_data' => true, 'financial_data' => true],
        ], json_decode($captured['body'], true, flags: \JSON_THROW_ON_ERROR));
    }

    public function testFetchThrowsOnAuthorizationFailure(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('{"message":"Invalid Api-Key"}', ['http_code' => 401]));
        $client = new OzonPostingFboListClient($httpClient);

        $this->expectException(ClientException::class);

        $client->fetchPage(
            clientId: 'client-1',
            apiKey: 'wrong-key',
            since: new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            to: new \DateTimeImmutable('2026-07-02T00:00:00Z'),
            cursor: '',
        );
    }
}
