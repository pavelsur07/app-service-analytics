<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Ui\Command\ImportOzonAccrualFixtureCommand;
use App\Ingestion\Ui\Command\SeedDashboardSandboxCatalogCommand;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class ImportOzonAccrualFixtureCommandTest extends KernelTestCase
{
    private string $companyId;
    private string $accountId;
    private string $file;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->companyId = Uuid::v7()->toRfc4122();
        $this->accountId = Uuid::v7()->toRfc4122();
        $file = tempnam(sys_get_temp_dir(), 'profit-fixture-');
        self::assertIsString($file);
        $this->file = $file;
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $this->connection = $connection;
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        parent::tearDown();
    }

    public function testFullDayIsStoredIdempotentlyAndMarkedComplete(): void
    {
        $this->fixture();
        self::assertSame(0, $this->import());
        self::assertSame(0, $this->import());
        self::assertSame('-150', $this->connection->fetchOne('SELECT SUM(amount_minor) FROM marketplace_expense_fact WHERE company_id = ?', [$this->companyId]));
        $mark = $this->mark();
        self::assertNotNull($mark['completed_at']);
        self::assertNull($mark['failed_at']);
    }

    public function testRejectedPageWritesNoFactsAndSuccessfulRepeatClearsFailure(): void
    {
        $this->fixture(cursor: 'next-page');
        try {
            $this->import();
            self::fail('An incomplete day must be rejected.');
        } catch (\UnexpectedValueException) {
            self::assertNotNull($this->mark()['failed_at']);
            self::assertFalse($this->connection->fetchOne('SELECT EXISTS (SELECT 1 FROM marketplace_expense_fact WHERE company_id = ?)', [$this->companyId]));
        }
        $this->fixture();
        self::assertSame(0, $this->import());
        self::assertNull($this->mark()['failed_at']);
    }

    public function testWrongBusinessDateIsRejectedBeforeFactWrite(): void
    {
        $this->fixture(day: '2026-08-02');
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('date');
        $this->import();
    }

    public function testInvalidBalanceDoesNotMarkDayComplete(): void
    {
        $this->fixture(total: '-2.00');
        try {
            $this->import();
            self::fail('Unbalanced accrual must fail.');
        } catch (\UnexpectedValueException) {
            self::assertNull($this->mark()['completed_at']);
            self::assertNotNull($this->mark()['failed_at']);
        }
    }

    public function testEmptyDayIsACompletedDay(): void
    {
        file_put_contents($this->file, '{"accruals":[],"last_id":""}');
        self::assertSame(0, $this->import());
        self::assertNotNull($this->mark()['completed_at']);
    }

    public function testSandboxCatalogProvidesTheCardRequiredForCostEntry(): void
    {
        /** @var SeedDashboardSandboxCatalogCommand $command */
        $command = self::getContainer()->get(SeedDashboardSandboxCatalogCommand::class);
        self::assertSame(0, new CommandTester($command)->execute(['companyId' => $this->companyId, 'marketplaceAccountId' => $this->accountId]));
        self::assertSame('E2E Dashboard Profit', $this->connection->fetchOne('SELECT name FROM marketplace_listing WHERE company_id = ? AND marketplace_account_id = ? AND marketplace_sku = ?', [$this->companyId, $this->accountId, '900000001']));
    }

    private function fixture(string $cursor = '', string $day = '2026-08-01', string $total = '-1.50'): void
    {
        file_put_contents($this->file, json_encode(['accruals' => [[
            'accrual_id' => 1, 'date' => $day, 'accrued_category' => 'NON_ITEM',
            'total_amount' => ['amount' => $total, 'currency' => 'RUB'],
            'non_item_fee' => ['type_id' => 41, 'accrued' => ['amount' => '-1.50', 'currency' => 'RUB']],
        ]], 'last_id' => $cursor], \JSON_THROW_ON_ERROR));
    }

    private function import(): int
    {
        /** @var ImportOzonAccrualFixtureCommand $command */
        $command = self::getContainer()->get(ImportOzonAccrualFixtureCommand::class);

        return new CommandTester($command)->execute(['companyId' => $this->companyId, 'marketplaceAccountId' => $this->accountId, 'businessDate' => '2026-08-01', 'fixturePath' => $this->file]);
    }

    /** @return array{completed_at: mixed, failed_at: mixed} */
    private function mark(): array
    {
        /** @var array{completed_at: mixed, failed_at: mixed}|false $mark */
        $mark = $this->connection->fetchAssociative('SELECT completed_at, failed_at FROM marketplace_day_load WHERE company_id = ? AND marketplace_account_id = ? AND period = ?', [$this->companyId, $this->accountId, '2026-08-01']);
        self::assertIsArray($mark);

        return $mark;
    }
}
