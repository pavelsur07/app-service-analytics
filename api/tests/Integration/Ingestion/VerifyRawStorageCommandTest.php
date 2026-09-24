<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\RawDocumentStorage;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceRawDocumentRepository;
use App\Ingestion\Infrastructure\Persistence\RawDocumentBody;
use App\Ingestion\Ui\Command\VerifyRawStorageCommand;
use App\Tests\Support\Builder\MarketplaceRawDocumentBuilder;
use AsyncAws\S3\S3Client;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Сверка хранилища сырья (ADR-024, этап 2) на MinIO: чистый прогон
 * и прогон, где объект одной строки пропал.
 */
final class VerifyRawStorageCommandTest extends KernelTestCase
{
    public function testPassesWhenEveryRowHasItsObject(): void
    {
        self::bootKernel();
        $since = new \DateTimeImmutable('-1 minute');
        $repository = MarketplaceRawDocumentBuilder::repository(self::getContainer());
        MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()->withRawBody('{"result":["a"]}')->persistWith($repository);
        MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()->withRawBody('{"result":["b"]}')->persistWith($repository);

        $tester = $this->tester();
        $status = $tester->execute(['--since' => $since->format('Y-m-d H:i:s')]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        self::assertStringContainsString('Расхождений — 0.', $tester->getDisplay());
        self::assertStringContainsString('Строк без объекта (тело в базе) — 0.', $tester->getDisplay());
    }

    public function testFailsAndNamesTheRowWhoseObjectIsMissing(): void
    {
        self::bootKernel();
        $since = new \DateTimeImmutable('-1 minute');
        $repository = MarketplaceRawDocumentBuilder::repository(self::getContainer());
        MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()->withRawBody('{"result":["kept"]}')->persistWith($repository);
        $lost = MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()->withRawBody('{"result":["lost"]}')->persistWith($repository);

        $s3 = self::getContainer()->get(S3Client::class);
        self::assertInstanceOf(S3Client::class, $s3);
        $bucket = $_ENV['RAW_STORAGE_BUCKET'] ?? null;
        self::assertIsString($bucket);
        $s3->deleteObject([
            'Bucket' => $bucket,
            'Key' => RawDocumentBody::key($lost->companyId(), $lost->marketplaceAccountId(), $lost->reportType(), $lost->period(), $lost->bodyHash())->toString(),
        ])->resolve();

        $tester = $this->tester();
        $status = $tester->execute(['--since' => $since->format('Y-m-d H:i:s')]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Расхождений — 1.', $tester->getDisplay());
        self::assertStringContainsString($lost->id()->toRfc4122().': объекта нет', $tester->getDisplay());
        self::assertStringNotContainsString('lost', $tester->getDisplay());
    }

    /**
     * При RAW_BODY_STORE=s3 строк без объекта быть не должно: тело в базе
     * после момента выкладки — отказ сверки, а не «всё в порядке».
     */
    public function testFailsWhenRowsWithoutObjectAppeared(): void
    {
        self::bootKernel();
        $since = new \DateTimeImmutable('-1 minute');
        $storage = self::getContainer()->get(RawDocumentStorage::class);
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(RawDocumentStorage::class, $storage);
        self::assertInstanceOf(Connection::class, $connection);
        $database = new DoctrineMarketplaceRawDocumentRepository($connection, $storage, new RawDocumentBody($storage), DoctrineMarketplaceRawDocumentRepository::STORE_DATABASE);
        MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()->withRawBody('{"result":["in-db"]}')->persistWith($database);

        $tester = $this->tester();
        $status = $tester->execute(['--since' => $since->format('Y-m-d H:i:s')]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Строк без объекта (тело в базе) — 1.', $tester->getDisplay());
    }

    /**
     * Документ до этапа 2 получил ключ при повторной загрузке, сохранив
     * прежнее received_at: окно --since его не видит, полная сверка — видит.
     */
    public function testFullRunChecksOldRowsThatGotAnObjectLater(): void
    {
        self::bootKernel();
        $storage = self::getContainer()->get(RawDocumentStorage::class);
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(RawDocumentStorage::class, $storage);
        self::assertInstanceOf(Connection::class, $connection);
        $builder = MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
            ->withRawBody('{"result":["old"]}')
            ->withReceivedAt(new \DateTimeImmutable('-30 days'));
        $database = new DoctrineMarketplaceRawDocumentRepository($connection, $storage, new RawDocumentBody($storage), DoctrineMarketplaceRawDocumentRepository::STORE_DATABASE);
        $id = $database->add($builder->build());
        MarketplaceRawDocumentBuilder::repository(self::getContainer())->add($builder->build());

        $tester = $this->tester();
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        self::assertEquals(1, $connection->fetchOne('SELECT COUNT(*) FROM marketplace_raw_document WHERE id = ? AND storage_key IS NOT NULL', [$id->toRfc4122()]));
        self::assertStringContainsString('Все строки', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $command = self::getContainer()->get(VerifyRawStorageCommand::class);
        self::assertInstanceOf(VerifyRawStorageCommand::class, $command);

        return new CommandTester($command);
    }
}
