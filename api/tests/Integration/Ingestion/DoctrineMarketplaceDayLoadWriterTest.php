<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\DayLoadMark;
use App\Ingestion\Domain\MarketplaceDayLoadRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Запись отметок загрузки дня (ADR-038): диапазон разворачивается в дни
 * одним запросом, успех стирает отказ, отказ сохраняет время успеха,
 * отметки одной компании и кабинета не трогают другие.
 */
final class DoctrineMarketplaceDayLoadWriterTest extends KernelTestCase
{
    public function testRangeMarksEveryDayOfItsCompanyAndCabinetOnly(): void
    {
        $writer = $this->writer();
        $company = Uuid::v7()->toRfc4122();
        $account = Uuid::v7()->toRfc4122();
        $otherCompany = Uuid::v7()->toRfc4122();
        $otherAccount = Uuid::v7()->toRfc4122();

        // Тот же кабинет у чужой компании и другой кабинет той же — с успехом.
        $writer->mark($otherCompany, $account, [DayLoadMark::completed(MarketplaceReportType::OzonReturnsList, self::day('2026-08-01'), self::day('2026-08-03'))], new \DateTimeImmutable());
        $writer->mark($company, $otherAccount, [DayLoadMark::completed(MarketplaceReportType::OzonReturnsList, self::day('2026-08-01'), self::day('2026-08-03'))], new \DateTimeImmutable());

        // Отказ и успех разных типов — одним вызовом.
        $writer->mark($company, $account, [
            DayLoadMark::failed(MarketplaceReportType::OzonReturnsList, self::day('2026-08-01'), self::day('2026-08-03'), 'сеть'),
            DayLoadMark::completed(MarketplaceReportType::OzonAdExpense, self::day('2026-08-02'), self::day('2026-08-02')),
        ], new \DateTimeImmutable());

        self::assertSame([
            [MarketplaceReportType::OzonAdExpense, '2026-08-02', 'ok'],
            [MarketplaceReportType::OzonReturnsList, '2026-08-01', 'failed'],
            [MarketplaceReportType::OzonReturnsList, '2026-08-02', 'failed'],
            [MarketplaceReportType::OzonReturnsList, '2026-08-03', 'failed'],
        ], $this->marks($company, $account));
        // Чужие отметки не тронуты.
        self::assertSame(['ok', 'ok', 'ok'], array_column($this->marks($otherCompany, $account), 2));
        self::assertSame(['ok', 'ok', 'ok'], array_column($this->marks($company, $otherAccount), 2));
    }

    public function testSuccessClearsTheFailureAndFailureKeepsTheLastSuccessTime(): void
    {
        $writer = $this->writer();
        $company = Uuid::v7()->toRfc4122();
        $account = Uuid::v7()->toRfc4122();
        $day = self::day('2026-08-05');

        $writer->mark($company, $account, [DayLoadMark::completed(MarketplaceReportType::OzonAccrualByDay, $day, $day)], new \DateTimeImmutable('2026-08-06 03:00:00'));
        $writer->mark($company, $account, [DayLoadMark::failed(MarketplaceReportType::OzonAccrualByDay, $day, $day, 'разбор')], new \DateTimeImmutable('2026-08-07 03:00:00'));

        $row = $this->row($company, $account);
        self::assertSame('2026-08-06 03:00:00', $row['completed_at']);
        self::assertNotNull($row['failed_at']);
        self::assertSame('разбор', $row['failure']);

        $writer->mark($company, $account, [DayLoadMark::completed(MarketplaceReportType::OzonAccrualByDay, $day, $day)], new \DateTimeImmutable('2026-08-08 03:00:00'));

        $row = $this->row($company, $account);
        self::assertSame('2026-08-08 03:00:00', $row['completed_at']);
        self::assertNull($row['failed_at']);
        self::assertNull($row['failure']);
    }

    public function testRangeLongerThanAYearOrEmptyIsRefused(): void
    {
        $writer = $this->writer();
        $company = Uuid::v7()->toRfc4122();
        $account = Uuid::v7()->toRfc4122();

        foreach ([['2025-01-01', '2026-01-02'], ['2026-08-02', '2026-08-01']] as [$from, $to]) {
            try {
                $writer->mark($company, $account, [DayLoadMark::completed(MarketplaceReportType::OzonReturnsList, self::day($from), self::day($to))], new \DateTimeImmutable());
                self::fail("Диапазон {$from}..{$to} должен быть отклонён.");
            } catch (\InvalidArgumentException) {
            }
        }

        // Ровно 366 дней — високосный год целиком — допустим.
        $writer->mark($company, $account, [DayLoadMark::completed(MarketplaceReportType::OzonReturnsList, self::day('2024-01-01'), self::day('2024-12-31'))], new \DateTimeImmutable());
        self::assertCount(366, $this->marks($company, $account));
    }

    public function testOverlappingMarksOfOneTypeAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->writer()->mark(Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), [
            DayLoadMark::completed(MarketplaceReportType::OzonAdSkuDay, self::day('2026-08-01'), self::day('2026-08-02')),
            DayLoadMark::failed(MarketplaceReportType::OzonAdSkuDay, self::day('2026-08-02'), self::day('2026-08-02'), 'разбор'),
        ], new \DateTimeImmutable());
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function marks(string $company, string $account): array
    {
        /** @var list<array{report_type: string, period: string, failed: bool}> $rows */
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT report_type, period, failed_at IS NOT NULL AS failed FROM marketplace_day_load WHERE company_id = ? AND marketplace_account_id = ? ORDER BY report_type, period',
            [$company, $account],
        );

        return array_map(static fn (array $row): array => [$row['report_type'], $row['period'], $row['failed'] ? 'failed' : 'ok'], $rows);
    }

    /**
     * @return array{completed_at: ?string, failed_at: ?string, failure: ?string}
     */
    private function row(string $company, string $account): array
    {
        /** @var array{completed_at: ?string, failed_at: ?string, failure: ?string}|false $row */
        $row = $this->connection()->fetchAssociative(
            'SELECT completed_at, failed_at, failure FROM marketplace_day_load WHERE company_id = ? AND marketplace_account_id = ?',
            [$company, $account],
        );
        self::assertIsArray($row);

        return $row;
    }

    private static function day(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('Europe/Moscow'));
    }

    private function writer(): MarketplaceDayLoadRepository
    {
        self::bootKernel();
        $writer = self::getContainer()->get(MarketplaceDayLoadRepository::class);
        self::assertInstanceOf(MarketplaceDayLoadRepository::class, $writer);

        return $writer;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
