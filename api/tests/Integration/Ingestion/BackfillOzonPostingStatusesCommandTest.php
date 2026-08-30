<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Infrastructure\Query\OzonPostingRawHistoryQuery;
use App\Ingestion\Ui\Command\BackfillOzonPostingStatusesCommand;
use App\Tests\Support\Builder\MarketplaceRawDocumentBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class BackfillOzonPostingStatusesCommandTest extends KernelTestCase
{
    private const string BEFORE = __DIR__.'/../../Fixtures/Marketplace/ozon/ozon-buyout-posting-statuses-before.json';
    private const string AFTER = __DIR__.'/../../Fixtures/Marketplace/ozon/ozon-buyout-posting-statuses-after.json';

    public function testBackfillsOldestRawFirstWithinTheRequestedTenantAndIsIdempotent(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $foreignCompanyId = Uuid::v7();
        $repository = $this->rawDocuments();

        /** @var OzonPostingRawHistoryQuery $history */
        $history = self::getContainer()->get(OzonPostingRawHistoryQuery::class);
        self::assertStringNotContainsString('body', $history->build(
            $companyId->toRfc4122(),
            $accountId->toRfc4122(),
            new \DateTimeImmutable('2026-08-29 21:00:00'),
            new \DateTimeImmutable('2026-08-30 21:00:00'),
            null,
            null,
            100,
        )->getSQL());

        // Вставка намеренно в обратном порядке: порядок backfill обязан
        // определяться received_at, не порядком чтения/UUID.
        $this->raw($companyId, $accountId, self::AFTER, '2026-08-30 10:00:00')->persistWith($repository);
        $this->raw($companyId, $accountId, self::BEFORE, '2026-08-30 09:00:00')->persistWith($repository);
        $this->raw($foreignCompanyId, $accountId, self::BEFORE, '2026-08-30 08:00:00')->persistWith($repository);
        $this->raw($companyId, $accountId, self::BEFORE, '2026-08-30 07:00:00')
            ->withReportType(MarketplaceReportType::OzonProductList)
            ->persistWith($repository);

        $tester = $this->tester();
        $arguments = $this->arguments($companyId, $accountId);

        $tester->execute($arguments);
        $tester->assertCommandIsSuccessful();

        $connection = $this->connection();
        self::assertEquals(12, $connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_posting_status WHERE company_id = ?',
            [$companyId->toRfc4122()],
        ));
        self::assertEquals(0, $connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_posting_status WHERE company_id = ?',
            [$foreignCompanyId->toRfc4122()],
        ));
        self::assertSame(
            ['2026-08-30 09:00:00', '2026-08-30 10:00:00'],
            $connection->fetchFirstColumn(
                'SELECT observed_at FROM marketplace_posting_status WHERE company_id = ? AND posting_number = ? ORDER BY observed_at',
                [$companyId->toRfc4122(), 'TEST-MIX-1-1'],
            ),
        );
        self::assertEquals(7, $connection->fetchOne(
            'SELECT COUNT(*) FROM sales_fact WHERE company_id = ?',
            [$companyId->toRfc4122()],
        ));

        $tester->execute($arguments);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('new_statuses=0', $tester->getDisplay());
        self::assertEquals(12, $connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_posting_status WHERE company_id = ?',
            [$companyId->toRfc4122()],
        ));
    }

    public function testDryRunParsesButDoesNotWrite(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $this->raw($companyId, $accountId, self::BEFORE, '2026-08-30 09:00:00')
            ->persistWith($this->rawDocuments());

        $tester = $this->tester();
        $arguments = $this->arguments($companyId, $accountId);
        $arguments['--dry-run'] = true;

        $tester->execute($arguments);
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('documents=1', $tester->getDisplay());
        self::assertStringContainsString('statuses=5', $tester->getDisplay());
        self::assertStringContainsString('facts=5', $tester->getDisplay());
        self::assertEquals(0, $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM marketplace_posting_status WHERE company_id = ?',
            [$companyId->toRfc4122()],
        ));
        self::assertEquals(0, $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM sales_fact WHERE company_id = ?',
            [$companyId->toRfc4122()],
        ));
    }

    private function raw(Uuid $companyId, Uuid $accountId, string $path, string $receivedAt): MarketplaceRawDocumentBuilder
    {
        $body = file_get_contents($path);
        self::assertIsString($body);

        return MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withReportType(MarketplaceReportType::OzonPostingFboList)
            ->withPeriod(new \DateTimeImmutable('2026-08-01'))
            ->withReceivedAt(new \DateTimeImmutable($receivedAt))
            ->withRawBody($body);
    }

    /**
     * @return array<string, string|bool>
     */
    private function arguments(Uuid $companyId, Uuid $accountId): array
    {
        return [
            'companyId' => $companyId->toRfc4122(),
            'marketplaceAccountId' => $accountId->toRfc4122(),
            '--from' => '2026-08-30',
            '--to' => '2026-08-30',
        ];
    }

    private function tester(): CommandTester
    {
        /** @var BackfillOzonPostingStatusesCommand $command */
        $command = self::getContainer()->get(BackfillOzonPostingStatusesCommand::class);

        return new CommandTester($command);
    }

    private function rawDocuments(): MarketplaceRawDocumentRepository
    {
        /** @var MarketplaceRawDocumentRepository $repository */
        $repository = self::getContainer()->get(MarketplaceRawDocumentRepository::class);

        return $repository;
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
