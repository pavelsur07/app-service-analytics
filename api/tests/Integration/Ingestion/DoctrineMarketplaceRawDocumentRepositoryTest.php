<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\RawDocumentStorage;
use App\Ingestion\Domain\RawObjectKey;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceRawDocumentRepository;
use App\Ingestion\Infrastructure\Persistence\RawDocumentBody;
use App\Tests\Support\Builder\MarketplaceRawDocumentBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-006: повторное получение идентичного ответа не создаёт новой строки;
 * изменившийся ответ того же периода — создаёт (новый body_hash).
 *
 * ADR-024, этап 2: при RAW_BODY_STORE=s3 тело — объект в хранилище
 * (SeaweedFS, бакет conwix-test), в строке body = NULL и ключ; строки
 * с телом в базе (до этапа 2 и аварийный режим database) читаются
 * так же. DAMA откатывает только PostgreSQL — объекты остаются, но ключи
 * детерминированы и уникальны на тест (новые UUID компании).
 */
final class DoctrineMarketplaceRawDocumentRepositoryTest extends KernelTestCase
{
    public function testStoresBodyInObjectStorageAndReadsItBack(): void
    {
        self::bootKernel();
        $connection = $this->connection();
        $repository = $this->repository(DoctrineMarketplaceRawDocumentRepository::STORE_S3);
        $body = '{"raw":"exact\\nbytes","name":"Брюки"}';

        $document = MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
            ->withRawBody($body)
            ->persistWith($repository);

        $row = $connection->fetchAssociative(
            'SELECT body, storage_key, byte_size FROM marketplace_raw_document WHERE id = ?',
            [$document->id()->toRfc4122()],
        );
        self::assertIsArray($row);
        self::assertNull($row['body']);
        self::assertSame($this->keyOf($document)->toString(), $row['storage_key']);
        self::assertEquals(\strlen($body), $row['byte_size']);
        self::assertSame($body, $this->storage()->get($document->companyId()->toRfc4122(), $this->keyOf($document)));

        self::assertSame($body, $repository->body(
            $document->companyId()->toRfc4122(),
            $document->marketplaceAccountId(),
            $document->id(),
        ));
    }

    public function testReadsBodyKeptInDatabaseBeforeObjectStorage(): void
    {
        self::bootKernel();
        $repository = $this->repository(DoctrineMarketplaceRawDocumentRepository::STORE_DATABASE);
        $body = '{"result":[{"posting_number":"OLD-1"}]}';

        $document = MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
            ->withRawBody($body)
            ->persistWith($repository);

        self::assertFalse($this->storage()->exists($document->companyId()->toRfc4122(), $this->keyOf($document)));
        self::assertSame($body, $this->connection()->fetchOne('SELECT body FROM marketplace_raw_document WHERE id = ?', [$document->id()->toRfc4122()]));

        // Читает и репозиторий в режиме s3: вид строки решает чтение, не режим записи.
        self::assertSame($body, $this->repository(DoctrineMarketplaceRawDocumentRepository::STORE_S3)->body(
            $document->companyId()->toRfc4122(),
            $document->marketplaceAccountId(),
            $document->id(),
        ));
    }

    /**
     * Идемпотентность (CLAUDE.md §4, §9): повтор того же документа —
     * одна строка, один объект, id первой строки.
     */
    public function testRepeatedAddIsIdempotent(): void
    {
        self::bootKernel();
        $repository = $this->repository(DoctrineMarketplaceRawDocumentRepository::STORE_S3);
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $builder = MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withRawBody('{"result":[{"posting_number":"A-1"}]}');

        $first = $repository->add($builder->build());
        $second = $repository->add($builder->build());

        self::assertTrue($first->equals($second));
        self::assertEquals(1, $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM marketplace_raw_document WHERE company_id = ? AND marketplace_account_id = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122()],
        ));
    }

    /**
     * ADR-024: объект, затем строка. Хранилище отказало — строки нет,
     * и повтор очереди запишет документ целиком.
     */
    public function testStorageFailureLeavesNoRow(): void
    {
        self::bootKernel();
        $failing = $this->unavailableStorage();
        $repository = new DoctrineMarketplaceRawDocumentRepository($this->connection(), $failing, new RawDocumentBody($failing), DoctrineMarketplaceRawDocumentRepository::STORE_S3);
        $document = MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()->build();

        try {
            $repository->add($document);
            self::fail('Сбой хранилища должен прервать запись.');
        } catch (\RuntimeException) {
        }

        self::assertEquals(0, $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM marketplace_raw_document WHERE company_id = ?',
            [$document->companyId()->toRfc4122()],
        ));
    }

    /**
     * Изоляция (CLAUDE.md §1, §9): тело не читается от имени другой компании —
     * ни из базы, ни из хранилища.
     */
    public function testBodyIsNotReadableByAnotherCompany(): void
    {
        self::bootKernel();
        $refused = [];
        foreach ([DoctrineMarketplaceRawDocumentRepository::STORE_S3, DoctrineMarketplaceRawDocumentRepository::STORE_DATABASE] as $store) {
            $repository = $this->repository($store);
            $document = MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
                ->withRawBody('{"secret":"'.$store.'"}')
                ->persistWith($repository);

            try {
                $repository->body(Uuid::v7()->toRfc4122(), $document->marketplaceAccountId(), $document->id());
                self::fail("Тело ({$store}) прочитано от имени чужой компании.");
            } catch (\RuntimeException) {
                $refused[] = $store;
            }
        }

        self::assertSame([DoctrineMarketplaceRawDocumentRepository::STORE_S3, DoctrineMarketplaceRawDocumentRepository::STORE_DATABASE], $refused);
    }

    /**
     * Аварийный режим при недоступном хранилище: повторная загрузка того же
     * документа (скользящее окно) попадает в строку этапа 2 с телом только
     * в S3 — конфликт дописывает тело в базу, и чтение в хранилище не идёт.
     */
    public function testDatabaseModeRepeatFillsBodyOfObjectOnlyRow(): void
    {
        self::bootKernel();
        $body = '{"result":[{"return_id":7}]}';
        $builder = MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()->withRawBody($body);
        $id = $this->repository(DoctrineMarketplaceRawDocumentRepository::STORE_S3)->add($builder->build());

        $unavailable = $this->unavailableStorage();
        $emergency = new DoctrineMarketplaceRawDocumentRepository($this->connection(), $unavailable, new RawDocumentBody($unavailable), DoctrineMarketplaceRawDocumentRepository::STORE_DATABASE);
        $document = $builder->build();

        self::assertTrue($id->equals($emergency->add($document)));
        self::assertSame($body, $emergency->body($document->companyId()->toRfc4122(), $document->marketplaceAccountId(), $id));
    }

    /**
     * Режим s3 поверх строки до этапа 2 (тело в базе): объект записан, строка
     * получает его ключ — объект не остаётся ни к чему не привязанным.
     */
    public function testObjectStorageRepeatAttachesKeyToDatabaseRow(): void
    {
        self::bootKernel();
        $body = '{"result":[{"posting_number":"OLD-2"}]}';
        $builder = MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()->withRawBody($body);
        $id = $this->repository(DoctrineMarketplaceRawDocumentRepository::STORE_DATABASE)->add($builder->build());
        $document = $builder->build();

        self::assertTrue($id->equals($this->repository(DoctrineMarketplaceRawDocumentRepository::STORE_S3)->add($document)));

        $row = $this->connection()->fetchAssociative('SELECT body, storage_key FROM marketplace_raw_document WHERE id = ?', [$id->toRfc4122()]);
        self::assertIsArray($row);
        self::assertSame($body, $row['body']);
        self::assertSame($this->keyOf($document)->toString(), $row['storage_key']);
        self::assertSame($body, $this->storage()->get($document->companyId()->toRfc4122(), $this->keyOf($document)));
    }

    public function testRejectsUnknownStoreMode(): void
    {
        self::bootKernel();

        $this->expectException(\LogicException::class);
        $this->repository('s3-and-database')->add(MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()->build());
    }

    public function testChangedContentForSamePeriodCreatesNewRow(): void
    {
        self::bootKernel();
        $connection = $this->connection();
        $repository = $this->repository(DoctrineMarketplaceRawDocumentRepository::STORE_S3);
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();

        MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withRawBody('{"result":[{"posting_number":"A-1"}]}')
            ->persistWith($repository);

        // Тот же период, другой контент — площадка доначислила ещё одну
        // строку в отчёте за тот же день.
        MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withRawBody('{"result":[{"posting_number":"A-1"},{"posting_number":"A-2"}]}')
            ->persistWith($repository);

        self::assertEquals(2, $connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_raw_document WHERE company_id = ? AND marketplace_account_id = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122()],
        ));
    }

    public function testIdenticalAccountReportPeriodAndBodyAreIndependentAcrossCompanies(): void
    {
        self::bootKernel();
        $connection = $this->connection();
        $repository = $this->repository(DoctrineMarketplaceRawDocumentRepository::STORE_S3);
        $accountId = Uuid::v7();
        $companyA = Uuid::v7();
        $companyB = Uuid::v7();

        // Тот же marketplace_account_id и тот же контент у двух разных
        // компаний обязаны дать две независимые строки и два объекта
        // в разных префиксах, а не дедуп друг против друга (CLAUDE.md §1).
        foreach ([$companyA, $companyB] as $company) {
            MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
                ->withCompanyId($company)
                ->withMarketplaceAccountId($accountId)
                ->withRawBody('{"result":[{"posting_number":"A-1"}]}')
                ->persistWith($repository);
        }

        foreach ([$companyA, $companyB] as $company) {
            self::assertEquals(1, $connection->fetchOne(
                'SELECT COUNT(*) FROM marketplace_raw_document WHERE company_id = ? AND marketplace_account_id = ? AND storage_key LIKE ?',
                [$company->toRfc4122(), $accountId->toRfc4122(), RawObjectKey::PREFIX.'/companies/'.$company->toRfc4122().'/%'],
            ));
        }
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        return $connection;
    }

    private function storage(): RawDocumentStorage
    {
        $storage = self::getContainer()->get(RawDocumentStorage::class);
        \assert($storage instanceof RawDocumentStorage);

        return $storage;
    }

    private function unavailableStorage(): RawDocumentStorage
    {
        return new class implements RawDocumentStorage {
            public function put(string $companyId, RawObjectKey $key, string $body): void
            {
                throw new \RuntimeException('S3 недоступен');
            }

            public function get(string $companyId, RawObjectKey $key): string
            {
                throw new \RuntimeException('S3 недоступен');
            }

            public function exists(string $companyId, RawObjectKey $key): bool
            {
                throw new \RuntimeException('S3 недоступен');
            }
        };
    }

    private function repository(string $store): DoctrineMarketplaceRawDocumentRepository
    {
        return new DoctrineMarketplaceRawDocumentRepository($this->connection(), $this->storage(), new RawDocumentBody($this->storage()), $store);
    }

    private function keyOf(\App\Ingestion\Domain\MarketplaceRawDocument $document): RawObjectKey
    {
        return RawDocumentBody::key(
            $document->companyId(),
            $document->marketplaceAccountId(),
            $document->reportType(),
            $document->period(),
            $document->bodyHash(),
        );
    }
}
