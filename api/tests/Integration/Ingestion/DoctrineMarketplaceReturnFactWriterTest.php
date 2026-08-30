<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceReturnFactWriter;
use App\Tests\Support\Builder\MarketplaceReturnFactBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DoctrineMarketplaceReturnFactWriterTest extends KernelTestCase
{
    private Connection $connection;
    private DoctrineMarketplaceReturnFactWriter $writer;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $this->connection = $connection;
        $this->writer = new DoctrineMarketplaceReturnFactWriter($connection);
    }

    public function testSameReturnIsIdempotentAndKeepsFirstLoadedAt(): void
    {
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $fact = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->build();

        $this->writer->upsertAll([$fact]);
        $firstLoadedAt = $this->connection->fetchOne(
            'SELECT first_loaded_at FROM marketplace_return_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122(), '900001'],
        );
        $this->writer->upsertAll([$fact]);

        self::assertEquals(1, $this->connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_return_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122(), '900001'],
        ));
        self::assertSame($firstLoadedAt, $this->connection->fetchOne(
            'SELECT first_loaded_at FROM marketplace_return_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122(), '900001'],
        ));
    }

    public function testChangedVisualStatusUpdatesMutableFieldsAndRawTrace(): void
    {
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $oldRawId = Uuid::v7();
        $newRawId = Uuid::v7();
        $base = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId);

        $this->writer->upsertAll([$base->withRawDocumentId($oldRawId)->build()]);
        $this->writer->upsertAll([
            $base
                ->withVisualStatus(34, 'ReturnedToOzon', new \DateTimeImmutable('2026-08-12T12:00:00Z'))
                ->withRawDocumentId($newRawId)
                ->build(),
        ]);

        $row = $this->connection->fetchAssociative(
            'SELECT visual_status_id, visual_status, visual_status_changed_at, raw_document_id FROM marketplace_return_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122(), '900001'],
        );
        self::assertNotFalse($row);
        self::assertSame(34, $row['visual_status_id']);
        self::assertSame('ReturnedToOzon', $row['visual_status']);
        self::assertSame('2026-08-12 12:00:00', $row['visual_status_changed_at']);
        self::assertIsString($row['raw_document_id']);
        self::assertSame($newRawId->toRfc4122(), $row['raw_document_id']);
    }

    public function testOlderVisualSnapshotCannotOverwriteANewerReturnFact(): void
    {
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $newRawId = Uuid::v7();
        $base = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId);

        $this->writer->upsertAll([
            $base
                ->withVisualStatus(34, 'ReturnedToOzon', new \DateTimeImmutable('2026-08-12T12:00:00Z'))
                ->withRawDocumentId($newRawId)
                ->build(),
        ]);
        $this->writer->upsertAll([
            $base
                ->withVisualStatus(1, 'AwaitingReturn', new \DateTimeImmutable('2026-08-11T12:00:00Z'))
                ->withRawDocumentId(Uuid::v7())
                ->build(),
        ]);

        $row = $this->connection->fetchAssociative(
            'SELECT visual_status_id, visual_status, visual_status_changed_at, raw_document_id FROM marketplace_return_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122(), '900001'],
        );

        self::assertNotFalse($row);
        self::assertSame(34, $row['visual_status_id']);
        self::assertSame('ReturnedToOzon', $row['visual_status']);
        self::assertSame('2026-08-12 12:00:00', $row['visual_status_changed_at']);
        self::assertSame($newRawId->toRfc4122(), $row['raw_document_id']);
    }

    public function testDifferentReturnIdsForSameOrderAndSkuRemainSeparateFacts(): void
    {
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $base = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withOrderNumber('DUPLICATE-PAIR')
            ->withMarketplaceSku('100001');

        $this->writer->upsertAll([
            $base->withSourceRowId('900001')->build(),
            $base->withSourceRowId('900006')->withSourceId(800006)->build(),
        ]);

        self::assertEquals(2, $this->connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_return_fact WHERE company_id = ? AND marketplace_account_id = ? AND order_number = ? AND marketplace_sku = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122(), 'DUPLICATE-PAIR', '100001'],
        ));
    }

    public function testSameNaturalKeyIsIndependentAcrossCompanies(): void
    {
        $companyA = Uuid::v7();
        $companyB = Uuid::v7();
        $accountId = Uuid::v7();
        $base = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
            ->withMarketplaceAccountId($accountId)
            ->withSourceRowId('SHARED-RETURN');

        $this->writer->upsertAll([
            $base->withCompanyId($companyA)->withReturnType('Cancellation')->build(),
            $base->withCompanyId($companyB)->withReturnType('ClientReturn')->build(),
        ]);

        self::assertSame('Cancellation', $this->connection->fetchOne(
            'SELECT return_type FROM marketplace_return_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            [$companyA->toRfc4122(), $accountId->toRfc4122(), 'SHARED-RETURN'],
        ));
        self::assertSame('ClientReturn', $this->connection->fetchOne(
            'SELECT return_type FROM marketplace_return_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            [$companyB->toRfc4122(), $accountId->toRfc4122(), 'SHARED-RETURN'],
        ));
    }
}
