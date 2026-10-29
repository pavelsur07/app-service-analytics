<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Domain\NetForwardArtifactKey;
use App\Ingestion\Domain\NetForwardArtifactKind;
use App\Ingestion\Infrastructure\Storage\S3NetForwardArtifactStorage;
use AsyncAws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

final class S3NetForwardArtifactStorageTest extends TestCase
{
    public function testIgnoredConditionalPutStillRequiresExactReadBack(): void
    {
        $body = '{"exact":1}';
        $key = $this->key($body);
        $client = new MockHttpClient([new MockResponse('', ['http_code' => 200]), new MockResponse($body, ['response_headers' => ['content-length: '.\strlen($body)]])]);
        $this->storage($client)->putContent($key->companyId, $key->accountId, $key, $body);
        self::assertSame(2, $client->getRequestsCount());
    }

    public function testLostPutResponseRecoversViaGetWithoutSecondPut(): void
    {
        $body = '{}';
        $key = $this->key($body);
        $client = new MockHttpClient([new MockResponse('', ['http_code' => 500]), new MockResponse($body)]);
        $this->storage($client)->putContent($key->companyId, $key->accountId, $key, $body);
        self::assertSame(2, $client->getRequestsCount());
    }

    public function testSuccessfulPutWithCorruptedReadBackFails(): void
    {
        $key = $this->key('{}');
        $client = new MockHttpClient([new MockResponse(''), new MockResponse('[]')]);
        $this->expectException(\UnexpectedValueException::class);
        $this->storage($client)->putContent($key->companyId, $key->accountId, $key, '{}');
    }

    public function testStreamingLimitDoesNotTrustMissingLength(): void
    {
        $key = $this->key('{}');
        $client = new MockHttpClient([new MockResponse(str_repeat('x', 300))]);
        $this->expectException(\OverflowException::class);
        $this->storage($client)->get($key->companyId, $key->accountId, $key, 2);
    }

    private function key(string $body): NetForwardArtifactKey
    {
        return new NetForwardArtifactKey(Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), NetForwardArtifactKind::Input, Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), hash('sha256', $body));
    }

    private function storage(MockHttpClient $client): S3NetForwardArtifactStorage
    {
        return new S3NetForwardArtifactStorage(new S3Client(['endpoint' => 'http://local.invalid', 'region' => 'local', 'accessKeyId' => 'test', 'accessKeySecret' => 'test', 'pathStyleEndpoint' => 'true'], httpClient: $client), 'test');
    }
}
