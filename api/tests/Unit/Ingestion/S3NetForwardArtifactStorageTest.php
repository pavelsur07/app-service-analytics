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
        $budget = new \App\Ingestion\Application\NetForecast\NetQualityBudget();
        $this->storage($client)->putContent($key->companyId, $key->accountId, $key, $body, static fn (int $bytes) => $budget->readBytes($bytes));
        self::assertSame(2, $client->getRequestsCount());
        self::assertSame(1, $budget->summary()['reads']);
        self::assertSame(\strlen($body), $budget->summary()['readBytes']);
    }

    public function testLostPutResponseRecoversViaGetWithoutSecondPut(): void
    {
        $body = '{}';
        $key = $this->key($body);
        $client = new MockHttpClient([new MockResponse('', ['http_code' => 500]), new MockResponse($body)]);
        $budget = new \App\Ingestion\Application\NetForecast\NetQualityBudget();
        $this->storage($client)->putContent($key->companyId, $key->accountId, $key, $body, static fn (int $bytes) => $budget->readBytes($bytes));
        self::assertSame(2, $client->getRequestsCount());
        self::assertSame(1, $budget->summary()['reads']);
        self::assertSame(\strlen($body), $budget->summary()['readBytes']);
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

    public function testCompressedResponseIsRejectedBeforeReadingItsBody(): void
    {
        $key = $this->key('{}');
        $client = new MockHttpClient([new MockResponse((static function (): \Generator {
            yield new \RuntimeException('Encoded artifact bytes must not be read or decompressed.');
        })(), ['response_headers' => ['content-encoding: gzip']])]);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid artifact length or content encoding.');
        $this->storage($client)->get($key->companyId, $key->accountId, $key, 1024);
    }

    public function testReadbackCannotStartOutsideSharedQualityByteBudget(): void
    {
        $body = '{}';
        $key = $this->key($body);
        $client = new MockHttpClient([new MockResponse(''), new MockResponse($body)]);
        $budget = new \App\Ingestion\Application\NetForecast\NetQualityBudget(maxReadBytes: 1);
        $this->expectException(\OverflowException::class);
        try {
            $this->storage($client)->putContent($key->companyId, $key->accountId, $key, $body, static fn (int $bytes) => $budget->readBytes($bytes));
        } finally {
            self::assertSame(1, $client->getRequestsCount(), 'Guard must run before the GET.');
        }
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
