<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\Facade\IngestionPlanningFacade;
use App\Ingestion\Application\Facade\PlanningObservationDateAxis;
use App\Ingestion\Domain\MarketplacePostingStatusRepository;
use App\Ingestion\Domain\MarketplaceReturnFactRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Query\PlanningCohortProvenanceQuery;
use App\Ingestion\Infrastructure\Query\PlanningMarketplaceSkusQuery;
use App\Ingestion\Infrastructure\Query\PlanningOrderCohortsQuery;
use App\Ingestion\Infrastructure\Query\PlanningOutcomeQueryGuard;
use App\Ingestion\Infrastructure\Query\PlanningResolutionsQuery;
use App\Ingestion\Infrastructure\Query\PlanningSourceStateQuery;
use App\Ingestion\Infrastructure\Repository\PlanningSourceStateWriter;
use App\Tests\Support\Builder\MarketplacePostingStatusBuilder;
use App\Tests\Support\Builder\MarketplaceReturnFactBuilder;
use App\Tests\Support\Builder\PlanningIngestionAccountStateBuilder;
use App\Tests\Support\Builder\PlanningIngestionDayCoverageBuilder;
use App\Tests\Support\Builder\PlanningIngestionResolutionObservationBuilder;
use App\Tests\Support\Builder\PlanningIngestionSourceRowRunBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class IngestionPlanningFacadeTest extends KernelTestCase
{
    public function testUndatedCurrentBuyoutMakesItsSkuWindowIncomplete(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $company = $companyId->toRfc4122();
        $account = $accountId->toRfc4122();
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow'));
        $yesterday = $today->modify('-1 day');
        $day = $today->format('Y-m-d');
        self::seedCompletedBaseline($companyId, $accountId, $yesterday->setTime(10, 0));

        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([SalesFactBuilder::aSalesFact()->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)->withSourceRowId('UNKNOWN-DATE|SKU')
            ->withPostingNumber('UNKNOWN-DATE')->withOrderNumber('UNKNOWN-DATE')
            ->withMarketplaceSku('SKU')->withBusinessDate($yesterday)->build(),
            SalesFactBuilder::aSalesFact()->withCompanyId($companyId)
                ->withMarketplaceAccountId($accountId)->withSourceRowId('UNKNOWN-NO-BUY|SKU-NO-BUY')
                ->withPostingNumber('UNKNOWN-NO-BUY')->withOrderNumber('UNKNOWN-NO-BUY')
                ->withMarketplaceSku('SKU-NO-BUY')->withBusinessDate($yesterday)->withStatus('cancelled')->build()]);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($company, [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withPostingNumber('UNKNOWN-DATE')->withOrderNumber('UNKNOWN-DATE')
            ->withStatus('delivered')->withObservedAt($yesterday->setTime(9, 0))->build(),
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
                ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withPostingNumber('UNKNOWN-NO-BUY')->withOrderNumber('UNKNOWN-NO-BUY')
                ->withStatus('awaiting_packaging')->withObservedAt($yesterday->setTime(8, 0))->build(),
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
                ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withPostingNumber('UNKNOWN-NO-BUY')->withOrderNumber('UNKNOWN-NO-BUY')
                ->withStatus('cancelled')->withObservedAt($yesterday->setTime(9, 0))->build()]);
        /** @var MarketplaceReturnFactRepository $returns */
        $returns = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        $returns->upsertAll([MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withSourceRowId('UNKNOWN-NO-BUY-RETURN')->withSourceId(991303)
            ->withPostingNumber('UNKNOWN-NO-BUY')->withOrderNumber('UNKNOWN-NO-BUY')
            ->withMarketplaceSku('SKU-NO-BUY')->withReturnReasonName('Покупатель отменил заказ')->build()]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection));
        $writer->recordCompleted($company, $account, 'postings', $day, $day, hash('sha256', 'unknown-rescan'), 'rescan', [], ['UNKNOWN-DATE|SKU', 'UNKNOWN-NO-BUY|SKU-NO-BUY']);
        $writer->recordCompleted($company, $account, 'postings', $day, $day, hash('sha256', 'unknown-regular'), 'regular', [], ['UNKNOWN-DATE|SKU', 'UNKNOWN-NO-BUY|SKU-NO-BUY'], regularWindowFrom: $day, regularWindowTo: $day);
        $writer->recordCompleted($company, $account, 'returns', $day, $day, hash('sha256', 'unknown-returns'), 'regular', [], regularWindowFrom: $day, regularWindowTo: $day);
        self::assertTrue((new PlanningSourceStateQuery($connection))->observationCoverage($company, $account, $today, $today)['complete']);
        self::assertNull($connection->fetchOne('SELECT first_regularly_observed_at FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND outcome = ?', [$company, $account, 'UNKNOWN-DATE|SKU', 'D']));
        self::assertSame('T1', $connection->fetchOne('SELECT outcome FROM buyout_outcome WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?', [$company, $account, 'UNKNOWN-NO-BUY|SKU-NO-BUY']));
        self::assertNull($connection->fetchOne('SELECT first_regularly_observed_at FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND outcome = ?', [$company, $account, 'UNKNOWN-NO-BUY|SKU-NO-BUY', 'T1']));

        $planning = new IngestionPlanningFacade(new PlanningOrderCohortsQuery($connection), new PlanningCohortProvenanceQuery($connection), new PlanningSourceStateQuery($connection), new PlanningResolutionsQuery($connection), new PlanningMarketplaceSkusQuery($connection), new PlanningOutcomeQueryGuard($connection), 'test-secret');
        self::assertFalse($planning->planningResolutionTotals($company, $account, ['SKU'], $today, $today, PlanningObservationDateAxis::FIRST_REGULAR_OBSERVATION)->complete);
        self::assertFalse($planning->planningResolutionObservations($company, $account, ['SKU'], $today, $today, PlanningObservationDateAxis::FIRST_KNOWN_OUTCOME)->complete);
        self::assertTrue($planning->planningResolutionTotals($company, $account, ['SKU-NO-BUY'], $today, $today, PlanningObservationDateAxis::FIRST_REGULAR_OBSERVATION)->complete);
        self::assertFalse($planning->planningResolutionTotals($company, $account, ['SKU-NO-BUY'], $today, $today, PlanningObservationDateAxis::FIRST_KNOWN_OUTCOME)->complete);
        self::assertTrue($planning->planningResolutionTotals($company, $account, ['OTHER-SKU'], $today, $today, PlanningObservationDateAxis::FIRST_REGULAR_OBSERVATION)->complete);
    }

    public function testRegularNonBuyHasKnownOutcomeButNoBuyoutVelocityDate(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $company = $companyId->toRfc4122();
        $account = $accountId->toRfc4122();
        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([
            SalesFactBuilder::aSalesFact()->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withSourceRowId('NO-BUY|SKU')->withPostingNumber('NO-BUY')->withOrderNumber('NO-BUY')
                ->withMarketplaceSku('SKU')->withStatus('cancelled')->build(),
        ]);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($company, [
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()->withCompanyId($companyId)
                ->withMarketplaceAccountId($accountId)->withPostingNumber('NO-BUY')->withOrderNumber('NO-BUY')
                ->withStatus('awaiting_packaging')->withObservedAt(new \DateTimeImmutable('2026-09-19 10:00:00'))->build(),
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()->withCompanyId($companyId)
                ->withMarketplaceAccountId($accountId)->withPostingNumber('NO-BUY')->withOrderNumber('NO-BUY')
                ->withStatus('cancelled')->withObservedAt(new \DateTimeImmutable('2026-09-20 10:00:00'))->build(),
        ]);
        /** @var MarketplaceReturnFactRepository $returns */
        $returns = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        $returns->upsertAll([
            MarketplaceReturnFactBuilder::aMarketplaceReturnFact()->withCompanyId($companyId)
                ->withMarketplaceAccountId($accountId)->withSourceRowId('NO-BUY-RETURN')->withSourceId(991301)
                ->withPostingNumber('NO-BUY')->withOrderNumber('NO-BUY')->withMarketplaceSku('SKU')
                ->withReturnReasonName('Покупатель отменил заказ')->build(),
        ]);
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        self::seedCompletedBaseline($companyId, $accountId);
        (new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection)))->recordCompleted(
            $company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'non-buy'),
            'regular', [], ['NO-BUY|SKU'], affectedOrderNumbers: ['NO-BUY'],
        );
        $observation = $connection->fetchAssociative(
            'SELECT outcome, first_known_outcome_at, first_regularly_observed_at FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            [$company, $account, 'NO-BUY|SKU'],
        );
        self::assertNotFalse($observation);
        self::assertSame('T1', $observation['outcome']);
        self::assertNotNull($observation['first_known_outcome_at']);
        self::assertNull($observation['first_regularly_observed_at']);
    }

    public function testChangedSiblingRefreshesOutcomeOfAnUnfetchedSourceRow(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $company = $companyId->toRfc4122();
        $account = $accountId->toRfc4122();
        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([
            SalesFactBuilder::aSalesFact()->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withSourceRowId('SIBLING-A|SKU-A')->withPostingNumber('SIBLING-A')->withOrderNumber('SIBLING-ORDER')
                ->withMarketplaceSku('SKU-A')->withStatus('cancelled')->build(),
            SalesFactBuilder::aSalesFact()->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withSourceRowId('SIBLING-B|SKU-B')->withPostingNumber('SIBLING-B')->withOrderNumber('SIBLING-ORDER')
                ->withMarketplaceSku('SKU-B')->withStatus('awaiting_packaging')->build(),
        ]);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($company, [
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()->withCompanyId($companyId)
                ->withMarketplaceAccountId($accountId)->withPostingNumber('SIBLING-A')->withOrderNumber('SIBLING-ORDER')
                ->withStatus('awaiting_packaging')->withObservedAt(new \DateTimeImmutable('2026-09-19 10:00:00'))->build(),
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()->withCompanyId($companyId)
                ->withMarketplaceAccountId($accountId)->withPostingNumber('SIBLING-A')->withOrderNumber('SIBLING-ORDER')
                ->withStatus('cancelled')->withObservedAt(new \DateTimeImmutable('2026-09-20 10:00:00'))->build(),
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()->withCompanyId($companyId)
                ->withMarketplaceAccountId($accountId)->withPostingNumber('SIBLING-B')->withOrderNumber('SIBLING-ORDER')
                ->withStatus('awaiting_packaging')->withObservedAt(new \DateTimeImmutable('2026-09-20 10:00:00'))->build(),
        ]);
        /** @var MarketplaceReturnFactRepository $returns */
        $returns = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        $returns->upsertAll([
            MarketplaceReturnFactBuilder::aMarketplaceReturnFact()->withCompanyId($companyId)
                ->withMarketplaceAccountId($accountId)->withSourceRowId('SIBLING-RETURN')->withSourceId(991201)
                ->withPostingNumber('SIBLING-A')->withOrderNumber('SIBLING-ORDER')->withMarketplaceSku('SKU-A')
                ->build(),
        ]);
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection));
        $writer->recordCompleted($company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'before sibling'), 'regular', [], ['SIBLING-A|SKU-A'], affectedOrderNumbers: ['SIBLING-ORDER']);
        self::assertFalse($connection->fetchOne('SELECT EXISTS (SELECT 1 FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?)', [$company, $account, 'SIBLING-A|SKU-A']));

        $sales->upsertAll([
            SalesFactBuilder::aSalesFact()->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withSourceRowId('SIBLING-B|SKU-B')->withPostingNumber('SIBLING-B')->withOrderNumber('SIBLING-ORDER')
                ->withMarketplaceSku('SKU-B')->withStatus('delivered')->build(),
        ]);
        $statuses->recordChanged($company, [
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()->withCompanyId($companyId)
                ->withMarketplaceAccountId($accountId)->withPostingNumber('SIBLING-B')->withOrderNumber('SIBLING-ORDER')
                ->withStatus('delivered')->withObservedAt(new \DateTimeImmutable('2026-09-20 11:00:00'))->build(),
        ]);
        $writer->recordCompleted($company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'after sibling'), 'regular', [], ['SIBLING-B|SKU-B'], affectedOrderNumbers: ['SIBLING-ORDER']);
        self::assertSame('P', $connection->fetchOne('SELECT outcome FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?', [$company, $account, 'SIBLING-A|SKU-A']));
    }

    public function testNullOrderObservationDoesNotPublishOtherHistoricalNullOrders(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $company = $companyId->toRfc4122();
        $account = $accountId->toRfc4122();
        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $facts = [];
        $statusRows = [];
        for ($index = 0; $index < 12; ++$index) {
            $posting = 'NULL-ORDER-'.$index;
            $facts[] = SalesFactBuilder::aSalesFact()->withCompanyId($companyId)
                ->withMarketplaceAccountId($accountId)->withSourceRowId($posting.'|SKU')
                ->withPostingNumber($posting)->withOrderNumber(null)->withMarketplaceSku('SKU')->build();
            $statusRows[] = MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
                ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withPostingNumber($posting)->withOrderNumber($posting)->withStatus('delivered')->build();
        }
        $sales->upsertAll($facts);
        $statuses->recordChanged($company, $statusRows);
        self::seedCompletedBaseline($companyId, $accountId);
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        (new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection)))->recordCompleted(
            $company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'one null-order row'),
            'regular', [], ['NULL-ORDER-11|SKU'], affectedOrderNumbers: [null],
        );

        self::assertSame(['NULL-ORDER-11|SKU'], $connection->fetchFirstColumn(
            'SELECT source_row_id FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? ORDER BY source_row_id',
            [$company, $account],
        ));
    }

    public function testCalendarWindowUsesMoscowBusinessDate(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $fact = SalesFactBuilder::aSalesFact()->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withSourceRowId('MOSCOW-DAY|SKU')->withPostingNumber('MOSCOW-DAY')->withOrderNumber('MOSCOW-DAY')
            ->withMarketplaceSku('SKU')->withBusinessDate(new \DateTimeImmutable('2026-09-21'))->build();
        $sales->upsertAll([$fact]);
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $planning = new IngestionPlanningFacade(new PlanningOrderCohortsQuery($connection), new PlanningCohortProvenanceQuery($connection), new PlanningSourceStateQuery($connection), new PlanningResolutionsQuery($connection), new PlanningMarketplaceSkusQuery($connection), new PlanningOutcomeQueryGuard($connection), 'test-secret');
        $instant = new \DateTimeImmutable('2026-09-20 23:30:00 UTC');
        $page = $planning->planningOrderCohorts($companyId->toRfc4122(), $accountId->toRfc4122(), ['SKU'], $instant, $instant, 50, null);
        self::assertCount(1, $page->items);
        self::assertSame('2026-09-21', $page->items[0]->orderBusinessDate);
        self::assertContains($fact->rawDocumentId()->toRfc4122(), $page->items[0]->rawDocumentIds);
    }

    public function testGenerationAndRoutineCoverageWaitForEntirePostingsWindow(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection));
        $source = new PlanningSourceStateQuery($connection);
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $company = $companyId->toRfc4122();
        $account = $accountId->toRfc4122();
        self::seedCompletedBaseline($companyId, $accountId);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow'));
        $from = $today->modify('-2 days')->format('Y-m-d');
        $middle = $today->modify('-1 day')->format('Y-m-d');
        $to = $today->format('Y-m-d');

        $writer->recordCompleted($company, $account, 'postings', $from, $from, hash('sha256', 'day one'), 'regular', [], regularWindowFrom: $from, regularWindowTo: $to);
        self::assertSame('1:1', $source->version($company, $account));
        self::assertFalse($source->observationCoverage($company, $account, $today, $today)['complete']);

        $writer->recordCompleted($company, $account, 'postings', $middle, $middle, hash('sha256', 'day two'), 'regular', [], regularWindowFrom: $from, regularWindowTo: $to);
        self::assertSame('1:2', $source->version($company, $account));
        $writer->recordCompleted($company, $account, 'returns', $from, $to, hash('sha256', 'returns'), 'regular', [], regularWindowFrom: $from, regularWindowTo: $to);
        self::assertFalse($source->observationCoverage($company, $account, $today, $today)['complete']);

        $writer->recordCompleted($company, $account, 'postings', $to, $to, hash('sha256', 'day three'), 'regular', [], regularWindowFrom: $from, regularWindowTo: $to);
        self::assertTrue($source->observationCoverage($company, $account, $today, $today)['complete']);
        $version = $source->version($company, $account);
        $writer->recordCompleted($company, $account, 'postings', $from, $from, hash('sha256', 'day one'), 'rescan', []);
        self::assertTrue($version === $source->version($company, $account));
        self::assertTrue($source->observationCoverage($company, $account, $today, $today)['complete']);

        $rescanAccount = Uuid::v7()->toRfc4122();
        $writer->recordCompleted($company, $rescanAccount, 'postings', $to, $to, hash('sha256', 'same body'), 'rescan', []);
        self::assertSame('1:1', $source->version($company, $rescanAccount));
        $writer->recordCompleted($company, $rescanAccount, 'postings', $to, $to, hash('sha256', 'same body'), 'regular', [], regularWindowFrom: $to, regularWindowTo: $to);
        self::assertSame('1:2', $source->version($company, $rescanAccount));

        $delayedAccount = Uuid::v7()->toRfc4122();
        $writer->recordCompleted($company, $delayedAccount, 'returns', $middle, $middle, hash('sha256', 'delayed poll'), 'regular', [], regularWindowFrom: $middle, regularWindowTo: $middle);
        $checked = $connection->fetchOne('SELECT COUNT(*) FROM planning_ingestion_day_coverage WHERE company_id = ? AND marketplace_account_id = ?', [$company, $delayedAccount]);
        self::assertContains($checked, [0, '0']);
        $writer->recordCompleted($company, $rescanAccount, 'postings', $to, $to, hash('sha256', 'same body'), 'regular', [], regularWindowFrom: $to, regularWindowTo: $to);
        self::assertSame('1:2', $source->version($company, $rescanAccount));
    }

    public function testDelayedAndShortPollsDoNotCompleteTodaysDeepWindow(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection));
        $source = new PlanningSourceStateQuery($connection);
        $company = Uuid::v7()->toRfc4122();
        $account = Uuid::v7()->toRfc4122();
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow'));
        $days = [$today->modify('-2 days')->format('Y-m-d'), $today->modify('-1 day')->format('Y-m-d'), $today->format('Y-m-d')];
        foreach ($days as $index => $day) {
            $writer->recordCompleted($company, $account, 'postings', $day, $day, hash('sha256', 'day-'.$index), 'regular', []);
        }
        $versionBeforeDeep = $source->version($company, $account);
        $writer->recordCompleted($company, $account, 'postings', $days[1], $days[1], hash('sha256', 'day-1'), 'regular', [], regularWindowFrom: $days[1], regularWindowTo: $days[1]);
        $writer->recordCompleted($company, $account, 'postings', $days[0], $days[0], hash('sha256', 'day-0'), 'regular', [], regularWindowFrom: $days[0], regularWindowTo: $days[2]);
        $writer->recordCompleted($company, $account, 'postings', $days[2], $days[2], hash('sha256', 'day-2'), 'regular', [], regularWindowFrom: $days[0], regularWindowTo: $days[2]);
        $checked = $connection->fetchOne('SELECT postings_checked_at FROM planning_ingestion_day_coverage WHERE company_id = ? AND marketplace_account_id = ? AND observation_date = ?', [$company, $account, $days[2]]);
        self::assertFalse($checked);

        $versionBeforeCoverage = $source->version($company, $account);
        $writer->recordCompleted($company, $account, 'postings', $days[1], $days[1], hash('sha256', 'day-1'), 'regular', [], regularWindowFrom: $days[0], regularWindowTo: $days[2]);
        $checked = $connection->fetchOne('SELECT postings_checked_at FROM planning_ingestion_day_coverage WHERE company_id = ? AND marketplace_account_id = ? AND observation_date = ?', [$company, $account, $days[2]]);
        self::assertIsString($checked);
        self::assertNotSame($versionBeforeDeep, $source->version($company, $account));
        self::assertNotSame($versionBeforeCoverage, $source->version($company, $account));
    }

    public function testCohortRawLinksAreBoundedAndReportTruncation(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([SalesFactBuilder::aSalesFact()->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)->withSourceRowId('RAW-LINKS|SKU')
            ->withPostingNumber('RAW-LINKS')->withOrderNumber('RAW-LINKS')
            ->withMarketplaceSku('SKU')->withBusinessDate(new \DateTimeImmutable('2026-09-20'))->build()]);
        $statuses = [];
        for ($index = 0; $index < 60; ++$index) {
            $statuses[] = MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
                ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withPostingNumber('RAW-LINKS')->withOrderNumber('RAW-LINKS')
                ->withStatus(0 === $index % 2 ? 'delivered' : 'delivering')
                ->withObservedAt(new \DateTimeImmutable('2026-09-20 10:00:00')->modify("+{$index} minutes"))
                ->build();
        }
        /** @var MarketplacePostingStatusRepository $statusWriter */
        $statusWriter = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statusWriter->recordChanged($companyId->toRfc4122(), $statuses);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $planning = new IngestionPlanningFacade(new PlanningOrderCohortsQuery($connection), new PlanningCohortProvenanceQuery($connection), new PlanningSourceStateQuery($connection), new PlanningResolutionsQuery($connection), new PlanningMarketplaceSkusQuery($connection), new PlanningOutcomeQueryGuard($connection), 'test-secret');
        $day = new \DateTimeImmutable('2026-09-20');
        $page = $planning->planningOrderCohorts($companyId->toRfc4122(), $accountId->toRfc4122(), ['SKU'], $day, $day);
        self::assertCount(1, $page->items);
        self::assertCount(50, $page->items[0]->rawDocumentIds);
        self::assertTrue($page->items[0]->rawDocumentIdsTruncated);
    }

    public function testCompleteWindowReplacesNativeRawDocumentLinks(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection));
        $company = Uuid::v7()->toRfc4122();
        $account = Uuid::v7()->toRfc4122();
        $oldRaw = Uuid::v7()->toRfc4122();
        $newRaw = Uuid::v7()->toRfc4122();
        $day = '2026-09-20';
        $writer->recordCompleted($company, $account, 'postings', $day, $day, hash('sha256', 'old raw'), 'rescan', [$oldRaw]);
        $row = $connection->fetchAssociative('SELECT raw_document_id::text AS id, pg_typeof(raw_document_id)::text AS type FROM planning_ingestion_source_raw_document WHERE company_id = ? AND marketplace_account_id = ?', [$company, $account]);
        self::assertNotFalse($row);
        self::assertSame($oldRaw, $row['id']);
        self::assertSame('uuid', $row['type']);

        $writer->recordCompleted($company, $account, 'postings', $day, $day, hash('sha256', 'corrected raw'), 'rescan', [$newRaw]);
        self::assertSame([$newRaw], $connection->fetchFirstColumn('SELECT raw_document_id::text FROM planning_ingestion_source_raw_document WHERE company_id = ? AND marketplace_account_id = ?', [$company, $account]));
    }

    public function testHistoricalRescanDoesNotBecomeTodaysBuyoutAfterRegularRepeat(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $company = $companyId->toRfc4122();
        $account = $accountId->toRfc4122();
        $sourceRowId = 'BACKFILL|SKU-3';
        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([
            SalesFactBuilder::aSalesFact()->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withSourceRowId($sourceRowId)->withPostingNumber('BACKFILL')->withOrderNumber('BACKFILL')
                ->withMarketplaceSku('SKU-3')->withBusinessDate(new \DateTimeImmutable('2026-09-10'))->build(),
        ]);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($company, [
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()->withCompanyId($companyId)
                ->withMarketplaceAccountId($accountId)->withPostingNumber('BACKFILL')
                ->withOrderNumber('BACKFILL')->withStatus('delivered')->build(),
        ]);
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection));
        $writer->recordCompleted($company, $account, 'postings', '2026-09-10', '2026-09-10', hash('sha256', 'old outcome'), 'rescan', [], [$sourceRowId]);
        $writer->recordCompleted($company, $account, 'postings', '2026-09-10', '2026-09-10', hash('sha256', 'old outcome'), 'regular', [], [$sourceRowId]);

        $observation = $connection->fetchAssociative(
            'SELECT first_known_outcome_at, first_regularly_observed_at, backfill FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND allocation_key = ? AND outcome = ?',
            [$company, $account, $sourceRowId, '1', 'D'],
        );
        self::assertNotFalse($observation);
        self::assertNull($observation['first_known_outcome_at']);
        self::assertNull($observation['first_regularly_observed_at']);
        self::assertTrue($observation['backfill']);
    }

    public function testFirstCompleteRoutineScanSeedsExistingOutcomesAsBackfill(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $company = $companyId->toRfc4122();
        $account = $accountId->toRfc4122();
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');
        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $sales->upsertAll([SalesFactBuilder::aSalesFact()->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)->withSourceRowId('BASELINE-OLD|SKU')
            ->withPostingNumber('BASELINE-OLD')->withOrderNumber('BASELINE-OLD')
            ->withMarketplaceSku('SKU')->withBusinessDate(new \DateTimeImmutable($today))->build()]);
        $statuses->recordChanged($company, [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withPostingNumber('BASELINE-OLD')->withOrderNumber('BASELINE-OLD')
            ->withStatus('delivered')->build()]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection));
        $writer->recordCompleted($company, $account, 'postings', $today, $today, hash('sha256', 'baseline-old'), 'regular', [], ['BASELINE-OLD|SKU'], regularWindowFrom: $today, regularWindowTo: $today);
        $writer->recordCompleted($company, $account, 'returns', $today, $today, hash('sha256', 'baseline-return'), 'regular', [], regularWindowFrom: $today, regularWindowTo: $today);
        $old = $connection->fetchAssociative('SELECT first_known_outcome_at, first_regularly_observed_at, backfill FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND outcome = ?', [$company, $account, 'BASELINE-OLD|SKU', 'D']);
        self::assertNotFalse($old);
        self::assertNull($old['first_known_outcome_at']);
        self::assertNull($old['first_regularly_observed_at']);
        self::assertTrue($old['backfill']);
        self::assertNotNull($connection->fetchOne('SELECT baseline_completed_at FROM planning_ingestion_account_state WHERE company_id = ? AND marketplace_account_id = ?', [$company, $account]));
        self::assertFalse((new PlanningSourceStateQuery($connection))->observationCoverage($company, $account, new \DateTimeImmutable($today), new \DateTimeImmutable($today))['complete']);

        $sales->upsertAll([SalesFactBuilder::aSalesFact()->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)->withSourceRowId('BASELINE-NEW|SKU')
            ->withPostingNumber('BASELINE-NEW')->withOrderNumber('BASELINE-NEW')
            ->withMarketplaceSku('SKU')->withBusinessDate(new \DateTimeImmutable($today))->build()]);
        $statuses->recordChanged($company, [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withPostingNumber('BASELINE-NEW')->withOrderNumber('BASELINE-NEW')
            ->withStatus('delivered')->build()]);
        $writer->recordCompleted($company, $account, 'postings', $today, $today, hash('sha256', 'baseline-new'), 'regular', [], ['BASELINE-NEW|SKU']);
        $new = $connection->fetchAssociative('SELECT first_known_outcome_at, first_regularly_observed_at, backfill FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND outcome = ?', [$company, $account, 'BASELINE-NEW|SKU', 'D']);
        self::assertNotFalse($new);
        self::assertNull($new['first_known_outcome_at']);
        self::assertNull($new['first_regularly_observed_at']);
        self::assertTrue($new['backfill']);
    }

    public function testReturnFirstSeenWithoutBuyoutDateStaysUndatedAfterRegularPosting(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $company = $companyId->toRfc4122();
        $account = $accountId->toRfc4122();
        $sourceRowId = 'RETURN-FIRST|SKU';

        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([SalesFactBuilder::aSalesFact()->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)->withSourceRowId($sourceRowId)
            ->withPostingNumber('RETURN-FIRST')->withOrderNumber('RETURN-FIRST')
            ->withMarketplaceSku('SKU')->build()]);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($company, [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withPostingNumber('RETURN-FIRST')->withOrderNumber('RETURN-FIRST')
            ->withStatus('delivered')->build()]);
        /** @var MarketplaceReturnFactRepository $returns */
        $returns = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        $returns->upsertAll([MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withSourceRowId('RETURN-FIRST-RETURN')->withSourceId(991103)
            ->withOrderNumber('RETURN-FIRST')->withMarketplaceSku('SKU')
            ->withReturnType('ClientReturn')->build()]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection));
        $writer->recordCompleted($company, $account, 'returns', '2026-09-20', '2026-09-20', hash('sha256', 'return-first'), 'regular', [],
            returnKeys: [['orderNumber' => 'RETURN-FIRST', 'marketplaceSku' => 'SKU']]);
        $before = $connection->fetchAssociative('SELECT first_known_outcome_at, first_regularly_observed_at FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND outcome = ?', [$company, $account, $sourceRowId, 'R']);
        self::assertNotFalse($before);
        self::assertNull($before['first_known_outcome_at']);
        self::assertNull($before['first_regularly_observed_at']);

        $writer->recordCompleted($company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'posting-after-return'), 'regular', [], [$sourceRowId]);
        $after = $connection->fetchAssociative('SELECT first_known_outcome_at, first_regularly_observed_at, backfill FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND outcome = ?', [$company, $account, $sourceRowId, 'R']);
        self::assertNotFalse($after);
        self::assertNull($after['first_known_outcome_at']);
        self::assertNull($after['first_regularly_observed_at']);
        self::assertTrue($after['backfill']);
    }

    public function testReturnLoadedBeforeItsSaleDoesNotBecomeTodaysBuyout(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $company = $companyId->toRfc4122();
        $account = $accountId->toRfc4122();
        self::seedCompletedBaseline($companyId, $accountId);

        /** @var MarketplaceReturnFactRepository $returns */
        $returns = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        $returns->upsertAll([MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withSourceRowId('LATE-SALE-RETURN')->withSourceId(991104)
            ->withOrderNumber('LATE-SALE')->withMarketplaceSku('SKU')
            ->withReturnType('ClientReturn')->build()]);
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection));
        $writer->recordCompleted($company, $account, 'returns', '2026-09-20', '2026-09-20', hash('sha256', 'return-before-sale'), 'regular', [],
            returnKeys: [['orderNumber' => 'LATE-SALE', 'marketplaceSku' => 'SKU']]);

        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([SalesFactBuilder::aSalesFact()->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)->withSourceRowId('LATE-SALE|SKU')
            ->withPostingNumber('LATE-SALE')->withOrderNumber('LATE-SALE')
            ->withMarketplaceSku('SKU')->build()]);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($company, [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withPostingNumber('LATE-SALE')->withOrderNumber('LATE-SALE')
            ->withStatus('delivered')->build()]);
        $writer->recordCompleted($company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'late-sale'), 'regular', [], ['LATE-SALE|SKU']);

        $observation = $connection->fetchAssociative('SELECT first_known_outcome_at, first_regularly_observed_at, backfill FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND outcome = ?', [$company, $account, 'LATE-SALE|SKU', 'R']);
        self::assertNotFalse($observation);
        self::assertNull($observation['first_known_outcome_at']);
        self::assertNull($observation['first_regularly_observed_at']);
        self::assertTrue($observation['backfill']);
    }

    public function testCorrectedUndatedReturnDoesNotBecomeTodaysDeliveredSale(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $company = $companyId->toRfc4122();
        $account = $accountId->toRfc4122();
        $sourceRowId = 'RETURN-CORRECTED|SKU';
        self::seedCompletedBaseline($companyId, $accountId);

        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([SalesFactBuilder::aSalesFact()->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)->withSourceRowId($sourceRowId)
            ->withPostingNumber('RETURN-CORRECTED')->withOrderNumber('RETURN-CORRECTED')
            ->withMarketplaceSku('SKU')->build()]);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($company, [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withPostingNumber('RETURN-CORRECTED')->withOrderNumber('RETURN-CORRECTED')
            ->withStatus('delivered')->build()]);
        /** @var MarketplaceReturnFactRepository $returns */
        $returns = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        $returnBuilder = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withSourceRowId('RETURN-CORRECTED-FACT')->withSourceId(991105)
            ->withMarketplaceSku('SKU')->withReturnType('ClientReturn');
        $returns->upsertAll([$returnBuilder->withOrderNumber('RETURN-CORRECTED')->build()]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection));
        $writer->recordCompleted($company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'undated return'), 'rescan', [], [$sourceRowId]);
        $returned = $connection->fetchAssociative('SELECT first_known_outcome_at, first_regularly_observed_at FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND outcome = ?', [$company, $account, $sourceRowId, 'R']);
        self::assertNotFalse($returned);
        self::assertNull($returned['first_known_outcome_at']);
        self::assertNull($returned['first_regularly_observed_at']);

        $returns->upsertAll([$returnBuilder->withOrderNumber('CORRECTED-OTHER-ORDER')->build()]);
        $writer->recordCompleted($company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'corrected return'), 'regular', [], [$sourceRowId]);
        $delivered = $connection->fetchAssociative('SELECT first_known_outcome_at, first_regularly_observed_at, backfill FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND outcome = ?', [$company, $account, $sourceRowId, 'D']);
        self::assertNotFalse($delivered);
        self::assertNull($delivered['first_known_outcome_at']);
        self::assertNull($delivered['first_regularly_observed_at']);
        self::assertTrue($delivered['backfill']);
    }

    public function testReturnPollingDoesNotDateAnUnobservedOldDeliveredSibling(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $company = $companyId->toRfc4122();
        $account = $accountId->toRfc4122();
        self::seedCompletedBaseline($companyId, $accountId);

        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([SalesFactBuilder::aSalesFact()->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)->withSourceRowId('OLD-SIBLING|SKU-B')
            ->withPostingNumber('OLD-SIBLING')->withOrderNumber('OLD-SIBLING')
            ->withMarketplaceSku('SKU-B')->withBusinessDate(new \DateTimeImmutable('2026-08-01'))->build()]);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($company, [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withPostingNumber('OLD-SIBLING')->withOrderNumber('OLD-SIBLING')
            ->withStatus('delivered')->withObservedAt(new \DateTimeImmutable('2026-08-15 10:00:00'))->build()]);
        /** @var MarketplaceReturnFactRepository $returns */
        $returns = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        $returns->upsertAll([MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withSourceRowId('OLD-SIBLING-RETURN')->withSourceId(991106)
            ->withOrderNumber('OLD-SIBLING')->withMarketplaceSku('SKU-A')
            ->withReturnType('ClientReturn')->build()]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        (new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection)))->recordCompleted(
            $company, $account, 'returns', '2026-09-20', '2026-09-20', hash('sha256', 'old sibling return'),
            'regular', [], returnKeys: [['orderNumber' => 'OLD-SIBLING', 'marketplaceSku' => 'SKU-A']],
        );
        $observation = $connection->fetchAssociative('SELECT first_known_outcome_at, first_regularly_observed_at, backfill FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND outcome = ?', [$company, $account, 'OLD-SIBLING|SKU-B', 'D']);
        self::assertNotFalse($observation);
        self::assertNull($observation['first_known_outcome_at']);
        self::assertNull($observation['first_regularly_observed_at']);
        self::assertTrue($observation['backfill']);
    }

    public function testCorrectedOldTerminalNoBuyDoesNotBecomeTodaysDeliveredSale(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $company = $companyId->toRfc4122();
        $account = $accountId->toRfc4122();
        self::seedCompletedBaseline($companyId, $accountId);

        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([SalesFactBuilder::aSalesFact()->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)->withSourceRowId('OLD-NO-BUY|SKU')
            ->withPostingNumber('OLD-NO-BUY')->withOrderNumber('OLD-NO-BUY')
            ->withMarketplaceSku('SKU')->withBusinessDate(new \DateTimeImmutable('2026-08-01'))->build()]);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($company, [
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
                ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withPostingNumber('OLD-NO-BUY')->withOrderNumber('OLD-NO-BUY')
                ->withStatus('awaiting_packaging')->withObservedAt(new \DateTimeImmutable('2026-08-14 10:00:00'))->build(),
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
                ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withPostingNumber('OLD-NO-BUY')->withOrderNumber('OLD-NO-BUY')
                ->withStatus('cancelled')->withObservedAt(new \DateTimeImmutable('2026-08-15 10:00:00'))->build(),
        ]);
        /** @var MarketplaceReturnFactRepository $returns */
        $returns = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        $returns->upsertAll([MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withSourceRowId('OLD-NO-BUY-RETURN')->withSourceId(991107)
            ->withOrderNumber('OLD-NO-BUY')->withMarketplaceSku('SKU')
            ->withReturnReasonName('Покупатель отменил заказ')->build()]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection));
        $writer->recordCompleted($company, $account, 'postings', '2026-08-15', '2026-08-15', hash('sha256', 'old no-buy'), 'rescan', [], ['OLD-NO-BUY|SKU']);
        self::assertSame('T1', $connection->fetchOne('SELECT outcome FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?', [$company, $account, 'OLD-NO-BUY|SKU']));

        $statuses->recordChanged($company, [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withPostingNumber('OLD-NO-BUY')->withOrderNumber('OLD-NO-BUY')
            ->withStatus('delivered')->withObservedAt(new \DateTimeImmutable('2026-09-20 10:00:00'))->build()]);
        $writer->recordCompleted($company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'corrected no-buy'), 'regular', [], ['OLD-NO-BUY|SKU']);
        $delivered = $connection->fetchAssociative('SELECT first_known_outcome_at, first_regularly_observed_at, backfill FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND outcome = ?', [$company, $account, 'OLD-NO-BUY|SKU', 'D']);
        self::assertNotFalse($delivered);
        self::assertNull($delivered['first_known_outcome_at']);
        self::assertNull($delivered['first_regularly_observed_at']);
        self::assertTrue($delivered['backfill']);
    }

    public function testNewReturnAfterPreviouslyActiveOrderGetsRegularBuyoutDate(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $company = $companyId->toRfc4122();
        $account = $accountId->toRfc4122();
        self::seedCompletedBaseline($companyId, $accountId);

        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([SalesFactBuilder::aSalesFact()->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)->withSourceRowId('ACTIVE-RETURN|SKU')
            ->withPostingNumber('ACTIVE-RETURN')->withOrderNumber('ACTIVE-RETURN')
            ->withMarketplaceSku('SKU')->withBusinessDate(new \DateTimeImmutable('2026-09-18'))->build()]);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($company, [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withPostingNumber('ACTIVE-RETURN')->withOrderNumber('ACTIVE-RETURN')
            ->withStatus('awaiting_packaging')->withObservedAt(new \DateTimeImmutable('2026-09-19 11:00:00'))->build()]);
        /** @var MarketplaceReturnFactRepository $returns */
        $returns = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        $returns->upsertAll([MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withSourceRowId('ACTIVE-RETURN-FACT')->withSourceId(991108)
            ->withOrderNumber('ACTIVE-RETURN')->withMarketplaceSku('SKU')
            ->withReturnType('ClientReturn')->build()]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection));
        $writer->recordCompleted($company, $account, 'returns', '2026-09-20', '2026-09-20', hash('sha256', 'return before delivered'), 'regular', [],
            returnKeys: [['orderNumber' => 'ACTIVE-RETURN', 'marketplaceSku' => 'SKU']]);
        $statuses->recordChanged($company, [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withPostingNumber('ACTIVE-RETURN')->withOrderNumber('ACTIVE-RETURN')
            ->withStatus('delivered')->withObservedAt(new \DateTimeImmutable('2026-09-20 11:00:00'))->build()]);
        $writer->recordCompleted($company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'delivered after return'), 'regular', [], ['ACTIVE-RETURN|SKU']);
        $returned = $connection->fetchAssociative('SELECT first_known_outcome_at, first_regularly_observed_at, backfill FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND outcome = ?', [$company, $account, 'ACTIVE-RETURN|SKU', 'R']);
        self::assertNotFalse($returned);
        self::assertNotNull($returned['first_known_outcome_at']);
        self::assertNotNull($returned['first_regularly_observed_at']);
        self::assertFalse($returned['backfill']);
    }

    public function testReturnsFirstDoesNotPreventDatedPostingsObservation(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $company = $companyId->toRfc4122();
        $account = $accountId->toRfc4122();
        self::seedCompletedBaseline($companyId, $accountId);

        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([SalesFactBuilder::aSalesFact()->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)->withSourceRowId('RETURN-ORDER-FIRST|SKU')
            ->withPostingNumber('RETURN-ORDER-FIRST')->withOrderNumber('RETURN-ORDER-FIRST')
            ->withMarketplaceSku('SKU')->withBusinessDate(new \DateTimeImmutable('2026-09-20'))->build()]);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($company, [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withPostingNumber('RETURN-ORDER-FIRST')->withOrderNumber('RETURN-ORDER-FIRST')
            ->withStatus('delivered')->withObservedAt(new \DateTimeImmutable('2026-09-20 11:00:00'))->build()]);
        /** @var MarketplaceReturnFactRepository $returns */
        $returns = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        $returns->upsertAll([MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withSourceRowId('RETURN-ORDER-FIRST-FACT')->withSourceId(991111)
            ->withOrderNumber('RETURN-ORDER-FIRST')->withMarketplaceSku('SKU')
            ->withReturnType('ClientReturn')->build()]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection));
        $writer->recordCompleted($company, $account, 'returns', '2026-09-20', '2026-09-20', hash('sha256', 'return first'), 'regular', [],
            returnKeys: [['orderNumber' => 'RETURN-ORDER-FIRST', 'marketplaceSku' => 'SKU']]);
        $writer->recordCompleted($company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'postings second'), 'regular', [], ['RETURN-ORDER-FIRST|SKU']);
        $observation = $connection->fetchAssociative('SELECT first_known_outcome_at, first_regularly_observed_at, backfill FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND outcome = ?', [$company, $account, 'RETURN-ORDER-FIRST|SKU', 'R']);
        self::assertNotFalse($observation);
        self::assertNotNull($observation['first_known_outcome_at']);
        self::assertNotNull($observation['first_regularly_observed_at']);
        self::assertFalse($observation['backfill']);
    }

    public function testRecentTerminalCorrectionToDeliveredKeepsRegularEvidence(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $company = $companyId->toRfc4122();
        $account = $accountId->toRfc4122();
        self::seedCompletedBaseline($companyId, $accountId);

        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([SalesFactBuilder::aSalesFact()->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)->withSourceRowId('RECENT-CORRECTION|SKU')
            ->withPostingNumber('RECENT-CORRECTION')->withOrderNumber('RECENT-CORRECTION')
            ->withMarketplaceSku('SKU')->withBusinessDate(new \DateTimeImmutable('2026-09-20'))->build()]);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($company, [
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
                ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withPostingNumber('RECENT-CORRECTION')->withOrderNumber('RECENT-CORRECTION')
                ->withStatus('awaiting_packaging')->withObservedAt(new \DateTimeImmutable('2026-09-19 11:00:00'))->build(),
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
                ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withPostingNumber('RECENT-CORRECTION')->withOrderNumber('RECENT-CORRECTION')
                ->withStatus('cancelled')->withObservedAt(new \DateTimeImmutable('2026-09-20 10:00:00'))->build(),
        ]);
        /** @var MarketplaceReturnFactRepository $returns */
        $returns = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        $returns->upsertAll([MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withSourceRowId('RECENT-CORRECTION-RETURN')->withSourceId(991112)
            ->withOrderNumber('RECENT-CORRECTION')->withMarketplaceSku('SKU')
            ->withReturnReasonName('Покупатель отменил заказ')->build()]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection));
        $writer->recordCompleted($company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'recent no buy'), 'regular', [], ['RECENT-CORRECTION|SKU']);
        self::assertIsString($connection->fetchOne('SELECT first_known_outcome_at FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND outcome = ?', [$company, $account, 'RECENT-CORRECTION|SKU', 'T1']));

        $statuses->recordChanged($company, [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withPostingNumber('RECENT-CORRECTION')->withOrderNumber('RECENT-CORRECTION')
            ->withStatus('delivered')->withObservedAt(new \DateTimeImmutable('2026-09-20 14:00:00'))->build()]);
        $writer->recordCompleted($company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'recent delivered'), 'regular', [], ['RECENT-CORRECTION|SKU']);
        $delivered = $connection->fetchAssociative('SELECT first_known_outcome_at, first_regularly_observed_at, backfill FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND outcome = ?', [$company, $account, 'RECENT-CORRECTION|SKU', 'D']);
        self::assertNotFalse($delivered);
        self::assertNotNull($delivered['first_known_outcome_at']);
        self::assertNotNull($delivered['first_regularly_observed_at']);
        self::assertFalse($delivered['backfill']);
    }

    public function testShiftedReturnedUnitKeepsItsOwnDateBeforeOldDeliveredDate(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $company = $companyId->toRfc4122();
        $account = $accountId->toRfc4122();
        self::seedCompletedBaseline($companyId, $accountId);

        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sale = SalesFactBuilder::aSalesFact()->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)->withSourceRowId('SHIFTED-R|SKU')
            ->withPostingNumber('SHIFTED-R')->withOrderNumber('SHIFTED-R')
            ->withMarketplaceSku('SKU')->withBusinessDate(new \DateTimeImmutable('2026-09-20'));
        $sales->upsertAll([$sale->withQuantity(1)->build()]);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($company, [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withPostingNumber('SHIFTED-R')->withOrderNumber('SHIFTED-R')
            ->withStatus('delivered')->withObservedAt(new \DateTimeImmutable('2026-09-20 10:00:00'))->build()]);
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection));
        $writer->recordCompleted($company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'one old D'), 'rescan', [], ['SHIFTED-R|SKU']);

        /** @var MarketplaceReturnFactRepository $returns */
        $returns = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        $returns->upsertAll([MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withSourceRowId('SHIFTED-R-RETURN')->withSourceId(991113)
            ->withOrderNumber('SHIFTED-R')->withMarketplaceSku('SKU')
            ->withReturnType('ClientReturn')->withQuantity(1)->build()]);
        $sales->upsertAll([$sale->withQuantity(2)->build()]);
        $writer->recordCompleted($company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'D and R'), 'regular', [], ['SHIFTED-R|SKU']);
        $returnedDate = $connection->fetchOne('SELECT first_known_outcome_at FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND allocation_key = ? AND outcome = ?', [$company, $account, 'SHIFTED-R|SKU', '2', 'R']);
        self::assertIsString($returnedDate);

        $sales->upsertAll([$sale->withQuantity(1)->build()]);
        $writer->recordCompleted($company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'shifted R'), 'regular', [], ['SHIFTED-R|SKU']);
        $shiftedDate = $connection->fetchOne('SELECT first_known_outcome_at FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND allocation_key = ? AND outcome = ?', [$company, $account, 'SHIFTED-R|SKU', '1', 'R']);
        self::assertSame($returnedDate, $shiftedDate);
    }

    public function testTwoShiftedReturnedUnitsKeepDistinctDatesAcrossRepeatedScans(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $company = $companyId->toRfc4122();
        $account = $accountId->toRfc4122();
        self::seedCompletedBaseline($companyId, $accountId);

        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sale = SalesFactBuilder::aSalesFact()->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)->withSourceRowId('TWO-SHIFTED-R|SKU')
            ->withPostingNumber('TWO-SHIFTED-R')->withOrderNumber('TWO-SHIFTED-R')
            ->withMarketplaceSku('SKU')->withBusinessDate(new \DateTimeImmutable('2026-09-20'));
        $sales->upsertAll([$sale->withQuantity(3)->build()]);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($company, [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withPostingNumber('TWO-SHIFTED-R')->withOrderNumber('TWO-SHIFTED-R')
            ->withStatus('delivered')->withObservedAt(new \DateTimeImmutable('2026-09-20 10:00:00'))->build()]);
        /** @var MarketplaceReturnFactRepository $returns */
        $returns = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        $return = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withSourceRowId('TWO-SHIFTED-R-RETURN')->withSourceId(991114)
            ->withOrderNumber('TWO-SHIFTED-R')->withMarketplaceSku('SKU')
            ->withReturnType('ClientReturn');
        $returns->upsertAll([$return->withQuantity(2)->build()]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection));
        $connection->insert('planning_ingestion_source_row_run', PlanningIngestionSourceRowRunBuilder::aSourceRowRun()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withSourceRowId('TWO-SHIFTED-R|SKU')->row());
        foreach (['2' => '2026-09-20 11:00:00', '3' => '2026-09-20 12:00:00'] as $key => $confirmedAt) {
            $connection->insert('planning_ingestion_resolution_observation', PlanningIngestionResolutionObservationBuilder::anObservation()
                ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withAllocation('TWO-SHIFTED-R|SKU', (string) $key, 'R')
                ->withDateLineage('two-shifted-return-'.$key)
                ->withFirstKnownOutcomeAt(new \DateTimeImmutable($confirmedAt))
                ->withFirstRegularlyObservedAt((new \DateTimeImmutable($confirmedAt))->modify('+30 minutes'))
                ->withBackfill(false)->row());
        }

        $sales->upsertAll([$sale->withQuantity(2)->build()]);
        for ($scan = 0; $scan < 2; ++$scan) {
            $writer->recordCompleted($company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'two R shifted '.$scan), 'regular', [], ['TWO-SHIFTED-R|SKU']);
            self::assertSame(
                [['allocation_key' => '1', 'first_known_outcome_at' => '2026-09-20 11:00:00', 'first_regularly_observed_at' => '2026-09-20 11:30:00'],
                    ['allocation_key' => '2', 'first_known_outcome_at' => '2026-09-20 12:00:00', 'first_regularly_observed_at' => '2026-09-20 12:30:00']],
                $connection->fetchAllAssociative(
                    "SELECT allocation_key, first_known_outcome_at, first_regularly_observed_at FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND outcome = 'R' AND allocation_key IN ('1', '2') ORDER BY allocation_key",
                    [$company, $account, 'TWO-SHIFTED-R|SKU'],
                ),
            );
        }

        $returns->upsertAll([$return->withQuantity(1)->build()]);
        $writer->recordCompleted($company, $account, 'returns', '2026-09-20', '2026-09-20', hash('sha256', 'one R after shift'), 'rescan', [], returnKeys: [['orderNumber' => 'TWO-SHIFTED-R', 'marketplaceSku' => 'SKU']]);
        $sales->upsertAll([$sale->withQuantity(3)->build()]);
        $returns->upsertAll([$return->withQuantity(2)->build()]);
        $writer->recordCompleted($company, $account, 'returns', '2026-09-20', '2026-09-20', hash('sha256', 'two R again'), 'rescan', [], returnKeys: [['orderNumber' => 'TWO-SHIFTED-R', 'marketplaceSku' => 'SKU']]);
        self::assertSame(['first_known_outcome_at' => '2026-09-20 12:00:00', 'first_regularly_observed_at' => '2026-09-20 12:30:00'], $connection->fetchAssociative(
            "SELECT first_known_outcome_at, first_regularly_observed_at FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND allocation_key = '3' AND outcome = 'R'",
            [$company, $account, 'TWO-SHIFTED-R|SKU'],
        ));

        $returns->upsertAll([$return->withReturnType('Cancellation')->withQuantity(2)->build()]);
        $writer->recordCompleted($company, $account, 'returns', '2026-09-20', '2026-09-20', hash('sha256', 'R disappears'), 'rescan', [], returnKeys: [['orderNumber' => 'TWO-SHIFTED-R', 'marketplaceSku' => 'SKU']]);
        $returns->upsertAll([$return->withQuantity(1)->build()]);
        $writer->recordCompleted($company, $account, 'returns', '2026-09-20', '2026-09-20', hash('sha256', 'R returns at key three'), 'rescan', [], returnKeys: [['orderNumber' => 'TWO-SHIFTED-R', 'marketplaceSku' => 'SKU']]);
        self::assertSame(['first_known_outcome_at' => '2026-09-20 12:00:00', 'first_regularly_observed_at' => '2026-09-20 12:30:00'], $connection->fetchAssociative(
            "SELECT first_known_outcome_at, first_regularly_observed_at FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND allocation_key = '3' AND outcome = 'R'",
            [$company, $account, 'TWO-SHIFTED-R|SKU'],
        ));
    }

    public function testOldTerminalOnBaselineCalendarDayStaysUndated(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $company = $companyId->toRfc4122();
        $account = $accountId->toRfc4122();
        self::seedCompletedBaseline($companyId, $accountId, new \DateTimeImmutable('2026-09-20 12:00:00'));

        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([SalesFactBuilder::aSalesFact()->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)->withSourceRowId('SAME-DAY-OLD|SKU')
            ->withPostingNumber('SAME-DAY-OLD')->withOrderNumber('SAME-DAY-OLD')
            ->withMarketplaceSku('SKU')->withBusinessDate(new \DateTimeImmutable('2026-09-20'))->build()]);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($company, [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withPostingNumber('SAME-DAY-OLD')->withOrderNumber('SAME-DAY-OLD')
            ->withStatus('cancelled')->withObservedAt(new \DateTimeImmutable('2026-09-20 10:00:00'))->build()]);
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection));
        $writer->recordCompleted($company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'same-day old T1'), 'rescan', [], ['SAME-DAY-OLD|SKU']);
        $statuses->recordChanged($company, [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withPostingNumber('SAME-DAY-OLD')->withOrderNumber('SAME-DAY-OLD')
            ->withStatus('delivered')->withObservedAt(new \DateTimeImmutable('2026-09-20 11:00:00'))->build()]);
        $writer->recordCompleted($company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'same-day old D'), 'regular', [], ['SAME-DAY-OLD|SKU']);
        $delivered = $connection->fetchAssociative(
            "SELECT first_known_outcome_at, first_regularly_observed_at, backfill FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND outcome = 'D'",
            [$company, $account, 'SAME-DAY-OLD|SKU'],
        );
        self::assertNotFalse($delivered);
        self::assertNull($delivered['first_known_outcome_at']);
        self::assertNull($delivered['first_regularly_observed_at']);
        self::assertTrue($delivered['backfill']);
    }

    public function testGrowingReturnedOutcomeDoesNotDuplicateAnEarlierUnitDate(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $company = $companyId->toRfc4122();
        $account = $accountId->toRfc4122();
        self::seedCompletedBaseline($companyId, $accountId);

        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([SalesFactBuilder::aSalesFact()->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)->withSourceRowId('GROWING-R|SKU')
            ->withPostingNumber('GROWING-R')->withOrderNumber('GROWING-R')
            ->withMarketplaceSku('SKU')->withQuantity(4)
            ->withBusinessDate(new \DateTimeImmutable('2026-09-20'))->build()]);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($company, [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withPostingNumber('GROWING-R')->withOrderNumber('GROWING-R')
            ->withStatus('delivered')->build()]);
        /** @var MarketplaceReturnFactRepository $returns */
        $returns = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        $return = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withSourceRowId('GROWING-R-RETURN')->withSourceId(991115)
            ->withOrderNumber('GROWING-R')->withMarketplaceSku('SKU')
            ->withReturnType('ClientReturn');
        $returns->upsertAll([$return->withQuantity(1)->build()]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->insert('planning_ingestion_source_row_run', PlanningIngestionSourceRowRunBuilder::aSourceRowRun()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withSourceRowId('GROWING-R|SKU')->row());
        foreach (['1', '2'] as $key) {
            $connection->insert('planning_ingestion_resolution_observation', PlanningIngestionResolutionObservationBuilder::anObservation()
                ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withAllocation('GROWING-R|SKU', $key, 'D')
                ->withFirstObservedAt(new \DateTimeImmutable('2026-09-19 11:00:00'))
                ->withDateLineage('growing-delivered-'.$key)
                ->withFirstKnownOutcomeAt(new \DateTimeImmutable('2026-09-19 11:00:00'))
                ->withFirstRegularlyObservedAt(new \DateTimeImmutable('2026-09-19 12:00:00'))
                ->withBackfill(false)->row());
        }
        $connection->insert('planning_ingestion_resolution_observation', PlanningIngestionResolutionObservationBuilder::anObservation()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withAllocation('GROWING-R|SKU', '4', 'R')
            ->withFirstObservedAt(new \DateTimeImmutable('2026-09-19 11:00:00'))
            ->withDateLineage('growing-return-4')
            ->withFirstKnownOutcomeAt(new \DateTimeImmutable('2026-09-20 11:00:00'))
            ->withFirstRegularlyObservedAt(new \DateTimeImmutable('2026-09-20 12:00:00'))
            ->withBackfill(false)->row());
        $returns->upsertAll([$return->withQuantity(2)->build()]);
        (new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection)))->recordCompleted(
            $company, $account, 'returns', '2026-09-20', '2026-09-20', hash('sha256', 'growing R'), 'rescan', [],
            returnKeys: [['orderNumber' => 'GROWING-R', 'marketplaceSku' => 'SKU']],
        );
        self::assertSame(
            [['allocation_key' => '3', 'first_known_outcome_at' => '2026-09-20 11:00:00', 'first_regularly_observed_at' => '2026-09-20 12:00:00'],
                ['allocation_key' => '4', 'first_known_outcome_at' => null, 'first_regularly_observed_at' => null]],
            $connection->fetchAllAssociative(
                "SELECT allocation_key, first_known_outcome_at, first_regularly_observed_at FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND outcome = 'R' ORDER BY allocation_key",
                [$company, $account, 'GROWING-R|SKU'],
            ),
        );
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow'));
        $connection->insert('planning_ingestion_day_coverage', PlanningIngestionDayCoverageBuilder::aDayCoverage()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withObservationDate($today)->withChecks($today->setTime(13, 0), $today->setTime(13, 0))->row());
        $planning = new IngestionPlanningFacade(new PlanningOrderCohortsQuery($connection), new PlanningCohortProvenanceQuery($connection), new PlanningSourceStateQuery($connection), new PlanningResolutionsQuery($connection), new PlanningMarketplaceSkusQuery($connection), new PlanningOutcomeQueryGuard($connection), 'test-secret');
        self::assertFalse($planning->planningResolutionTotals($company, $account, ['SKU'], $today, $today, PlanningObservationDateAxis::FIRST_REGULAR_OBSERVATION)->complete);
        self::assertSame($today->format('Y-m-d'), $connection->fetchOne("SELECT undated_since_at::date::text FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND allocation_key = '4' AND outcome = 'R'", [$company, $account, 'GROWING-R|SKU']));
    }

    public function testMovedReturnDateIsNotReusedByANewDeliveredUnit(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $company = $companyId->toRfc4122();
        $account = $accountId->toRfc4122();
        self::seedCompletedBaseline($companyId, $accountId);

        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sale = SalesFactBuilder::aSalesFact()->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)->withSourceRowId('MOVED-R|SKU')
            ->withPostingNumber('MOVED-R')->withOrderNumber('MOVED-R')
            ->withMarketplaceSku('SKU')->withBusinessDate(new \DateTimeImmutable('2026-09-20'));
        $sales->upsertAll([$sale->withQuantity(2)->build()]);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($company, [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withPostingNumber('MOVED-R')->withOrderNumber('MOVED-R')
            ->withStatus('delivered')->build()]);
        /** @var MarketplaceReturnFactRepository $returns */
        $returns = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        $returns->upsertAll([MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withSourceRowId('MOVED-R-RETURN')->withSourceId(991116)
            ->withOrderNumber('MOVED-R')->withMarketplaceSku('SKU')
            ->withReturnType('ClientReturn')->withQuantity(1)->build()]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->insert('planning_ingestion_source_row_run', PlanningIngestionSourceRowRunBuilder::aSourceRowRun()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withSourceRowId('MOVED-R|SKU')->row());
        $connection->insert('planning_ingestion_resolution_observation', PlanningIngestionResolutionObservationBuilder::anObservation()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withAllocation('MOVED-R|SKU', '2', 'R')
            ->withDateLineage('moved-return-2')
            ->withFirstKnownOutcomeAt(new \DateTimeImmutable('2026-09-20 11:00:00'))
            ->withFirstRegularlyObservedAt(new \DateTimeImmutable('2026-09-20 12:00:00'))
            ->withBackfill(false)->row());
        $sales->upsertAll([$sale->withQuantity(3)->build()]);
        (new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection)))->recordCompleted(
            $company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'R shifted by new D'), 'rescan', [], ['MOVED-R|SKU'],
        );
        self::assertSame(
            [['allocation_key' => '2', 'outcome' => 'D', 'first_known_outcome_at' => null, 'first_regularly_observed_at' => null],
                ['allocation_key' => '3', 'outcome' => 'R', 'first_known_outcome_at' => '2026-09-20 11:00:00', 'first_regularly_observed_at' => '2026-09-20 12:00:00']],
            $connection->fetchAllAssociative(
                "SELECT allocation_key, outcome, first_known_outcome_at, first_regularly_observed_at FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND ((allocation_key = '2' AND outcome = 'D') OR (allocation_key = '3' AND outcome = 'R')) ORDER BY allocation_key",
                [$company, $account, 'MOVED-R|SKU'],
            ),
        );
    }

    public function testKnownReturnDateSurvivesAnUndatedOrdinalWhenQuantityShrinks(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $company = $companyId->toRfc4122();
        $account = $accountId->toRfc4122();
        self::seedCompletedBaseline($companyId, $accountId);

        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([SalesFactBuilder::aSalesFact()->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)->withSourceRowId('KNOWN-LATER-R|SKU')
            ->withPostingNumber('KNOWN-LATER-R')->withOrderNumber('KNOWN-LATER-R')
            ->withMarketplaceSku('SKU')->withQuantity(3)
            ->withBusinessDate(new \DateTimeImmutable('2026-09-20'))->build()]);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($company, [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withPostingNumber('KNOWN-LATER-R')->withOrderNumber('KNOWN-LATER-R')
            ->withStatus('delivered')->build()]);
        /** @var MarketplaceReturnFactRepository $returns */
        $returns = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        $return = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withSourceRowId('KNOWN-LATER-R-RETURN')->withSourceId(991117)
            ->withOrderNumber('KNOWN-LATER-R')->withMarketplaceSku('SKU')
            ->withReturnType('ClientReturn');
        $returns->upsertAll([$return->withQuantity(2)->build()]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->insert('planning_ingestion_source_row_run', PlanningIngestionSourceRowRunBuilder::aSourceRowRun()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withSourceRowId('KNOWN-LATER-R|SKU')->row());
        foreach (['2', '3'] as $key) {
            $observation = PlanningIngestionResolutionObservationBuilder::anObservation()
                ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withAllocation('KNOWN-LATER-R|SKU', $key, 'R');
            if ('3' === $key) {
                $observation = $observation
                    ->withDateLineage('known-later-return-3')
                    ->withFirstKnownOutcomeAt(new \DateTimeImmutable('2026-09-20 11:00:00'))
                    ->withFirstRegularlyObservedAt(new \DateTimeImmutable('2026-09-20 12:00:00'))
                    ->withBackfill(false);
            }
            $connection->insert('planning_ingestion_resolution_observation', $observation->row());
        }

        $returns->upsertAll([$return->withQuantity(1)->build()]);
        (new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection)))->recordCompleted(
            $company, $account, 'returns', '2026-09-20', '2026-09-20', hash('sha256', 'undated first R disappears'), 'rescan', [],
            returnKeys: [['orderNumber' => 'KNOWN-LATER-R', 'marketplaceSku' => 'SKU']],
        );
        self::assertSame(
            ['first_known_outcome_at' => '2026-09-20 11:00:00', 'first_regularly_observed_at' => '2026-09-20 12:00:00'],
            $connection->fetchAssociative(
                "SELECT first_known_outcome_at, first_regularly_observed_at FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND allocation_key = '3' AND outcome = 'R'",
                [$company, $account, 'KNOWN-LATER-R|SKU'],
            ),
        );
    }

    public function testReappearingStaleKeyDoesNotDuplicateOrMixDates(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $company = $companyId->toRfc4122();
        $account = $accountId->toRfc4122();
        self::seedCompletedBaseline($companyId, $accountId);

        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sale = SalesFactBuilder::aSalesFact()->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)->withSourceRowId('STALE-R|SKU')
            ->withPostingNumber('STALE-R')->withOrderNumber('STALE-R')
            ->withMarketplaceSku('SKU')->withBusinessDate(new \DateTimeImmutable('2026-09-20'));
        $sales->upsertAll([$sale->withQuantity(3)->build()]);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($company, [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withPostingNumber('STALE-R')->withOrderNumber('STALE-R')
            ->withStatus('delivered')->build()]);
        /** @var MarketplaceReturnFactRepository $returns */
        $returns = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        $return = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withSourceRowId('STALE-R-RETURN')->withSourceId(991118)
            ->withOrderNumber('STALE-R')->withMarketplaceSku('SKU')
            ->withReturnType('ClientReturn');
        $returns->upsertAll([$return->withQuantity(2)->build()]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection));
        $connection->insert('planning_ingestion_source_row_run', PlanningIngestionSourceRowRunBuilder::aSourceRowRun()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)->withSourceRowId('STALE-R|SKU')->row());
        foreach (['2' => '2026-09-20 11:00:00', '3' => '2026-09-20 12:00:00'] as $key => $knownAt) {
            $observation = PlanningIngestionResolutionObservationBuilder::anObservation()
                ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withAllocation('STALE-R|SKU', (string) $key, 'R')
                ->withDateLineage('stale-return-'.$key)
                ->withFirstKnownOutcomeAt(new \DateTimeImmutable($knownAt))
                ->withBackfill(false);
            if ('3' === (string) $key) {
                $observation = $observation->withFirstRegularlyObservedAt((new \DateTimeImmutable($knownAt))->modify('+30 minutes'));
            }
            $connection->insert('planning_ingestion_resolution_observation', $observation->row());
        }

        $sales->upsertAll([$sale->withQuantity(2)->build()]);
        $writer->recordCompleted($company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'R shifts to keys 1 and 2'), 'rescan', [], ['STALE-R|SKU']);
        $sales->upsertAll([$sale->withQuantity(3)->build()]);
        $returns->upsertAll([$return->withQuantity(3)->build()]);
        $writer->recordCompleted($company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'stale R key 3 reappears'), 'rescan', [], ['STALE-R|SKU']);
        self::assertSame(
            [['allocation_key' => '1', 'first_known_outcome_at' => '2026-09-20 11:00:00', 'first_regularly_observed_at' => null],
                ['allocation_key' => '2', 'first_known_outcome_at' => '2026-09-20 12:00:00', 'first_regularly_observed_at' => '2026-09-20 12:30:00'],
                ['allocation_key' => '3', 'first_known_outcome_at' => null, 'first_regularly_observed_at' => null]],
            $connection->fetchAllAssociative(
                "SELECT allocation_key, first_known_outcome_at, first_regularly_observed_at FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND outcome = 'R' ORDER BY allocation_key",
                [$company, $account, 'STALE-R|SKU'],
            ),
        );

        $sales->upsertAll([$sale->withQuantity(1)->build()]);
        $returns->upsertAll([$return->withQuantity(1)->build()]);
        $writer->recordCompleted($company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'only R key 1 remains'), 'rescan', [], ['STALE-R|SKU']);
        $sales->upsertAll([$sale->withQuantity(2)->build()]);
        $writer->recordCompleted($company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'stale R key 2 returns'), 'rescan', [], ['STALE-R|SKU']);
        self::assertSame(
            ['first_known_outcome_at' => '2026-09-20 11:00:00', 'first_regularly_observed_at' => null],
            $connection->fetchAssociative(
                "SELECT first_known_outcome_at, first_regularly_observed_at FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND allocation_key = '2' AND outcome = 'R'",
                [$company, $account, 'STALE-R|SKU'],
            ),
        );
        $planning = new IngestionPlanningFacade(new PlanningOrderCohortsQuery($connection), new PlanningCohortProvenanceQuery($connection), new PlanningSourceStateQuery($connection), new PlanningResolutionsQuery($connection), new PlanningMarketplaceSkusQuery($connection), new PlanningOutcomeQueryGuard($connection), 'test-secret');
        $totals = $planning->planningResolutionTotals($company, $account, ['SKU'], new \DateTimeImmutable('2026-09-20'), new \DateTimeImmutable('2026-09-20'), PlanningObservationDateAxis::FIRST_KNOWN_OUTCOME);
        self::assertCount(1, $totals->items);
        self::assertSame(1, $totals->items[0]->returned);
    }

    public function testEarlierOutcomeAddedByBackfillDoesNotEraseKnownLaterOutcomeDate(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $company = $companyId->toRfc4122();
        $account = $accountId->toRfc4122();
        self::seedCompletedBaseline($companyId, $accountId);

        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([SalesFactBuilder::aSalesFact()->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)->withSourceRowId('SHIFTED-T2|SKU')
            ->withPostingNumber('SHIFTED-T2')->withOrderNumber('SHIFTED-T2')
            ->withMarketplaceSku('SKU')->withQuantity(2)
            ->withBusinessDate(new \DateTimeImmutable('2026-09-20'))->build()]);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($company, [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withPostingNumber('SHIFTED-T2')->withOrderNumber('SHIFTED-T2')
            ->withStatus('cancelled')->withObservedAt(new \DateTimeImmutable('2026-09-20 10:00:00'))->build()]);
        /** @var MarketplaceReturnFactRepository $returns */
        $returns = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        $returns->upsertAll([
            MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
                ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withSourceRowId('SHIFTED-T2-RETURN')->withSourceId(991109)
                ->withOrderNumber('SHIFTED-T2')->withMarketplaceSku('SKU')
                ->withReturnType('Cancellation')->withReturnReasonName('Покупатель не забрал заказ')
                ->withQuantity(1)->build(),
            MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
                ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withSourceRowId('SHIFTED-T1-RETURN')->withSourceId(991110)
                ->withOrderNumber('SHIFTED-T2')->withMarketplaceSku('SKU')
                ->withReturnType('Cancellation')->withReturnReasonName('Покупатель отменил заказ')
                ->withQuantity(1)->build(),
        ]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection));
        $writer->recordCompleted($company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'before earlier outcome'), 'regular', [], ['SHIFTED-T2|SKU']);
        $knownDate = $connection->fetchOne('SELECT first_known_outcome_at FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND allocation_key = ? AND outcome = ?', [$company, $account, 'SHIFTED-T2|SKU', '1', 'T2']);
        self::assertIsString($knownDate);

        $statuses->recordChanged($company, [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withPostingNumber('SHIFTED-T2')->withOrderNumber('SHIFTED-T2')
            ->withStatus('awaiting_packaging')->withObservedAt(new \DateTimeImmutable('2026-09-19 11:00:00'))->build()]);
        self::assertSame(['awaiting_packaging', 'cancelled'], $connection->fetchFirstColumn('SELECT status FROM marketplace_posting_status WHERE company_id = ? AND marketplace_account_id = ? AND posting_number = ? ORDER BY observed_at', [$company, $account, 'SHIFTED-T2']));
        self::assertSame([
            ['outcome' => 'T1', 'quantity' => 1],
            ['outcome' => 'T2', 'quantity' => 1],
        ], $connection->fetchAllAssociative('SELECT outcome, quantity FROM buyout_outcome WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? ORDER BY outcome', [$company, $account, 'SHIFTED-T2|SKU']));
        $writer->recordCompleted($company, $account, 'postings', '2026-09-20', '2026-09-20', hash('sha256', 'after earlier outcome'), 'rescan', [], ['SHIFTED-T2|SKU']);
        $shifted = $connection->fetchAssociative('SELECT first_known_outcome_at, backfill FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND allocation_key = ? AND outcome = ?', [$company, $account, 'SHIFTED-T2|SKU', '2', 'T2']);
        self::assertNotFalse($shifted);
        self::assertSame($knownDate, $shifted['first_known_outcome_at']);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow'));
        $totals = (new PlanningResolutionsQuery($connection))->totals($company, $account, ['SKU'], $today, $today, PlanningObservationDateAxis::FIRST_KNOWN_OUTCOME->value)
            ->executeQuery()->fetchAssociative();
        self::assertNotFalse($totals);
        self::assertEquals(1, $totals['post_handover_no_buy']);
    }

    public function testReturnInheritsProvenBuyoutDateWithoutCreatingARescanSale(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $company = $companyId->toRfc4122();
        $account = $accountId->toRfc4122();
        $sourceRowId = 'TRANSITION|SKU-2';

        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([
            SalesFactBuilder::aSalesFact()->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withSourceRowId($sourceRowId)->withPostingNumber('TRANSITION')->withOrderNumber('TRANSITION')
                ->withMarketplaceSku('SKU-2')->withQuantity(3)->withBusinessDate(new \DateTimeImmutable('2026-09-10'))->build(),
        ]);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($company, [
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()->withCompanyId($companyId)
                ->withMarketplaceAccountId($accountId)->withPostingNumber('TRANSITION')
                ->withOrderNumber('TRANSITION')->withStatus('delivered')->build(),
        ]);
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        self::seedCompletedBaseline($companyId, $accountId, new \DateTimeImmutable('2026-09-09 10:00:00'));
        $writer = new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection));
        $writer->recordCompleted($company, $account, 'postings', '2026-09-10', '2026-09-10', hash('sha256', 'delivered'), 'regular', [], [$sourceRowId]);

        /** @var MarketplaceReturnFactRepository $returns */
        $returns = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        $returns->upsertAll([
            MarketplaceReturnFactBuilder::aMarketplaceReturnFact()->withCompanyId($companyId)
                ->withMarketplaceAccountId($accountId)->withSourceRowId('TRANSITION-RETURN')
                ->withSourceId(991102)->withOrderNumber('TRANSITION')->withMarketplaceSku('SKU-2')
                ->withReturnType('ClientReturn')->withQuantity(1)->build(),
        ]);
        $writer->recordCompleted($company, $account, 'returns', '2026-09-10', '2026-09-10', hash('sha256', 'return'), 'rescan', [],
            returnKeys: [['orderNumber' => 'TRANSITION', 'marketplaceSku' => 'SKU-2']]);

        $planning = new IngestionPlanningFacade(new PlanningOrderCohortsQuery($connection), new PlanningCohortProvenanceQuery($connection), new PlanningSourceStateQuery($connection), new PlanningResolutionsQuery($connection), new PlanningMarketplaceSkusQuery($connection), new PlanningOutcomeQueryGuard($connection), 'test-secret');
        $today = new \DateTimeImmutable('today');
        $totals = $planning->planningResolutionTotals($company, $account, ['SKU-2'], $today->modify('-1 day'), $today->modify('+1 day'), PlanningObservationDateAxis::FIRST_REGULAR_OBSERVATION);
        self::assertCount(1, $totals->items);
        self::assertSame(2, $totals->items[0]->delivered);
        self::assertSame(1, $totals->items[0]->returned);
        $observations = $planning->planningResolutionObservations($company, $account, ['SKU-2'], $today->modify('-1 day'), $today->modify('+1 day'), PlanningObservationDateAxis::FIRST_KNOWN_OUTCOME, 50, null);
        self::assertCount(3, $observations->items);
        self::assertSame(['D', 'D', 'R'], array_map(static fn ($item): string => $item->outcome, $observations->items));
        self::assertSame(['1', '2', '3'], array_map(static fn ($item): string => $item->allocationKey, $observations->items));
        self::assertSame($observations->items[0]->firstKnownOutcomeAt, $observations->items[2]->firstKnownOutcomeAt);
        $transition = $connection->fetchAllAssociative(
            'SELECT outcome, first_known_outcome_at FROM planning_ingestion_resolution_observation WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND allocation_key = ? ORDER BY outcome',
            [$company, $account, $sourceRowId, '3'],
        );
        self::assertCount(2, $transition);
        self::assertSame(['D', 'R'], array_column($transition, 'outcome'));
        self::assertSame($transition[0]['first_known_outcome_at'], $transition[1]['first_known_outcome_at']);
    }

    public function testCohortCursorPagesWithinAccountAndRejectsForeignCursor(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([
            SalesFactBuilder::aSalesFact()->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withSourceRowId('PAGE-1|SKU-1')->withPostingNumber('PAGE-1')->withOrderNumber('PAGE-1')
                ->withMarketplaceSku('SKU-1')->withBusinessDate(new \DateTimeImmutable('2026-09-10'))->build(),
            SalesFactBuilder::aSalesFact()->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withSourceRowId('PAGE-2|SKU-1')->withPostingNumber('PAGE-2')->withOrderNumber('PAGE-2')
                ->withMarketplaceSku('SKU-1')->withBusinessDate(new \DateTimeImmutable('2026-09-11'))->build(),
        ]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $planning = new IngestionPlanningFacade(new PlanningOrderCohortsQuery($connection), new PlanningCohortProvenanceQuery($connection), new PlanningSourceStateQuery($connection), new PlanningResolutionsQuery($connection), new PlanningMarketplaceSkusQuery($connection), new PlanningOutcomeQueryGuard($connection), 'test-secret');
        $from = new \DateTimeImmutable('2026-09-10');
        $to = new \DateTimeImmutable('2026-09-11');
        $first = $planning->planningOrderCohorts(
            $companyId->toRfc4122(), $accountId->toRfc4122(), ['SKU-1'], $from, $to, 1, null,
        );

        self::assertCount(1, $first->items);
        self::assertSame('2026-09-10', $first->items[0]->orderBusinessDate);
        self::assertNotNull($first->nextCursor);

        $second = $planning->planningOrderCohorts(
            $companyId->toRfc4122(), $accountId->toRfc4122(), ['SKU-1'], $from, $to, 1, $first->nextCursor,
        );
        self::assertSame('2026-09-11', $second->items[0]->orderBusinessDate);
        self::assertNull($second->nextCursor);

        (new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection)))->recordCompleted(
            $companyId->toRfc4122(), $accountId->toRfc4122(), 'postings',
            '2026-09-10', '2026-09-10', hash('sha256', 'new complete poll'), 'rescan', [],
        );
        try {
            $planning->planningOrderCohorts(
                $companyId->toRfc4122(), $accountId->toRfc4122(), ['SKU-1'], $from, $to, 1, $first->nextCursor,
            );
            self::fail('A cursor from an older source generation must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        $planning->planningOrderCohorts(
            $companyId->toRfc4122(), Uuid::v7()->toRfc4122(), ['SKU-1'], $from, $to, 1, $first->nextCursor,
        );
    }

    public function testCohortSeparatesPartialReturnOpenOrdersAndAnotherAccount(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $otherAccountId = Uuid::v7();
        $day = new \DateTimeImmutable('2026-09-10');

        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([
            SalesFactBuilder::aSalesFact()->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withSourceRowId('PLAN-DELIVERED|SKU-1')->withPostingNumber('PLAN-DELIVERED')
                ->withOrderNumber('PLAN-ORDER')->withMarketplaceSku('SKU-1')->withQuantity(3)
                ->withBusinessDate($day)->build(),
            SalesFactBuilder::aSalesFact()->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withSourceRowId('PLAN-OPEN|SKU-1')->withPostingNumber('PLAN-OPEN')
                ->withOrderNumber('PLAN-OPEN-ORDER')->withMarketplaceSku('SKU-1')->withQuantity(4)
                ->withBusinessDate($day)->withStatus('awaiting_packaging')->build(),
            SalesFactBuilder::aSalesFact()->withCompanyId($companyId)->withMarketplaceAccountId($otherAccountId)
                ->withSourceRowId('PLAN-FOREIGN|SKU-1')->withPostingNumber('PLAN-FOREIGN')
                ->withOrderNumber('PLAN-FOREIGN-ORDER')->withMarketplaceSku('SKU-1')->withQuantity(9)
                ->withBusinessDate($day)->build(),
            SalesFactBuilder::aSalesFact()->withCompanyId($companyId)->withMarketplaceAccountId($otherAccountId)
                ->withSourceRowId('PLAN-FOREIGN|SKU-FOREIGN')->withPostingNumber('PLAN-FOREIGN-2')
                ->withOrderNumber('PLAN-FOREIGN-ORDER-2')->withMarketplaceSku('SKU-FOREIGN')
                ->withBusinessDate($day)->build(),
        ]);

        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($companyId->toRfc4122(), [
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()->withCompanyId($companyId)
                ->withMarketplaceAccountId($accountId)->withPostingNumber('PLAN-DELIVERED')
                ->withOrderNumber('PLAN-ORDER')->withStatus('delivered')->build(),
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()->withCompanyId($companyId)
                ->withMarketplaceAccountId($accountId)->withPostingNumber('PLAN-OPEN')
                ->withOrderNumber('PLAN-OPEN-ORDER')->withStatus('awaiting_packaging')->build(),
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()->withCompanyId($companyId)
                ->withMarketplaceAccountId($otherAccountId)->withPostingNumber('PLAN-FOREIGN')
                ->withOrderNumber('PLAN-FOREIGN-ORDER')->withStatus('delivered')->build(),
        ]);

        /** @var MarketplaceReturnFactRepository $returns */
        $returns = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        $returns->upsertAll([
            MarketplaceReturnFactBuilder::aMarketplaceReturnFact()->withCompanyId($companyId)
                ->withMarketplaceAccountId($accountId)->withSourceRowId('PLAN-RETURN-1')
                ->withSourceId(991101)->withOrderNumber('PLAN-ORDER')->withMarketplaceSku('SKU-1')
                ->withReturnType('ClientReturn')->withQuantity(1)->build(),
        ]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $planning = new IngestionPlanningFacade(new PlanningOrderCohortsQuery($connection), new PlanningCohortProvenanceQuery($connection), new PlanningSourceStateQuery($connection), new PlanningResolutionsQuery($connection), new PlanningMarketplaceSkusQuery($connection), new PlanningOutcomeQueryGuard($connection), 'test-secret');
        $page = $planning->planningOrderCohorts(
            $companyId->toRfc4122(), $accountId->toRfc4122(), ['SKU-1'], $day, $day, 50, null,
        );

        self::assertCount(1, $page->items);
        self::assertSame('SKU-1', $page->items[0]->marketplaceSku);
        self::assertSame(7, $page->items[0]->ordered);
        self::assertSame(3, $page->items[0]->bought);
        self::assertSame(4, $page->items[0]->openEligible);
        self::assertSame(0, $page->items[0]->terminalNoBuy);
        self::assertSame(0, $page->items[0]->unknown);
        self::assertNull($page->nextCursor);

        $writer = new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection));
        self::seedCompletedBaseline($companyId, $accountId, new \DateTimeImmutable('2026-09-09 10:00:00'));
        $writer->recordCompleted(
            $companyId->toRfc4122(), $accountId->toRfc4122(), 'postings', '2026-09-10', '2026-09-10',
            hash('sha256', 'complete-postings'), 'regular', [],
            ['PLAN-DELIVERED|SKU-1', 'PLAN-OPEN|SKU-1'],
        );
        $today = new \DateTimeImmutable('today');
        $totals = $planning->planningResolutionTotals(
            $companyId->toRfc4122(), $accountId->toRfc4122(), ['SKU-1'],
            $today->modify('-1 day'), $today->modify('+1 day'), PlanningObservationDateAxis::FIRST_KNOWN_OUTCOME,
        );
        self::assertCount(1, $totals->items);
        self::assertSame(2, $totals->items[0]->delivered);
        self::assertSame(1, $totals->items[0]->returned);
        self::assertSame('1:1', $totals->sourceVersion);
        $incomplete = $planning->planningOrderCohorts(
            $companyId->toRfc4122(), $accountId->toRfc4122(), ['SKU-1'], $day, $day, 50, null,
        );
        self::assertFalse($incomplete->complete);
        self::assertNull($incomplete->lastCompleteAt);
        $writer->recordCompleted(
            $companyId->toRfc4122(), $accountId->toRfc4122(), 'returns', '2026-09-10', '2026-09-10',
            hash('sha256', 'complete-returns'), 'rescan', [],
        );
        self::assertTrue($planning->planningOrderCohorts(
            $companyId->toRfc4122(), $accountId->toRfc4122(), ['SKU-1'], $day, $day, 50, null,
        )->complete);

        $observations = $planning->planningResolutionObservations(
            $companyId->toRfc4122(), $accountId->toRfc4122(), ['SKU-1'],
            $today->modify('-1 day'), $today->modify('+1 day'), PlanningObservationDateAxis::FIRST_REGULAR_OBSERVATION,
            1, null,
        );
        self::assertCount(1, $observations->items);
        self::assertNotNull($observations->nextCursor);
        $next = $planning->planningResolutionObservations(
            $companyId->toRfc4122(), $accountId->toRfc4122(), ['SKU-1'],
            $today->modify('-1 day'), $today->modify('+1 day'), PlanningObservationDateAxis::FIRST_REGULAR_OBSERVATION,
            1, $observations->nextCursor,
        );
        self::assertCount(1, $next->items);
        self::assertNotNull($next->nextCursor);
        $last = $planning->planningResolutionObservations(
            $companyId->toRfc4122(), $accountId->toRfc4122(), ['SKU-1'],
            $today->modify('-1 day'), $today->modify('+1 day'), PlanningObservationDateAxis::FIRST_REGULAR_OBSERVATION,
            1, $next->nextCursor,
        );
        self::assertCount(1, $last->items);
        self::assertNull($last->nextCursor);
        self::assertSame(['1', '2', '3'], [
            $observations->items[0]->allocationKey, $next->items[0]->allocationKey, $last->items[0]->allocationKey,
        ]);
        self::assertSame(['SKU-1'], $planning->knownMarketplaceSkus(
            $companyId->toRfc4122(), $accountId->toRfc4122(), ['SKU-1', 'SKU-FOREIGN'],
        ));
        $search = $planning->searchMarketplaceSkus($companyId->toRfc4122(), $accountId->toRfc4122(), 'SKU', 50, null);
        self::assertCount(1, $search->items);
        self::assertSame('SKU-1', $search->items[0]->marketplaceSku);
    }

    public function testSearchFindsHistoricalSkuRegardlessOfLetterCase(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([SalesFactBuilder::aSalesFact()->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)->withSourceRowId('HISTORIC-CASE|historic-sku')
            ->withPostingNumber('HISTORIC-CASE')->withOrderNumber('HISTORIC-CASE')
            ->withMarketplaceSku('historic-sku')->build()]);
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $planning = new IngestionPlanningFacade(new PlanningOrderCohortsQuery($connection), new PlanningCohortProvenanceQuery($connection), new PlanningSourceStateQuery($connection), new PlanningResolutionsQuery($connection), new PlanningMarketplaceSkusQuery($connection), new PlanningOutcomeQueryGuard($connection), 'test-secret');
        self::assertSame(['historic-sku'], $planning->knownMarketplaceSkus(
            $companyId->toRfc4122(), $accountId->toRfc4122(), ['historic-sku'],
        ));

        $page = $planning->searchMarketplaceSkus($companyId->toRfc4122(), $accountId->toRfc4122(), 'HISTORIC', 50, null);
        self::assertCount(1, $page->items);
        self::assertSame('historic-sku', $page->items[0]->marketplaceSku);
    }

    public function testPlanningReadsDoNotCrossCompaniesSharingAnAccountId(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $otherCompanyId = Uuid::v7();
        $accountId = Uuid::v7();
        $day = new \DateTimeImmutable('2026-09-10');
        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([
            SalesFactBuilder::aSalesFact()->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withSourceRowId('TENANT-A|SHARED')->withPostingNumber('TENANT-A')->withOrderNumber('TENANT-A')
                ->withMarketplaceSku('SHARED')->withQuantity(2)->withBusinessDate($day)->build(),
            SalesFactBuilder::aSalesFact()->withCompanyId($otherCompanyId)->withMarketplaceAccountId($accountId)
                ->withSourceRowId('TENANT-B|SHARED')->withPostingNumber('TENANT-B')->withOrderNumber('TENANT-B')
                ->withMarketplaceSku('SHARED')->withQuantity(9)->withBusinessDate($day)->build(),
            SalesFactBuilder::aSalesFact()->withCompanyId($otherCompanyId)->withMarketplaceAccountId($accountId)
                ->withSourceRowId('TENANT-B|PRIVATE-B')->withPostingNumber('TENANT-B-PRIVATE')->withOrderNumber('TENANT-B-PRIVATE')
                ->withMarketplaceSku('PRIVATE-B')->withBusinessDate($day)->build(),
        ]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $planning = new IngestionPlanningFacade(new PlanningOrderCohortsQuery($connection), new PlanningCohortProvenanceQuery($connection), new PlanningSourceStateQuery($connection), new PlanningResolutionsQuery($connection), new PlanningMarketplaceSkusQuery($connection), new PlanningOutcomeQueryGuard($connection), 'test-secret');
        $own = $planning->planningOrderCohorts($companyId->toRfc4122(), $accountId->toRfc4122(), ['SHARED'], $day, $day);
        $other = $planning->planningOrderCohorts($otherCompanyId->toRfc4122(), $accountId->toRfc4122(), ['SHARED'], $day, $day);
        self::assertCount(1, $own->items);
        self::assertSame(2, $own->items[0]->ordered);
        self::assertCount(1, $other->items);
        self::assertSame(9, $other->items[0]->ordered);
        self::assertSame([], $planning->knownMarketplaceSkus($companyId->toRfc4122(), $accountId->toRfc4122(), ['PRIVATE-B']));
        self::assertSame([], $planning->searchMarketplaceSkus($companyId->toRfc4122(), $accountId->toRfc4122(), 'PRIVATE-B')->items);
    }

    public function testCohortRejectsAnUnboundedPage(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $planning = new IngestionPlanningFacade(new PlanningOrderCohortsQuery($connection), new PlanningCohortProvenanceQuery($connection), new PlanningSourceStateQuery($connection), new PlanningResolutionsQuery($connection), new PlanningMarketplaceSkusQuery($connection), new PlanningOutcomeQueryGuard($connection), 'test-secret');
        $day = new \DateTimeImmutable('2026-09-10');

        $this->expectException(\InvalidArgumentException::class);
        $planning->planningOrderCohorts(Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), ['SKU'], $day, $day, 201);
    }

    public function testResolutionCursorKeepsRowsWithTheSameTimestampAndAllocationKey(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([
            SalesFactBuilder::aSalesFact()->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withSourceRowId('CURSOR-A|SKU')->withPostingNumber('CURSOR-A')->withOrderNumber('CURSOR-A')
                ->withMarketplaceSku('SKU')->build(),
            SalesFactBuilder::aSalesFact()->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withSourceRowId('CURSOR-B|SKU')->withPostingNumber('CURSOR-B')->withOrderNumber('CURSOR-B')
                ->withMarketplaceSku('SKU')->build(),
        ]);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($companyId->toRfc4122(), [
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()->withCompanyId($companyId)
                ->withMarketplaceAccountId($accountId)->withPostingNumber('CURSOR-A')->withOrderNumber('CURSOR-A')
                ->withStatus('delivered')->build(),
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()->withCompanyId($companyId)
                ->withMarketplaceAccountId($accountId)->withPostingNumber('CURSOR-B')->withOrderNumber('CURSOR-B')
                ->withStatus('delivered')->build(),
        ]);
        self::seedCompletedBaseline($companyId, $accountId, new \DateTimeImmutable('2026-06-30 10:00:00'));
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new PlanningSourceStateWriter($connection, new PlanningOutcomeQueryGuard($connection));
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow'));
        $day = $today->format('Y-m-d');
        $writer->recordCompleted($companyId->toRfc4122(), $accountId->toRfc4122(), 'postings',
            $day, $day, hash('sha256', 'cursor same timestamp'), 'regular', [], ['CURSOR-A|SKU', 'CURSOR-B|SKU']);

        $planning = new IngestionPlanningFacade(new PlanningOrderCohortsQuery($connection), new PlanningCohortProvenanceQuery($connection), new PlanningSourceStateQuery($connection), new PlanningResolutionsQuery($connection), new PlanningMarketplaceSkusQuery($connection), new PlanningOutcomeQueryGuard($connection), 'test-secret');
        $first = $planning->planningResolutionObservations($companyId->toRfc4122(), $accountId->toRfc4122(), ['SKU'],
            $today, $today, PlanningObservationDateAxis::FIRST_KNOWN_OUTCOME, 1);
        self::assertCount(1, $first->items);
        self::assertNotNull($first->nextCursor);
        $second = $planning->planningResolutionObservations($companyId->toRfc4122(), $accountId->toRfc4122(), ['SKU'],
            $today, $today, PlanningObservationDateAxis::FIRST_KNOWN_OUTCOME, 1, $first->nextCursor);
        self::assertCount(1, $second->items);
        self::assertNull($second->nextCursor);
        self::assertSame('1', $first->items[0]->allocationKey);
        self::assertSame('1', $second->items[0]->allocationKey);
        self::assertSame($first->items[0]->firstKnownOutcomeAt, $second->items[0]->firstKnownOutcomeAt);
        self::assertNotSame($first->items[0]->sourceRowId, $second->items[0]->sourceRowId);
    }

    public function testCohortRejectsAnOversizedWindow(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $planning = new IngestionPlanningFacade(new PlanningOrderCohortsQuery($connection), new PlanningCohortProvenanceQuery($connection), new PlanningSourceStateQuery($connection), new PlanningResolutionsQuery($connection), new PlanningMarketplaceSkusQuery($connection), new PlanningOutcomeQueryGuard($connection), 'test-secret');

        $this->expectException(\InvalidArgumentException::class);
        $planning->planningOrderCohorts(Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), ['SKU'], new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2027-01-02'));
    }

    private static function seedCompletedBaseline(Uuid $companyId, Uuid $accountId, ?\DateTimeImmutable $completedAt = null): void
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $completedAt ??= new \DateTimeImmutable('2026-09-19 10:00:00');
        $connection->insert('planning_ingestion_account_state', PlanningIngestionAccountStateBuilder::anAccountState()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withUpdatedAt($completedAt)->withBaselineCompletedAt($completedAt)->row());
    }
}
