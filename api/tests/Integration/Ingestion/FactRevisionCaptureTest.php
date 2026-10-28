<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\DayLoadMark;
use App\Ingestion\Domain\FactCaptureOrigin;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceDayLoadWriter;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceReturnFactWriter;
use App\Ingestion\Infrastructure\Persistence\DoctrineSalesFactWriter;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\MarketplaceDayLoadBuilder;
use App\Tests\Support\Builder\MarketplaceDayLoadReceiptBuilder;
use App\Tests\Support\Builder\MarketplaceReturnFactBuilder;
use App\Tests\Support\Builder\MarketplaceReturnFactRevisionBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use App\Tests\Support\Builder\SalesFactRevisionBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class FactRevisionCaptureTest extends KernelTestCase
{
    public function testSalesAcceptedNoopAndSameRawAbaStates(): void
    {
        $db = $this->db();
        $clock = new MockClock('2026-10-28 12:00:00 UTC');
        $writer = new DoctrineSalesFactWriter($db, $clock);
        $company = Uuid::v7();
        $account = Uuid::v7();
        $base = SalesFactBuilder::aSalesFact()->withCompanyId($company)->withMarketplaceAccountId($account);
        $a = $base->withQuantity(1)->build();
        $b = $base->withQuantity(3)->withAmount(Money::ofMinor(321, 'RUB'))->build();
        $writer->upsertAll([$a], FactCaptureOrigin::LivePolling);
        $writer->upsertAll([$a], FactCaptureOrigin::LivePolling);
        $writer->upsertAll([$b], FactCaptureOrigin::LivePolling);
        $writer->upsertAll([$a], FactCaptureOrigin::LivePolling);

        $rows = $this->revisions('sales_fact_revision', $company);
        self::assertSame([1, 2, 3], array_column($rows, 'fact_revision'));
        self::assertSame([1, 3, 1], array_column($rows, 'quantity'));
        self::assertSame([true, false, false], array_column($rows, 'capture_is_initial'));
        self::assertSame(array_fill(0, 3, $a->rawDocumentId()->toRfc4122()), array_column($rows, 'raw_document_id'));
        self::assertSame('2026-10-28 12:00:00', $rows[0]['captured_at']);
        foreach ($rows as $row) {
            self::assertIsString($row['id']);
            self::assertInstanceOf(UuidV7::class, Uuid::fromString($row['id']));
            self::assertSame('accepted_state_v1', $row['capture_version']);
        }
        self::assertSame(3, $db->fetchOne('SELECT fact_revision FROM sales_fact WHERE company_id = ?', [$company->toRfc4122()]));
    }

    public function testLegacyUnknownReplayAndBackfillCannotClaimInitialObservation(): void
    {
        $db = $this->db();
        $writer = new DoctrineSalesFactWriter($db);
        foreach ([FactCaptureOrigin::Unknown, FactCaptureOrigin::HistoricalBackfill, FactCaptureOrigin::RawReplay] as $origin) {
            $company = Uuid::v7();
            $base = SalesFactBuilder::aSalesFact()->withCompanyId($company);
            SalesFactRevisionBuilder::aSalesFactRevision()->withFact($base)->withOrigin($origin)->persistWith($writer);
            $rows = $this->revisions('sales_fact_revision', $company);
            self::assertFalse($rows[0]['capture_is_initial']);
            self::assertSame($origin->value, $rows[0]['capture_origin']);
        }
        $company = Uuid::v7();
        $base = SalesFactBuilder::aSalesFact()->withCompanyId($company);
        $writer->upsertAll([$base->build()]);
        // Synthetic legacy state: migration sees a current fact and no history.
        $db->executeStatement('DELETE FROM sales_fact_revision WHERE company_id = ?', [$company->toRfc4122()]);
        $db->executeStatement('UPDATE sales_fact SET fact_revision = 0, capture_initial = false WHERE company_id = ?', [$company->toRfc4122()]);
        $writer->upsertAll([$base->withQuantity(2)->build()], FactCaptureOrigin::LivePolling);
        $rows = $this->revisions('sales_fact_revision', $company);
        self::assertSame(1, $rows[0]['fact_revision']);
        self::assertFalse($rows[0]['capture_is_initial']);
    }

    public function testBackfillCapturesReturnedStateAndKeepsBusinessClocks(): void
    {
        $db = $this->db();
        $writer = new DoctrineSalesFactWriter($db);
        $company = Uuid::v7();
        $base = SalesFactBuilder::aSalesFact()->withCompanyId($company);
        SalesFactRevisionBuilder::aSalesFactRevision()
            ->withFact($base->withOrderedAt(null)->withPostingNumber(null))
            ->withOrigin(FactCaptureOrigin::LivePolling)
            ->persistWith($writer);
        $before = $db->fetchAssociative('SELECT first_loaded_at, last_updated_at FROM sales_fact WHERE company_id = ?', [$company->toRfc4122()]);
        self::assertIsArray($before);
        $writer->backfillLinks($company->toRfc4122(), [$base->withQuantity(9)->withAmount(Money::ofMinor(17, 'RUB'))->build()]);
        $writer->backfillLinks($company->toRfc4122(), [$base->withQuantity(9)->withAmount(Money::ofMinor(17, 'RUB'))->build()]);
        $rows = $this->revisions('sales_fact_revision', $company);
        self::assertSame([1, 2], array_column($rows, 'fact_revision'));
        self::assertSame(1, $rows[1]['quantity']);
        self::assertSame(216000, $rows[1]['amount_minor']);
        self::assertSame('historical_backfill', $rows[1]['capture_origin']);
        self::assertSame('backfill_links', $rows[1]['capture_path']);
        self::assertFalse($rows[1]['capture_is_initial']);
        self::assertSame($before['first_loaded_at'], $rows[1]['first_loaded_at']);
        self::assertSame($before['last_updated_at'], $rows[1]['last_updated_at']);
        self::assertNotNull($rows[1]['ordered_at']);
        self::assertNotNull($rows[1]['posting_number']);
        $other = Uuid::v7();
        $writer->backfillLinks($other->toRfc4122(), [$base->withCompanyId($other)->build()]);
        self::assertFalse($this->revisions('sales_fact_revision', $other)[0]['capture_is_initial']);
    }

    public function testReturnFreshnessNoopSameRawRepairAndAba(): void
    {
        $db = $this->db();
        $writer = new DoctrineMarketplaceReturnFactWriter($db, new MockClock('2026-10-28 12:00:00 UTC'));
        $company = Uuid::v7();
        $base = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()->withCompanyId($company)->withReturnType('ClientReturn');
        $a = MarketplaceReturnFactRevisionBuilder::aMarketplaceReturnFactRevision()->withFact($base)->withOrigin(FactCaptureOrigin::LivePolling)->persistWith($writer);
        $writer->upsertAll([$a], FactCaptureOrigin::LivePolling);
        $writer->upsertAll([$base->withQuantity(2)->build()], FactCaptureOrigin::RawReplay);
        $writer->upsertAll([$a], FactCaptureOrigin::LivePolling);
        $writer->upsertAll([$base->withRawDocumentId(Uuid::v7())->withVisualStatus(1, 'Completed', new \DateTimeImmutable('2020-01-01'))->withQuantity(8)->build()], FactCaptureOrigin::LivePolling);
        $rows = $this->revisions('marketplace_return_fact_revision', $company);
        self::assertSame([1, 2, 3], array_column($rows, 'fact_revision'));
        self::assertSame([1, 2, 1], array_column($rows, 'quantity'));
        self::assertSame([true, false, false], array_column($rows, 'capture_is_initial'));
        self::assertSame('ClientReturn', $rows[0]['return_type']);
    }

    public function testReceiptsIncludeEmptyLoadsAndDedupWithoutSuppressingCurrentMarks(): void
    {
        $db = $this->db();
        $writer = new DoctrineMarketplaceDayLoadWriter($db);
        $company = Uuid::v7();
        $account = Uuid::v7()->toRfc4122();
        $from = new \DateTimeImmutable('2026-10-01');
        $to = new \DateTimeImmutable('2026-10-03');
        $at = new \DateTimeImmutable('2026-10-28 12:00:00 UTC');
        $success = DayLoadMark::completed('ozon_returns_list', $from, $to);
        $failure = DayLoadMark::failed('ozon_returns_list', $from, $to, 'private raw failure must not enter receipt');
        foreach ([$success, $failure, $success] as $mark) {
            $writer->mark($company->toRfc4122(), $account, [$mark], $at);
        }
        $rows = $db->fetchAllAssociative('SELECT id, date_from, date_to, marked_at, status FROM marketplace_day_load_receipt WHERE company_id = ? ORDER BY status', [$company->toRfc4122()]);
        self::assertCount(2, $rows);
        self::assertSame(['completed', 'failed'], array_column($rows, 'status'));
        self::assertSame('2026-10-01', $rows[0]['date_from']);
        self::assertIsString($rows[0]['id']);
        self::assertInstanceOf(UuidV7::class, Uuid::fromString($rows[0]['id']));
        $days = $db->fetchAllAssociative('SELECT period, failed_at, failure FROM marketplace_day_load WHERE company_id = ? ORDER BY period', [$company->toRfc4122()]);
        self::assertCount(3, $days);
        self::assertSame([null, null, null], array_column($days, 'failed_at'));
        self::assertSame([null, null, null], array_column($days, 'failure'));
    }

    public function testLedgerFailureAtomicallyRollsBackBothFactWriters(): void
    {
        $db = $this->db();
        foreach (['sales', 'returns'] as $kind) {
            $company = Uuid::v7();
            $base = 'sales' === $kind ? SalesFactBuilder::aSalesFact()->withCompanyId($company) : MarketplaceReturnFactBuilder::aMarketplaceReturnFact()->withCompanyId($company);
            $writer = 'sales' === $kind ? new DoctrineSalesFactWriter($db) : new DoctrineMarketplaceReturnFactWriter($db);
            $table = 'sales' === $kind ? 'sales_fact' : 'marketplace_return_fact';
            $writer->upsertAll([$base->build()], FactCaptureOrigin::LivePolling);
            $this->rejectNextHistoryWrite($table.'_revision', static function () use ($writer, $base): void {
                $writer->upsertAll([$base->withQuantity(7)->build()], FactCaptureOrigin::LivePolling);
            });
            self::assertSame(1, $db->fetchOne('SELECT quantity FROM '.$table.' WHERE company_id = ?', [$company->toRfc4122()]));
            self::assertSame([1], array_column($this->revisions($table.'_revision', $company), 'fact_revision'));
        }
    }

    public function testReceiptFailureAtomicallyRollsBackCurrentDayMark(): void
    {
        $db = $this->db();
        $writer = new DoctrineMarketplaceDayLoadWriter($db);
        $company = Uuid::v7()->toRfc4122();
        $account = Uuid::v7()->toRfc4122();
        $day = new \DateTimeImmutable('2026-10-28');
        MarketplaceDayLoadReceiptBuilder::aMarketplaceDayLoadReceipt()
            ->withLoad(MarketplaceDayLoadBuilder::aMarketplaceDayLoad()->withCompanyId(Uuid::fromString($company))->withMarketplaceAccountId(Uuid::fromString($account))->withReportType('test')->withPeriod($day)->withAt($day))
            ->persistWith($writer);
        $this->rejectNextHistoryWrite('marketplace_day_load_receipt', static function () use ($writer, $company, $account, $day): void {
            $writer->mark($company, $account, [DayLoadMark::failed('test', $day, $day, 'failure')], $day->modify('+1 day'));
        });
        self::assertNull($db->fetchOne('SELECT failed_at FROM marketplace_day_load WHERE company_id = ?', [$company]));
        self::assertSame(['completed'], $db->fetchFirstColumn('SELECT status FROM marketplace_day_load_receipt WHERE company_id = ?', [$company]));
    }

    public function testOuterTransactionRollbackRemovesEveryAcceptedStateAndReceipt(): void
    {
        $db = $this->db();
        $company = Uuid::v7();
        $account = Uuid::v7();
        $db->beginTransaction();
        try {
            SalesFactRevisionBuilder::aSalesFactRevision()->withFact(SalesFactBuilder::aSalesFact()->withCompanyId($company)->withMarketplaceAccountId($account))->persistWith(new DoctrineSalesFactWriter($db));
            MarketplaceReturnFactRevisionBuilder::aMarketplaceReturnFactRevision()->withFact(MarketplaceReturnFactBuilder::aMarketplaceReturnFact()->withCompanyId($company)->withMarketplaceAccountId($account))->persistWith(new DoctrineMarketplaceReturnFactWriter($db));
            $day = new \DateTimeImmutable('2026-10-28');
            MarketplaceDayLoadReceiptBuilder::aMarketplaceDayLoadReceipt()
                ->withLoad(MarketplaceDayLoadBuilder::aMarketplaceDayLoad()->withCompanyId($company)->withMarketplaceAccountId($account)->withReportType('test')->withPeriod($day)->withAt($day))
                ->persistWith(new DoctrineMarketplaceDayLoadWriter($db));
        } finally {
            $db->rollBack();
        }
        foreach (['sales_fact', 'sales_fact_revision', 'marketplace_return_fact', 'marketplace_return_fact_revision', 'marketplace_day_load', 'marketplace_day_load_receipt'] as $table) {
            self::assertFalse($db->fetchOne('SELECT 1 FROM '.$table.' WHERE company_id = ? LIMIT 1', [$company->toRfc4122()]));
        }
    }

    public function testEmptyReceiptProvenanceCannotBeInferredOrUpgradedByRetry(): void
    {
        $db = $this->db();
        $writer = new DoctrineMarketplaceDayLoadWriter($db);
        $day = new \DateTimeImmutable('2026-10-28');
        foreach ([FactCaptureOrigin::LivePolling, FactCaptureOrigin::HistoricalBackfill, FactCaptureOrigin::RawReplay, FactCaptureOrigin::Unknown] as $origin) {
            $company = Uuid::v7()->toRfc4122();
            $account = Uuid::v7()->toRfc4122();
            $marks = [DayLoadMark::completed('returns', $day, $day)];
            MarketplaceDayLoadReceiptBuilder::aMarketplaceDayLoadReceipt()
                ->withLoad(MarketplaceDayLoadBuilder::aMarketplaceDayLoad()->withCompanyId(Uuid::fromString($company))->withMarketplaceAccountId(Uuid::fromString($account))->withReportType('returns')->withPeriod($day)->withAt($day))
                ->withOrigin($origin)
                ->persistWith($writer);
            self::assertSame($origin->value, $db->fetchOne('SELECT capture_origin FROM marketplace_day_load_receipt WHERE company_id = ?', [$company]));
            $writer->mark($company, $account, $marks, $day, FactCaptureOrigin::LivePolling);
            self::assertSame([$origin->value], $db->fetchFirstColumn('SELECT capture_origin FROM marketplace_day_load_receipt WHERE company_id = ?', [$company]));
        }
    }

    public function testCaptureKeysStayScopedAndBothWritersCaptureAcrossChunkBoundary(): void
    {
        $db = $this->db();
        foreach (['sales', 'returns'] as $kind) {
            $company = Uuid::v7();
            $otherCompany = Uuid::v7();
            $account = Uuid::v7();
            $otherAccount = Uuid::v7();
            $base = 'sales' === $kind ? SalesFactBuilder::aSalesFact() : MarketplaceReturnFactBuilder::aMarketplaceReturnFact();
            $writer = 'sales' === $kind ? new DoctrineSalesFactWriter($db) : new DoctrineMarketplaceReturnFactWriter($db);
            $table = 'sales' === $kind ? 'sales_fact_revision' : 'marketplace_return_fact_revision';
            $facts = [];
            for ($index = 0; $index < 501; ++$index) {
                $facts[] = $base->withCompanyId($company)->withMarketplaceAccountId($account)->withSourceRowId('B02-CHUNK-'.$index)->build();
            }
            $facts[] = $base->withCompanyId($otherCompany)->withMarketplaceAccountId($account)->withSourceRowId('B02-CHUNK-0')->withQuantity(2)->build();
            $facts[] = $base->withCompanyId($company)->withMarketplaceAccountId($otherAccount)->withSourceRowId('B02-CHUNK-0')->withQuantity(3)->build();
            $writer->upsertAll($facts, FactCaptureOrigin::LivePolling);
            $writer->upsertAll($facts, FactCaptureOrigin::LivePolling);
            $rows = $db->fetchAllAssociative('SELECT fact_revision, quantity FROM '.$table.' WHERE company_id = ? AND marketplace_account_id = ? ORDER BY source_row_id LIMIT 502', [$company->toRfc4122(), $account->toRfc4122()]);
            self::assertCount(501, $rows);
            self::assertSame(array_fill(0, 501, 1), array_column($rows, 'fact_revision'));
            self::assertSame(2, $db->fetchOne('SELECT quantity FROM '.$table.' WHERE company_id = ? AND marketplace_account_id = ?', [$otherCompany->toRfc4122(), $account->toRfc4122()]));
            self::assertSame(3, $db->fetchOne('SELECT quantity FROM '.$table.' WHERE company_id = ? AND marketplace_account_id = ?', [$company->toRfc4122(), $otherAccount->toRfc4122()]));
        }
    }

    public function testReturnLegacyUpdateAndReplayInsertAreNotInitialCapture(): void
    {
        $db = $this->db();
        $writer = new DoctrineMarketplaceReturnFactWriter($db);
        $company = Uuid::v7();
        $base = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()->withCompanyId($company);
        MarketplaceReturnFactRevisionBuilder::aMarketplaceReturnFactRevision()->withFact($base)->withOrigin(FactCaptureOrigin::RawReplay)->persistWith($writer);
        self::assertFalse($this->revisions('marketplace_return_fact_revision', $company)[0]['capture_is_initial']);
        $db->executeStatement('DELETE FROM marketplace_return_fact_revision WHERE company_id = ?', [$company->toRfc4122()]);
        $db->executeStatement('UPDATE marketplace_return_fact SET fact_revision = 0, capture_initial = false WHERE company_id = ?', [$company->toRfc4122()]);
        $writer->upsertAll([$base->withQuantity(2)->build()], FactCaptureOrigin::LivePolling);
        $rows = $this->revisions('marketplace_return_fact_revision', $company);
        self::assertSame([1], array_column($rows, 'fact_revision'));
        self::assertFalse($rows[0]['capture_is_initial']);
    }

    private function rejectNextHistoryWrite(string $table, \Closure $write): void
    {
        $db = $this->db();
        $db->executeStatement("CREATE FUNCTION pg_temp.b02_reject_capture() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''synthetic capture failure''; END;'");
        $db->executeStatement('CREATE TRIGGER b02_capture_failure BEFORE INSERT ON '.$table.' FOR EACH ROW EXECUTE FUNCTION pg_temp.b02_reject_capture()');
        $db->beginTransaction();
        try {
            $write();
            self::fail('History failure must reject the mutable write.');
        } catch (\Doctrine\DBAL\Exception $failure) {
            self::assertStringContainsString('synthetic capture failure', $failure->getMessage());
        } finally {
            $db->rollBack();
            $db->executeStatement('DROP TRIGGER b02_capture_failure ON '.$table);
            $db->executeStatement('DROP FUNCTION pg_temp.b02_reject_capture()');
        }
    }

    /** @return list<array<string, mixed>> */
    private function revisions(string $table, Uuid $company): array
    {
        $columns = 'fact_revision, id, quantity, capture_is_initial, capture_version, capture_origin, capture_path, captured_at, raw_document_id, first_loaded_at, last_updated_at';
        $columns .= 'sales_fact_revision' === $table ? ', amount_minor, ordered_at, posting_number' : ', return_type';

        return $this->db()->fetchAllAssociative('SELECT '.$columns.' FROM '.$table.' WHERE company_id = ? ORDER BY fact_revision', [$company->toRfc4122()]);
    }

    private function db(): Connection
    {
        if (!self::$booted) {
            self::bootKernel();
        }
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);

        return $db;
    }
}
