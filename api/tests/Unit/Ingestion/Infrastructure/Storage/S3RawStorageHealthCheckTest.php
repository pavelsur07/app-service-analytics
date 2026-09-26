<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Infrastructure\Storage;

use App\Ingestion\Infrastructure\Storage\S3RawStorageHealthCheck;
use AsyncAws\Core\Credentials\NullProvider;
use AsyncAws\S3\Exception\BucketAlreadyExistsException;
use AsyncAws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class S3RawStorageHealthCheckTest extends TestCase
{
    private const string ALREADY_EXISTS = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>BucketAlreadyExists</Code><Message>The requested bucket name is not available.</Message></Error>';

    public function testOwnBucketReportedAsAlreadyExistsIsAcceptedWhenItCanBeListed(): void
    {
        $check = self::check([
            new MockResponse(self::ALREADY_EXISTS, ['http_code' => 409]),
            new MockResponse('<?xml version="1.0" encoding="UTF-8"?><ListBucketResult><Name>raw</Name><KeyCount>0</KeyCount><MaxKeys>1</MaxKeys><IsTruncated>false</IsTruncated></ListBucketResult>', ['http_code' => 200]),
        ]);

        self::assertFalse($check->ensureBucket());
    }

    public function testForeignBucketStaysAnError(): void
    {
        $check = self::check([
            new MockResponse(self::ALREADY_EXISTS, ['http_code' => 409]),
            new MockResponse('<?xml version="1.0" encoding="UTF-8"?><Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>', ['http_code' => 403]),
        ]);

        $this->expectException(BucketAlreadyExistsException::class);
        $check->ensureBucket();
    }

    /** @param list<MockResponse> $responses */
    private static function check(array $responses): S3RawStorageHealthCheck
    {
        $s3 = new S3Client(
            ['endpoint' => 'http://s3.test', 'region' => 'ru-1', 'pathStyleEndpoint' => 'true'],
            new NullProvider(),
            new MockHttpClient($responses),
        );

        return new S3RawStorageHealthCheck($s3, 'raw', 'http://s3.test', 'ru-1', 'key', 'secret');
    }
}
