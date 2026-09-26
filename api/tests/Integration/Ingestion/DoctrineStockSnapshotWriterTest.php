<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\StockSnapshotFact;
use App\Ingestion\Infrastructure\Persistence\DoctrineStockSnapshotWriter;
use App\Tests\Support\Builder\StockSnapshotFactBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Замена снимка дня (ADR-034, CLAUDE.md §6 — снимочный факт):
 * идемпотентность, порядок прогонов, исчезнувшие строки, изоляция.
 */
final class DoctrineStockSnapshotWriterTest extends KernelTestCase
{
    private Connection $connection;
    private DoctrineStockSnapshotWriter $writer;
    private Uuid $companyId;
    private Uuid $accountId;
    private \DateTimeImmutable $day;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $this->connection = $connection;
        $this->writer = new DoctrineStockSnapshotWriter($connection);
        $this->companyId = Uuid::v7();
        $this->accountId = Uuid::v7();
        $this->day = new \DateTimeImmutable('2026-09-26');
    }

    public function testFirstRunWritesTheDayAndItsMark(): void
    {
        $raw = Uuid::v7();

        self::assertTrue($this->replace('2026-09-26 00:10:00', ['1', '2'], [$raw], [
            $this->fact('1', 10),
            $this->fact('2', 20),
        ]));

        self::assertSame(['1' => 10, '2' => 20], $this->available());
        $mark = $this->mark();
        self::assertSame('2026-09-26 00:10:00', $mark['started_at']);
        self::assertSame('2026-09-26 00:10:00', $mark['first_started_at']);
        self::assertSame(['1', '2'], json_decode($this->string($mark['requested_skus']), true));
        self::assertSame([$raw->toRfc4122()], json_decode($this->string($mark['raw_document_ids']), true));
        self::assertSame(2, $mark['row_count']);
    }

    public function testRepeatingTheSameRunChangesNothing(): void
    {
        $this->replace('2026-09-26 00:10:00', ['1'], [], [$this->fact('1', 10)]);

        // Повторная запись того же прогона — no-op, а не второй набор строк.
        self::assertFalse($this->replace('2026-09-26 00:10:00', ['1'], [], [$this->fact('1', 99)]));

        self::assertSame(['1' => 10], $this->available());
    }

    public function testNewerRunReplacesTheDayAndDropsVanishedRows(): void
    {
        $this->replace('2026-09-26 00:10:00', ['1', '2'], [], [$this->fact('1', 10), $this->fact('2', 20)]);

        // SKU 2 кончился: площадка строку не отдаёт, и она обязана уйти,
        // а не остаться от утреннего снимка.
        self::assertTrue($this->replace('2026-09-26 12:00:00', ['1', '2'], [], [$this->fact('1', 7)]));

        self::assertSame(['1' => 7], $this->available());
        $mark = $this->mark();
        self::assertSame('2026-09-26 12:00:00', $mark['started_at']);
        // Первый полный снимок дня не переписывается.
        self::assertSame('2026-09-26 00:10:00', $mark['first_started_at']);
        self::assertSame(1, $mark['row_count']);
    }

    public function testStaleRunProcessedLaterDoesNotOverwriteAFresherOne(): void
    {
        $this->replace('2026-09-26 12:00:00', ['1'], [], [$this->fact('1', 7)]);

        // Утренний прогон дообработался после дневного (повтор очереди).
        self::assertFalse($this->replace('2026-09-26 00:10:00', ['1'], [], [$this->fact('1', 10)]));

        self::assertSame(['1' => 7], $this->available());
        self::assertSame('2026-09-26 12:00:00', $this->mark()['started_at']);
    }

    public function testOtherCompanyAndOtherDayAreUntouched(): void
    {
        $other = Uuid::v7();
        $this->writer->replaceDay($other->toRfc4122(), $this->accountId, $this->day, new \DateTimeImmutable('2026-09-26 00:10:00'), ['1'], [], [
            StockSnapshotFactBuilder::aStockSnapshotFact()->withCompanyId($other)->withMarketplaceAccountId($this->accountId)
                ->withSnapshotDate($this->day)->withSku('1')->withAvailable(50)->build(),
        ]);
        $this->writer->replaceDay($this->companyId->toRfc4122(), $this->accountId, new \DateTimeImmutable('2026-09-25'), new \DateTimeImmutable('2026-09-25 00:10:00'), ['1'], [], [
            StockSnapshotFactBuilder::aStockSnapshotFact()->withCompanyId($this->companyId)->withMarketplaceAccountId($this->accountId)
                ->withSnapshotDate(new \DateTimeImmutable('2026-09-25'))->withSku('1')->withAvailable(40)->build(),
        ]);

        $this->replace('2026-09-26 00:10:00', ['1'], [], []);

        self::assertSame([], $this->available());
        $count = static fn (Connection $c, string $company, string $day): mixed => $c->fetchOne(
            'SELECT COUNT(*) FROM stock_snapshot_fact WHERE company_id = ? AND snapshot_date = ?',
            [$company, $day],
        );
        self::assertSame(1, $count($this->connection, $other->toRfc4122(), '2026-09-26'));
        self::assertSame(1, $count($this->connection, $this->companyId->toRfc4122(), '2026-09-25'));
    }

    public function testFactOfAnotherCompanyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->replace('2026-09-26 00:10:00', ['1'], [], [
            StockSnapshotFactBuilder::aStockSnapshotFact()->withMarketplaceAccountId($this->accountId)->withSnapshotDate($this->day)->build(),
        ]);
    }

    /**
     * @param list<string>            $skus
     * @param list<Uuid>              $raws
     * @param list<StockSnapshotFact> $facts
     */
    private function replace(string $startedAt, array $skus, array $raws, array $facts): bool
    {
        return $this->writer->replaceDay(
            $this->companyId->toRfc4122(),
            $this->accountId,
            $this->day,
            new \DateTimeImmutable($startedAt, new \DateTimeZone('UTC')),
            $skus,
            $raws,
            $facts,
        );
    }

    private function fact(string $sku, int $available): StockSnapshotFact
    {
        return StockSnapshotFactBuilder::aStockSnapshotFact()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withSnapshotDate($this->day)
            ->withSku($sku)
            ->withAvailable($available)
            ->build();
    }

    /**
     * @return array<string, int>
     */
    private function available(): array
    {
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT marketplace_sku, available FROM stock_snapshot_fact
             WHERE company_id = ? AND marketplace_account_id = ? AND snapshot_date = ? ORDER BY marketplace_sku',
            [$this->companyId->toRfc4122(), $this->accountId->toRfc4122(), '2026-09-26'],
        );
        $result = [];
        foreach ($rows as $sku => $available) {
            self::assertIsInt($available);
            $result[(string) $sku] = $available;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function mark(): array
    {
        $mark = $this->connection->fetchAssociative(
            'SELECT * FROM stock_snapshot_run WHERE company_id = ? AND marketplace_account_id = ? AND snapshot_date = ?',
            [$this->companyId->toRfc4122(), $this->accountId->toRfc4122(), '2026-09-26'],
        );
        self::assertIsArray($mark);

        return $mark;
    }

    private function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
