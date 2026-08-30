<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplacePostingStatusWriter;
use App\Tests\Support\Builder\MarketplacePostingStatusBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DoctrineMarketplacePostingStatusWriterTest extends KernelTestCase
{
    private Connection $connection;
    private DoctrineMarketplacePostingStatusWriter $writer;
    private Uuid $companyId;
    private Uuid $accountId;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->writer = new DoctrineMarketplacePostingStatusWriter($connection);
        $this->companyId = Uuid::v7();
        $this->accountId = Uuid::v7();
    }

    public function testSameRawDocumentIsIdempotent(): void
    {
        $rawDocumentId = Uuid::v7();
        $status = $this->statusBuilder()->withRawDocumentId($rawDocumentId)->build();

        $this->writer->recordChanged($this->companyId->toRfc4122(), [$status]);
        $this->writer->recordChanged($this->companyId->toRfc4122(), [$status]);

        self::assertSame(1, $this->countRows());
    }

    public function testNewRawWithUnchangedStatusDoesNotGrowHistory(): void
    {
        $this->writer->recordChanged($this->companyId->toRfc4122(), [
            $this->statusBuilder()
                ->withObservedAt(new \DateTimeImmutable('2026-08-30 09:00:00'))
                ->build(),
        ]);
        $this->writer->recordChanged($this->companyId->toRfc4122(), [
            $this->statusBuilder()
                ->withObservedAt(new \DateTimeImmutable('2026-08-30 10:00:00'))
                ->withRawDocumentId(Uuid::v7())
                ->build(),
        ]);

        self::assertSame(1, $this->countRows());
    }

    public function testHistoricalObservationIsNotComparedWithAChronologicallyLaterState(): void
    {
        $this->writer->recordChanged($this->companyId->toRfc4122(), [
            $this->statusBuilder()
                ->withObservedAt(new \DateTimeImmutable('2026-08-30 10:00:00'))
                ->build(),
        ]);
        $this->writer->recordChanged($this->companyId->toRfc4122(), [
            $this->statusBuilder()
                ->withObservedAt(new \DateTimeImmutable('2026-08-30 09:00:00'))
                ->withRawDocumentId(Uuid::v7())
                ->build(),
        ]);

        self::assertSame(2, $this->countRows());
    }

    public function testChangedStatusCreatesOneNewObservation(): void
    {
        $this->writer->recordChanged($this->companyId->toRfc4122(), [
            $this->statusBuilder()
                ->withObservedAt(new \DateTimeImmutable('2026-08-30 09:00:00'))
                ->build(),
        ]);
        $this->writer->recordChanged($this->companyId->toRfc4122(), [
            $this->statusBuilder()
                ->withStatus('delivering', 'posting_on_way_to_city')
                ->withObservedAt(new \DateTimeImmutable('2026-08-30 10:00:00'))
                ->withRawDocumentId(Uuid::v7())
                ->build(),
        ]);

        self::assertSame(['awaiting_packaging', 'delivering'], $this->statuses());
    }

    public function testNullToValueChangeCreatesObservation(): void
    {
        $this->writer->recordChanged($this->companyId->toRfc4122(), [
            $this->statusBuilder()->withStatus('cancelled')->build(),
        ]);
        $this->writer->recordChanged($this->companyId->toRfc4122(), [
            $this->statusBuilder()
                ->withStatus('cancelled', 'posting_canceled', 506)
                ->withObservedAt(new \DateTimeImmutable('2026-08-30 10:00:00'))
                ->withRawDocumentId(Uuid::v7())
                ->build(),
        ]);

        self::assertSame(2, $this->countRows());
    }

    public function testOneBatchRecordsSeveralPostingsForTheTenant(): void
    {
        $this->writer->recordChanged($this->companyId->toRfc4122(), [
            $this->statusBuilder()->withPostingNumber('TEST-POSTING-1')->build(),
            $this->statusBuilder()
                ->withPostingNumber('TEST-POSTING-2')
                ->withOrderNumber('TEST-ORDER-2')
                ->build(),
        ]);

        self::assertSame(2, $this->countRows());
    }

    public function testBatchRejectsAStatusFromAnotherCompany(): void
    {
        $foreignCompanyId = Uuid::v7();

        $this->expectException(\InvalidArgumentException::class);

        $this->writer->recordChanged($this->companyId->toRfc4122(), [
            $this->statusBuilder()->build(),
            $this->statusBuilder()->withCompanyId($foreignCompanyId)->build(),
        ]);
    }

    private function statusBuilder(): MarketplacePostingStatusBuilder
    {
        return MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($this->accountId);
    }

    private function countRows(): int
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_posting_status WHERE company_id = :companyId',
            ['companyId' => $this->companyId->toRfc4122()],
        );
        self::assertTrue(\is_int($count) || \is_string($count));

        return (int) $count;
    }

    /**
     * @return list<string>
     */
    private function statuses(): array
    {
        $values = $this->connection->fetchFirstColumn(
            'SELECT status FROM marketplace_posting_status WHERE company_id = :companyId ORDER BY observed_at, raw_document_id',
            ['companyId' => $this->companyId->toRfc4122()],
        );

        return array_map(
            static function (mixed $value): string {
                self::assertIsString($value);

                return $value;
            },
            $values,
        );
    }
}
