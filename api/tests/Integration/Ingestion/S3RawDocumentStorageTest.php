<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\RawObjectKey;
use App\Ingestion\Domain\RawObjectNotFound;
use App\Ingestion\Infrastructure\Storage\S3RawDocumentStorage;
use AsyncAws\S3\S3Client;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-024 на MinIO из docker-compose (бакет conwix-test, make s3-bucket-create).
 *
 * DAMA откатывает только PostgreSQL: объекты в бакете переживают тест.
 * Поэтому ключи уникальны на тест (новые UUID компании и подключения),
 * а записанное удаляется в tearDown.
 */
final class S3RawDocumentStorageTest extends KernelTestCase
{
    private S3Client $s3;

    private string $bucket;

    private S3RawDocumentStorage $storage;

    /** @var list<RawObjectKey> */
    private array $written = [];

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var S3Client $s3 */
        $s3 = self::getContainer()->get(S3Client::class);
        $bucket = $_ENV['RAW_STORAGE_BUCKET'] ?? null;
        self::assertIsString($bucket);

        $this->s3 = $s3;
        $this->bucket = $bucket;
        $this->storage = new S3RawDocumentStorage($s3, $bucket);
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $key) {
            $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $key->toString()])->resolve();
        }
        $this->written = [];

        parent::tearDown();
    }

    public function testReadsBackExactBytes(): void
    {
        $body = "{\"result\":[{\"posting_number\":\"A-1\",\"name\":\"Брюки\"}]}\n";
        $key = $this->key($body);

        $this->storage->put($key, $body);

        self::assertTrue($this->storage->exists($key));
        self::assertSame($body, $this->storage->get($key));
    }

    public function testStoresGzipOnTheWire(): void
    {
        $body = str_repeat('{"accrual_id":1,"amount":"10.00"}', 200);
        $key = $this->key($body);

        $this->storage->put($key, $body);

        $stored = $this->s3->getObject(['Bucket' => $this->bucket, 'Key' => $key->toString()])->getBody()->getContentAsString();
        self::assertStringStartsWith("\x1f\x8b", $stored);
        self::assertLessThan(\strlen($body), \strlen($stored));
        self::assertSame($body, gzdecode($stored));
    }

    public function testRepeatedPutOfSameKeyIsIdempotent(): void
    {
        $body = '{"result":[]}';
        $key = $this->key($body);

        $this->storage->put($key, $body);
        $this->storage->put($key, $body);

        $listed = $this->s3->listObjectsV2(['Bucket' => $this->bucket, 'Prefix' => $key->toString()]);
        self::assertCount(1, iterator_to_array($listed->getContents()));
        self::assertSame($body, $this->storage->get($key));
    }

    /**
     * Условная запись: существующий объект не перезаписывается. Другое
     * тело под тем же ключом в жизни не встречается (ключ — хэш тела),
     * здесь оно нужно, чтобы увидеть, что второй PUT объект не тронул.
     */
    public function testExistingObjectIsNotOverwritten(): void
    {
        $first = '{"result":["first"]}';
        $key = $this->key($first);

        $this->storage->put($key, $first);
        $this->storage->put($key, '{"result":["second"]}');

        self::assertSame($first, $this->storage->get($key));
    }

    public function testMissingObjectIsAnErrorNotAnEmptyBody(): void
    {
        $key = $this->key('{"never":"written"}', track: false);

        self::assertFalse($this->storage->exists($key));

        $this->expectException(RawObjectNotFound::class);
        $this->storage->get($key);
    }

    private function key(string $body, bool $track = true): RawObjectKey
    {
        $key = RawObjectKey::for(Uuid::v7(), Uuid::v7(), 'ozon_posting_fbo_list', new \DateTimeImmutable('2026-09-04'), hash('sha256', $body), 'json');
        if ($track) {
            $this->written[] = $key;
        }

        return $key;
    }
}
