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
    private const string FIXTURE = __DIR__.'/../../../../../Fixtures/Marketplace/ozon/posting-fbo-list-2026-07-01.json';

    public function testFetchReturnsResponseBodyByteForByteUnparsed(): void
    {
        $fixtureBody = file_get_contents(self::FIXTURE);
        self::assertIsString($fixtureBody);

        $httpClient = new MockHttpClient(new MockResponse($fixtureBody, ['http_code' => 200]));
        $client = new OzonPostingFboListClient($httpClient);

        $body = $client->fetch(
            clientId: 'client-1',
            apiKey: 'key-1',
            since: new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            to: new \DateTimeImmutable('2026-07-02T00:00:00Z'),
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

            return new MockResponse('{"result":[]}', ['http_code' => 200]);
        });
        $client = new OzonPostingFboListClient($httpClient);

        $client->fetch(
            clientId: 'client-42',
            apiKey: 'secret-key',
            since: new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            to: new \DateTimeImmutable('2026-07-02T00:00:00Z'),
        );

        self::assertContains('Client-Id: client-42', $capturedHeaders);
        self::assertContains('Api-Key: secret-key', $capturedHeaders);
    }

    public function testFetchThrowsOnAuthorizationFailure(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('{"message":"Invalid Api-Key"}', ['http_code' => 401]));
        $client = new OzonPostingFboListClient($httpClient);

        $this->expectException(ClientException::class);

        $client->fetch(
            clientId: 'client-1',
            apiKey: 'wrong-key',
            since: new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            to: new \DateTimeImmutable('2026-07-02T00:00:00Z'),
        );
    }
}
