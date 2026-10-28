<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\FactCaptureOrigin;
use App\Ingestion\Infrastructure\Query\NetForecast\GrossTrainingAggregateQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingLimits;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingRequest;
use App\Ingestion\Infrastructure\Query\NetForecast\ReturnTrainingAggregateQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\ReturnTrainingAggregateRow;
use App\Tests\Support\Ingestion\NetTrainingFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NetTrainingSelectionQueryTest extends KernelTestCase
{
    public function testAnUnknownCancellationCannotSupplyTheThirtiethMaturityObservation(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $f = new NetTrainingFixture($db);
        for ($i = 0; $i < 29; ++$i) {
            $f->posting('KNOWN-'.$i, 'REQUESTED', 1);
        }
        $f->posting('CANCEL', 'OTHER', 1, 'cancelled', cancellationReason: 'unmapped cancellation reason', businessDay: $f->initial->modify('-1 day'));
        $context = new NetTrainingContext($f->company->toRfc4122(), $f->account->toRfc4122(), $f->readAt, $f->epoch);
        $read = static fn (): array => array_map(GrossTrainingAggregateQuery::mapRow(...), (new GrossTrainingAggregateQuery($db))->build($context->companyId, $context->accountId, $context, new NetTrainingRequest(['REQUESTED']))->executeQuery()->fetchAllAssociative());
        $unknown = $read()[0];
        self::assertFalse($unknown->inputComplete);
        self::assertNull($unknown->p95Seconds);
        self::assertNull($unknown->sampleQuantity);
        $f->returned('CANCEL', 'OTHER', 1, $f->terminal->modify('+2 hours'), 'Cancellation', 'Покупатель отменил заказ');
        $known = $read()[0];
        self::assertTrue($known->inputComplete);
        self::assertNotNull($known->p95Seconds);
        self::assertSame(30, $known->sampleQuantity);
        self::assertSame(29, $known->grossQuantity);
        self::assertSame(1, $known->t1Quantity);
    }

    public function testIntradayTerminalReceiptsCannotProveAFullMskOrderDay(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $f = new NetTrainingFixture($db);
        for ($i = 0; $i < 31; ++$i) {
            $f->posting('INTRADAY-'.$i, 'REQUESTED', 1, terminalAt: $f->initial->modify('+12 hours'));
        }
        $context = new NetTrainingContext($f->company->toRfc4122(), $f->account->toRfc4122(), $f->readAt, $f->epoch);
        $read = static fn (): array => array_map(GrossTrainingAggregateQuery::mapRow(...), (new GrossTrainingAggregateQuery($db))->build($context->companyId, $context->accountId, $context, new NetTrainingRequest(['REQUESTED']))->executeQuery()->fetchAllAssociative());
        self::assertFalse($read()[0]->inputComplete);
        self::assertNull($read()[0]->sampleQuantity);
        $dayEnd = $f->initial->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0, 0)->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'));
        $f->postingsDayReceipt($dayEnd->modify('-1 second'));
        self::assertFalse($read()[0]->inputComplete);
        $f->postingsDayReceipt($dayEnd->modify('+1 second'));
        $complete = $read()[0];
        self::assertSame($f->initial->format('Y-m-d'), $dayEnd->modify('+1 second')->format('Y-m-d'));
        self::assertTrue($complete->inputComplete);
        self::assertSame(31, $complete->sampleQuantity);
        self::assertSame(31, $complete->grossQuantity);
        self::assertSame(0, $complete->p95Seconds);
    }

    public function testALaterLivePollCannotInventAnInitialLiveObservationForReplayOrBackfill(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $f = new NetTrainingFixture($db);
        $f->posting('LIVE', 'REQUESTED', 10);
        foreach ([FactCaptureOrigin::HistoricalBackfill, FactCaptureOrigin::RawReplay, FactCaptureOrigin::Unknown] as $origin) {
            $f->posting($origin->value, 'REQUESTED', 20, initialOrigin: $origin);
        }
        $f->returnCoverage();
        $rows = $this->returns($db, $f);
        self::assertSame(10, $rows[0]->sampleQuantity);
        self::assertSame(1, $rows[0]->samplePostings);
        self::assertSame(3, $rows[0]->excludedGroups);
    }

    public function testAnUnknownReturnTypeCannotBecomeAKnownNonClientPredecessor(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $f = new NetTrainingFixture($db);
        $f->posting('ORDER', 'REQUESTED', 10);
        $f->returned('ORDER', 'REQUESTED', 2, $f->terminal->modify('+5 days'), 'UnrecognizedReturnType');
        $f->returned('ORDER', 'REQUESTED', 2, $f->terminal->modify('+15 days'));
        $f->returnCoverage();
        $rows = $this->returns($db, $f);
        self::assertSame(0, $rows[0]->sampleQuantity);
        self::assertSame(1, $rows[0]->excludedGroups);
    }

    public function testCompanionMetadataNeedsBothAKnownMeaningAndAnAcceptedSourceAnchor(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $f = new NetTrainingFixture($db);
        foreach (['VALID' => 10, 'UNKNOWN' => 20, 'UNANCHORED' => 30] as $key => $quantity) {
            $f->posting($key, 'REQUESTED', $quantity);
            $f->deliveryMetadata($key, 'REQUESTED', $quantity, $f->terminal->modify('+5 days'), 'UNKNOWN' === $key ? 'unknown_delivery_metadata' : 'posting_delivered', 'UNANCHORED' !== $key);
        }
        $f->returnCoverage();
        $rows = $this->returns($db, $f);
        self::assertSame(10, $rows[0]->sampleQuantity);
        self::assertSame(2, $rows[0]->excludedGroups);
        self::assertNotNull($rows[0]->ageProfile);
        self::assertSame(10, $rows[0]->ageProfile[60]->atRiskQuantity);
    }

    public function testSaleLinkMoveCannotLeaveASeeminglyCompleteOldOrderBehind(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $f = new NetTrainingFixture($db);
        $f->posting('MOVED', 'REQUESTED', 10, order: 'OLD');
        $f->posting('KEEP', 'REQUESTED', 20, order: 'OLD');
        $f->posting('OTHER', 'REQUESTED', 30, order: 'NEW');
        $f->priceCorrection('MOVED', 'REQUESTED', 10, $f->terminal->modify('+10 days'), order: 'NEW');
        $f->returnCoverage();
        $rows = $this->returns($db, $f);
        self::assertSame(0, $rows[0]->sampleQuantity);
        self::assertSame(2, $rows[0]->excludedGroups);
    }

    public function testDeepReceiptUsesEachExactOriginEvenWhenDailyVisualWindowsMatch(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $f = new NetTrainingFixture($db);
        $f->posting('EARLY', 'REQUESTED', 10);
        $f->posting('LATE', 'OTHER', 20, terminalAt: $f->terminal->modify('+12 hours'));
        $f->returnCoverage(deepAt: $f->terminal->modify('+67 days -1 second'));
        self::assertSame(0, $this->returns($db, $f)[0]->sampleQuantity);
        $f->returnDeepReceipt($f->terminal->modify('+67 days'));
        self::assertSame(10, $this->returns($db, $f)[0]->sampleQuantity);
        $f->returnDeepReceipt($f->terminal->modify('+67 days +12 hours'));
        self::assertSame(30, $this->returns($db, $f)[0]->sampleQuantity);
    }

    public function testGrossComponentsMatchTheUnchangedCanonicalViewForEveryTerminalOutcome(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $f = new NetTrainingFixture($db);
        for ($i = 0; $i < 31; ++$i) {
            $f->posting('G-'.$i, 'REQUESTED', 2);
        }
        $f->returned('G-0', 'REQUESTED', 2, $f->terminal->modify('+5 days'));
        $f->posting('T1', 'OTHER', 3, 'cancelled');
        $f->posting('T2', 'OTHER', 4, 'cancelled', handedOver: true);
        $f->posting('PART-D', 'OTHER', 5, order: 'PARTIAL');
        $f->posting('PART-P', 'OTHER', 6, 'cancelled', order: 'PARTIAL', handedOver: true, cancellationReason: 'Покупатель отказался при вручении: товар не подошел');
        $canonical = $db->fetchAllKeyValue('SELECT outcome, SUM(quantity)::bigint FROM buyout_outcome WHERE company_id = ? AND marketplace_account_id = ? GROUP BY outcome', [$f->company->toRfc4122(), $f->account->toRfc4122()]);
        self::assertEqualsCanonicalizing(['D' => 65, 'R' => 2, 'T1' => 3, 'T2' => 4, 'P' => 6], $canonical);
        $context = new NetTrainingContext($f->company->toRfc4122(), $f->account->toRfc4122(), $f->readAt, $f->epoch);
        $rows = array_map(GrossTrainingAggregateQuery::mapRow(...), (new GrossTrainingAggregateQuery($db))->build($context->companyId, $context->accountId, $context, new NetTrainingRequest(['REQUESTED']))->executeQuery()->fetchAllAssociative());
        self::assertTrue($rows[0]->inputComplete);
        self::assertSame(80, $rows[0]->sampleQuantity);
        self::assertIsInt($canonical['D']);
        self::assertIsInt($canonical['R']);
        self::assertSame($canonical['D'] + $canonical['R'], $rows[0]->grossQuantity);
        self::assertSame($canonical['T1'], $rows[0]->t1Quantity);
        self::assertSame($canonical['T2'], $rows[0]->t2Quantity);
        self::assertSame($canonical['P'], $rows[0]->pQuantity);
        self::assertSame(35, $rows[0]->samplePostings);
        self::assertSame(34, $rows[0]->postHandoverPostings);
    }

    public function testSparseOrderAgesPreserveUnchangedGroupsAndAllSkuPrefixes(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $f = new NetTrainingFixture($db);
        $f->posting('EARLY', 'REQUESTED', 10);
        $f->posting('LATE', 'OTHER', 20);
        $f->posting('NONE', 'OTHER', 30);
        $f->returned('EARLY', 'REQUESTED', 3, $f->terminal->modify('+5 days'));
        $f->returned('LATE', 'OTHER', 20, $f->terminal->modify('+15 days'));
        $f->returnCoverage();
        $rows = $this->returns($db, $f);
        self::assertNotNull($rows[0]->ageProfile);
        self::assertSame(60, $rows[0]->ageProfile[0]->atRiskQuantity);
        self::assertSame(57, $rows[0]->ageProfile[6]->atRiskQuantity);
        self::assertSame(37, $rows[0]->ageProfile[16]->atRiskQuantity);
        self::assertSame(23, $rows[0]->ageProfile[60]->returnedQuantity);
        self::assertSame(2, $rows[0]->ageProfile[60]->atRiskPostingsLowerBound);
        self::assertNotNull($rows[1]->ageProfile);
        self::assertSame(7, $rows[1]->ageProfile[60]->atRiskQuantity);
        self::assertSame(1, $rows[1]->ageProfile[60]->atRiskPostingsLowerBound);
    }

    public function testQuantityChangesAndFutureAcceptedRevisionsCannotReuseAnOldDeliveredState(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $f = new NetTrainingFixture($db);
        $f->posting('QUANTITY', 'REQUESTED', 10);
        $f->priceCorrection('QUANTITY', 'REQUESTED', 9, $f->terminal->modify('+10 days'));
        $f->posting('FUTURE', 'OTHER', 20);
        $f->priceCorrection('FUTURE', 'OTHER', 20, $f->readAt->modify('+1 day'));
        $f->posting('DECREASE', 'OTHER', 30);
        $f->returned('DECREASE', 'OTHER', 3, $f->terminal->modify('+5 days'));
        $f->returned('DECREASE', 'OTHER', 2, $f->terminal->modify('+15 days'));
        $f->returnCoverage();
        $rows = $this->returns($db, $f);
        self::assertSame(0, $rows[0]->sampleQuantity);
        self::assertSame(3, $rows[0]->excludedGroups);
    }

    public function testReturnLinkMoveInvalidatesBothOldAndNewOrderSkuGroups(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $f = new NetTrainingFixture($db);
        $f->posting('OLD', 'REQUESTED', 10);
        $f->posting('NEW', 'OTHER', 20);
        $f->returned('OLD', 'REQUESTED', 2, $f->terminal->modify('+5 days'), sourceKey: 'MOVED');
        $f->returned('NEW', 'OTHER', 2, $f->terminal->modify('+15 days'), sourceKey: 'MOVED');
        $f->returnCoverage();
        $rows = $this->returns($db, $f);
        self::assertSame(0, $rows[0]->sampleQuantity);
        self::assertSame(2, $rows[0]->excludedGroups);
    }

    public function testUnlinkedSourceInsideAKnownPostingCannotDisappearFromTheAccountPopulation(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $f = new NetTrainingFixture($db);
        $f->posting('KNOWN', 'REQUESTED', 10, order: 'ORDER', posting: 'P');
        $f->posting('UNLINKED', 'REQUESTED', 20, order: 'ORDER', posting: 'P', missingOrder: true);
        $f->returnCoverage();
        self::assertSame(0, $this->returns($db, $f)[0]->sampleQuantity);
    }

    public function testPriceOnlyRevisionWithStatusNoopRetainsTheProvenFirstDeliveryOrigin(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $f = new NetTrainingFixture($db);
        $f->posting('ORDER', 'REQUESTED', 10);
        $f->returned('ORDER', 'REQUESTED', 2, $f->terminal->modify('+5 days'));
        self::assertSame(0, $f->priceCorrection('ORDER', 'REQUESTED', 10, $f->terminal->modify('+10 days')));
        $f->returnCoverage();
        $rows = $this->returns($db, $f);
        self::assertSame(10, $rows[0]->sampleQuantity);
        self::assertNotNull($rows[0]->ageProfile);
        self::assertSame(2, $rows[0]->ageProfile[6]->returnedQuantity);
        self::assertSame(0, $rows[0]->excludedGroups);
    }

    public function testDeepCatchupCannotRestoreAMissingTimelyDailyObservation(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $f = new NetTrainingFixture($db);
        $f->posting('ORDER', 'REQUESTED', 10);
        $f->returnCoverage([5]);
        $rows = $this->returns($db, $f);
        self::assertSame(0, $rows[0]->sampleQuantity);
        self::assertSame(1, $rows[0]->excludedGroups);
    }

    public function testSameSecondSuccessFailureSuccessRequiresAnUnambiguousLaterSuccess(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $f = new NetTrainingFixture($db);
        $f->posting('ORDER', 'REQUESTED', 10);
        $f->returnCoverage();
        $at = $f->terminal->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0, 0)->modify('+6 days +1 hour');
        $f->returnDayReceipt(5, $at, false);
        $f->returnDayReceipt(5, $at);
        self::assertSame(0, $this->returns($db, $f)[0]->sampleQuantity);
        $f->returnDayReceipt(5, $at->modify('+1 second'));
        self::assertSame(10, $this->returns($db, $f)[0]->sampleQuantity);
    }

    public function testMultiplePostingsUseACapacityBoundWithoutInventingReturnAllocation(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $f = new NetTrainingFixture($db);
        $f->posting('P1', 'REQUESTED', 3, order: 'BIG');
        $f->posting('P2', 'REQUESTED', 1, order: 'BIG');
        foreach (['P3', 'P4', 'P5'] as $posting) {
            $f->posting($posting, 'REQUESTED', 1, order: 'ONES');
        }
        $f->returned('BIG', 'REQUESTED', 1, $f->terminal->modify('+5 days'));
        $f->returned('ONES', 'REQUESTED', 1, $f->terminal->modify('+5 days'));
        $f->returnCoverage();
        $rows = $this->returns($db, $f);
        self::assertSame(7, $rows[0]->sampleQuantity);
        self::assertSame(5, $rows[0]->samplePostings);
        self::assertNotNull($rows[0]->ageProfile);
        self::assertSame(5, $rows[0]->ageProfile[0]->atRiskPostingsLowerBound);
        self::assertSame(3, $rows[0]->ageProfile[6]->atRiskPostingsLowerBound);
        self::assertSame(5, $rows[0]->ageProfile[6]->atRiskQuantity);
    }

    public function testSharedPostingIdsAreDeduplicatedAcrossSkuAndGuaranteedIdsStrengthenTheOrderBound(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $f = new NetTrainingFixture($db);
        $f->posting('X1', 'REQUESTED', 10, order: 'ORDER', posting: 'P1');
        $f->posting('X2', 'REQUESTED', 1, order: 'ORDER', posting: 'P2');
        $f->posting('Y1', 'OTHER', 1, order: 'ORDER', posting: 'P1');
        $f->posting('Y2', 'OTHER', 10, order: 'ORDER', posting: 'P2');
        foreach (['REQUESTED', 'OTHER'] as $sku) {
            $f->returned('ORDER', $sku, 1, $f->terminal->modify('+5 days'));
            $f->returned('ORDER', $sku, 11, $f->terminal->modify('+20 days'));
        }
        $f->returnCoverage();
        $rows = $this->returns($db, $f);
        self::assertSame(22, $rows[0]->sampleQuantity);
        self::assertSame(2, $rows[0]->samplePostings);
        self::assertNotNull($rows[0]->ageProfile);
        self::assertSame(2, $rows[0]->ageProfile[0]->atRiskPostingsLowerBound);
        self::assertSame(2, $rows[0]->ageProfile[6]->atRiskPostingsLowerBound);
        self::assertSame(0, $rows[0]->ageProfile[21]->atRiskPostingsLowerBound);
        self::assertSame(0, $rows[0]->ageProfile[21]->atRiskQuantity);
        self::assertNotNull($rows[1]->ageProfile);
        self::assertSame(1, $rows[1]->ageProfile[6]->atRiskPostingsLowerBound);
    }

    public function testDifferentDeliveryOriginsRemainUnavailableInsteadOfReceivingOneInventedAge(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $f = new NetTrainingFixture($db);
        $f->posting('P1', 'REQUESTED', 3, order: 'ORDER');
        $f->posting('P2', 'REQUESTED', 1, order: 'ORDER', terminalAt: $f->terminal->modify('+1 day'));
        $f->returnCoverage();
        $rows = $this->returns($db, $f);
        self::assertSame(0, $rows[0]->sampleQuantity);
        self::assertSame(1, $rows[0]->excludedGroups);
    }

    public function testAClientTypeStartsAtItsFirstAcceptedRevisionAndReverseTypeCannotTrain(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $f = new NetTrainingFixture($db);
        $f->posting('VALID', 'REQUESTED', 10);
        $f->returned('VALID', 'REQUESTED', 2, $f->terminal->modify('+5 days'), 'Cancellation', 'Покупатель отменил заказ');
        $f->returned('VALID', 'REQUESTED', 2, $f->terminal->modify('+15 days'));
        $f->posting('REVERSE', 'OTHER', 20);
        $f->returned('REVERSE', 'OTHER', 2, $f->terminal->modify('+5 days'));
        $f->returned('REVERSE', 'OTHER', 2, $f->terminal->modify('+15 days'), 'Cancellation', 'Покупатель отменил заказ');
        $f->returnCoverage();
        $rows = $this->returns($db, $f);
        self::assertSame(10, $rows[0]->sampleQuantity);
        self::assertSame(1, $rows[0]->excludedGroups);
        self::assertNotNull($rows[0]->ageProfile);
        self::assertSame(0, $rows[0]->ageProfile[6]->returnedQuantity);
        self::assertSame(2, $rows[0]->ageProfile[16]->returnedQuantity);
    }

    public function testCompanyAndAccountScopesCannotContributeTheirReturnsOrReceiptProof(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $ours = new NetTrainingFixture($db);
        $foreignCompany = new NetTrainingFixture($db, account: $ours->account);
        $foreignAccount = new NetTrainingFixture($db, company: $ours->company);
        foreach ([[$ours, 10, 2], [$foreignCompany, 100, 50], [$foreignAccount, 500, 400]] as [$f, $quantity, $returned]) {
            $f->posting('ORDER', 'REQUESTED', $quantity);
            $f->returned('ORDER', 'REQUESTED', $returned, $f->terminal->modify('+5 days'));
            $f->returnCoverage();
        }
        $rows = $this->returns($db, $ours);
        self::assertSame(10, $rows[0]->sampleQuantity);
        self::assertNotNull($rows[0]->ageProfile);
        self::assertSame(2, $rows[0]->ageProfile[6]->returnedQuantity);
    }

    public function testAcceptedClientReturnIncrementsUseObservedAgeAndTheHalfOpenHorizon(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $fixture = new NetTrainingFixture($db);
        $fixture->posting('ORDER', 'REQUESTED', 10);
        $fixture->returned('ORDER', 'REQUESTED', 2, $fixture->terminal->modify('+5 days'), visualAt: $fixture->terminal->modify('+1 day'));
        $fixture->returned('ORDER', 'REQUESTED', 3, $fixture->terminal->modify('+15 days'));
        $fixture->returned('ORDER', 'REQUESTED', 4, $fixture->terminal->modify('+60 days'), visualAt: $fixture->terminal->modify('+55 days'));
        $fixture->returnCoverage();
        $context = new NetTrainingContext($fixture->company->toRfc4122(), $fixture->account->toRfc4122(), $fixture->readAt, $fixture->epoch);
        $rows = array_map(ReturnTrainingAggregateQuery::mapRow(...), (new ReturnTrainingAggregateQuery($db))->build($context->companyId, $context->accountId, $context, new NetTrainingRequest(['REQUESTED']))->executeQuery()->fetchAllAssociative());
        self::assertTrue($rows[0]->inputComplete);
        self::assertSame(10, $rows[0]->sampleQuantity);
        self::assertSame(1, $rows[0]->samplePostings);
        self::assertNotNull($rows[0]->ageProfile);
        self::assertCount(61, $rows[0]->ageProfile);
        self::assertSame(0, $rows[0]->ageProfile[0]->returnedQuantity);
        self::assertSame(2, $rows[0]->ageProfile[6]->returnedQuantity);
        self::assertSame(7, $rows[0]->ageProfile[16]->atRiskQuantity);
        self::assertSame(3, $rows[0]->ageProfile[60]->returnedQuantity);
        self::assertSame(1, $rows[0]->ageProfile[60]->atRiskPostingsLowerBound);
    }

    public function testReusedRawCannotTurnAReopenedSourceIntoAClosedTrainingSale(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $fixture = new NetTrainingFixture($db);
        for ($i = 0; $i < 31; ++$i) {
            $fixture->posting('GOOD-'.$i, 'REQUESTED', 1);
        }
        $fixture->posting('REOPENED', 'OTHER', 1);
        $fixture->reopenWithDeduplicatedInitialRaw('REOPENED', 'OTHER', 1);
        $context = new NetTrainingContext($fixture->company->toRfc4122(), $fixture->account->toRfc4122(), $fixture->readAt, $fixture->epoch);
        $query = (new GrossTrainingAggregateQuery($db))->build($context->companyId, $context->accountId, $context, new NetTrainingRequest(['REQUESTED']));
        $rows = array_map(GrossTrainingAggregateQuery::mapRow(...), $query->executeQuery()->fetchAllAssociative());
        self::assertSame(0, $rows[0]->sampleQuantity);
        self::assertSame(1, $rows[0]->excludedDays);
    }

    public function testBudgetOverflowDoesNotPublishTheFirstCandidateAsAnAccountSample(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $fixture = new NetTrainingFixture($db);
        for ($i = 0; $i < 31; ++$i) {
            $fixture->posting('POSTING-'.$i, 'REQUESTED', 1);
        }
        $context = new NetTrainingContext($fixture->company->toRfc4122(), $fixture->account->toRfc4122(), $fixture->readAt, $fixture->epoch);
        $rows = array_map(GrossTrainingAggregateQuery::mapRow(...), (new GrossTrainingAggregateQuery($db, new NetTrainingLimits(groups: 1)))->build($context->companyId, $context->accountId, $context, new NetTrainingRequest(['REQUESTED']))->executeQuery()->fetchAllAssociative());
        self::assertFalse($rows[0]->inputComplete);
        self::assertSame('input_budget_exceeded', $rows[0]->unavailableReason);
        self::assertNotNull($rows[0]->budgetFailure);
        self::assertSame('groups', $rows[0]->budgetFailure->input);
        self::assertSame(1, $rows[0]->budgetFailure->limit);
        self::assertSame(2, $rows[0]->budgetFailure->observedLowerBound);
        self::assertNull($rows[0]->sampleQuantity);
        self::assertNull($rows[1]->grossQuantity);
        $returns = array_map(ReturnTrainingAggregateQuery::mapRow(...), (new ReturnTrainingAggregateQuery($db, new NetTrainingLimits(groups: 1)))->build($context->companyId, $context->accountId, $context, new NetTrainingRequest(['REQUESTED']))->executeQuery()->fetchAllAssociative());
        self::assertFalse($returns[0]->inputComplete);
        self::assertSame('input_budget_exceeded', $returns[0]->unavailableReason);
        self::assertNotNull($returns[0]->budgetFailure);
        self::assertSame(2, $returns[0]->budgetFailure->observedLowerBound);
        self::assertNull($returns[0]->sampleQuantity);
        self::assertNull($returns[0]->ageProfile);
        $sourceCapped = array_map(GrossTrainingAggregateQuery::mapRow(...), (new GrossTrainingAggregateQuery($db, new NetTrainingLimits(revisionsPerSource: 1)))->build($context->companyId, $context->accountId, $context, new NetTrainingRequest(['REQUESTED']))->executeQuery()->fetchAllAssociative());
        self::assertFalse($sourceCapped[0]->inputComplete);
        self::assertNull($sourceCapped[0]->sampleQuantity);
        self::assertNotNull($sourceCapped[0]->budgetFailure);
        self::assertSame('revisions_per_source', $sourceCapped[0]->budgetFailure->input);
        self::assertSame(2, $sourceCapped[0]->budgetFailure->observedLowerBound);
    }

    public function testFullAccountGrossRetainsPreHandoverCancellationsBeforeRequestedSkuFilter(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $fixture = new NetTrainingFixture($db);
        for ($i = 0; $i < 25; ++$i) {
            $fixture->posting('G-'.$i, $i < 5 ? 'REQUESTED' : 'OTHER', 4);
            $fixture->posting('T1-'.$i, 'OTHER', 4, 'cancelled');
        }
        $context = new NetTrainingContext($fixture->company->toRfc4122(), $fixture->account->toRfc4122(), $fixture->readAt, $fixture->epoch);
        $query = (new GrossTrainingAggregateQuery($db))->build($fixture->company->toRfc4122(), $fixture->account->toRfc4122(), $context, new NetTrainingRequest(['REQUESTED']));
        $rows = array_map(GrossTrainingAggregateQuery::mapRow(...), $query->executeQuery()->fetchAllAssociative());
        self::assertCount(2, $rows);
        self::assertNull($rows[0]->marketplaceSku);
        self::assertTrue($rows[0]->inputComplete);
        self::assertSame(200, $rows[0]->sampleQuantity);
        self::assertSame(100, $rows[0]->grossQuantity);
        self::assertSame(100, $rows[0]->t1Quantity);
        self::assertSame(50, $rows[0]->samplePostings);
        self::assertSame(20, $rows[1]->grossQuantity);
    }

    /** @return list<ReturnTrainingAggregateRow> */
    private function returns(Connection $db, NetTrainingFixture $fixture): array
    {
        $context = new NetTrainingContext($fixture->company->toRfc4122(), $fixture->account->toRfc4122(), $fixture->readAt, $fixture->epoch);

        return array_map(ReturnTrainingAggregateQuery::mapRow(...), (new ReturnTrainingAggregateQuery($db))->build($context->companyId, $context->accountId, $context, new NetTrainingRequest(['REQUESTED']))->executeQuery()->fetchAllAssociative());
    }
}
