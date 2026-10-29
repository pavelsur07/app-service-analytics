<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\NetForecast as AppNet;
use App\Ingestion\Domain\NetForwardArtifactKey;
use App\Ingestion\Domain\NetForwardArtifactKind;
use App\Ingestion\Domain\NetForwardArtifactMissing;
use App\Ingestion\Domain\NetForwardArtifactStorage;
use App\Ingestion\Infrastructure\Config\NetForecastCaptureEpochs;
use App\Ingestion\Infrastructure\Persistence\DbalNetForecastArtifactRepository;
use App\Ingestion\Infrastructure\Query\Buyout\BuyoutMaturityQuery;
use App\Ingestion\Infrastructure\Query\NetForecast as Query;
use App\Ingestion\Infrastructure\Query\SalesCohort\SalesCohortCoverageQuery;
use App\Ingestion\Infrastructure\Query\SalesCohort\SalesCohortMetricsQuery;
use App\Ingestion\Infrastructure\Query\SalesCohort\SalesCohortSeriesQuery;
use App\Tests\Support\Ingestion\NetTargetFixture;
use App\Tests\Support\Ingestion\NetTrainingFixture;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[SkipDatabaseRollback]
final class NetForwardCaptureTest extends KernelTestCase
{
    public function testPrivateNumericCapturePublishesVerifiedWinnerAndReplayIgnoresFutureSources(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $params = $connection->getParams();
        unset($params['wrapperClass']);
        $db = DriverManager::getConnection($params);
        $lateConnection = DriverManager::getConnection($params);
        $t = new NetTrainingFixture($db);
        $f = new NetTargetFixture($db, $t->readAt, $t->company, $t->account);
        $company = $f->company->toRfc4122();
        $account = $f->account->toRfc4122();
        try {
            for ($i = 0; $i < 30; ++$i) {
                $t->posting('TRAIN-D-'.$i, 'SKU', 40);
                $t->posting('TRAIN-C-'.$i, 'SKU', 40, 'cancelled');
                if ($i < 20) {
                    $t->returned('TRAIN-D-'.$i, 'SKU', 5, $t->terminal->modify('+10 days'));
                }
            }
            $t->returnCoverage();
            $origin = $f->readAt->modify('-1 day');
            $gapOrigin = $f->readAt->modify('-2 days');
            $fullOrigin = $f->readAt->modify('-30 days');
            $f->delivered('D', 'SKU', 70, $origin);
            $f->delivered('FULL', 'SKU', 10, $fullOrigin);
            $f->delivered('GAP', 'SKU', 10, $gapOrigin);
            $f->sale('OPEN', 'SKU', 50, 'awaiting_packaging', $f->readAt->modify('-2 hours'));
            $f->daily($origin);
            $f->daily($gapOrigin);
            $f->daily($fullOrigin, deep: true);
            $f->currentReturns();
            $epoch = $f->epoch;
            $epochs = new NetForecastCaptureEpochs(json_encode([['epochId' => $epoch->id, 'captureVersion' => $epoch->captureVersion, 'startUtc' => $epoch->start->format('Y-m-d\TH:i:s\Z'), 'endUtc' => null, 'cutoverRelease' => $epoch->cutoverRelease]], \JSON_THROW_ON_ERROR));
            $lateFixture = new NetTargetFixture($lateConnection, $f->readAt, $f->company, $f->account);
            $lateConnection->beginTransaction();
            $lateFixture->sale('LATE-COMMIT', 'SKU', 1, 'awaiting_packaging', $f->readAt->modify('-10 minutes'));
            $clock = new class($f->readAt, $lateConnection) implements \Psr\Clock\ClockInterface {
                public int $calls = 0;

                public function __construct(private \DateTimeImmutable $at, private Connection $writer)
                {
                }

                public function now(): \DateTimeImmutable
                {
                    ++$this->calls;
                    if ($this->writer->isTransactionActive()) {
                        $this->writer->commit();
                    }

                    return $this->at;
                }
            };
            $training = new AppNet\ReadNetTrainingAction($db, new Query\GrossTrainingAggregateQuery($db), new Query\ReturnTrainingAggregateQuery($db), $epochs, $clock);
            /** @var AppNet\AggregateNetForecastAction $aggregate */
            $aggregate = self::getContainer()->get(AppNet\AggregateNetForecastAction::class);
            $codec = new AppNet\NetForwardManifestCodec();
            $inputCodec = new AppNet\NetForwardInputCodec();
            $prepare = new AppNet\PrepareNetForecastCaptureAction($db, new AppNet\ReadNetForecastInputsAction($db, $training, new Query\NetTargetQuery($db)), $aggregate, new Query\NetForwardEvidenceQuery($db), new Query\NetForwardBaselineQuery($db), new BuyoutMaturityQuery($db), new SalesCohortSeriesQuery($db, new SalesCohortMetricsQuery($db), new SalesCohortCoverageQuery($db)), $codec, $inputCodec, 'test-only');
            $day = $f->readAt->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d');
            $planId = Uuid::v7()->toRfc4122();
            $request = new AppNet\NetForwardCaptureRequest(Uuid::v7()->toRfc4122(), $planId, $day, $day);
            $plan = new AppNet\NetForwardPlanContext($planId, 1, 1, $planId, 1, 'retained_sales', 'RUB', $day, ['SKU'], [['sku' => 'SKU', 'points' => []]]);
            $prepared = (new AppNet\NetTrainingSnapshot($db))->read(static fn () => $prepare($company, $account, $request, $plan, (int) (hrtime(true) + 30000000000)));
            self::assertFalse($db->isTransactionActive());
            $storage = new class($db) implements NetForwardArtifactStorage {
                /** @var array<string, string> */
                public array $objects = [];

                public function __construct(private Connection $db)
                {
                }

                public function putContent(string $companyId, string $accountId, NetForwardArtifactKey $key, string $body): void
                {
                    TestCaseProxy::assertNoTransaction($this->db);
                    $key->assertScope($companyId, $accountId);
                    $this->objects[$key->toString()] = $body; // Provider deliberately ignores CAS.
                }

                public function get(string $companyId, string $accountId, NetForwardArtifactKey $key, int $maxBytes): string
                {
                    TestCaseProxy::assertNoTransaction($this->db);
                    $key->assertScope($companyId, $accountId);

                    return $this->objects[$key->toString()] ?? throw new NetForwardArtifactMissing();
                }
            };
            $repository = new DbalNetForecastArtifactRepository($db);
            $reader = new AppNet\NetForwardArtifactReader($db, $repository, $storage, $codec);
            $publish = new AppNet\PublishNetForecastCaptureAction($repository, $storage, $reader);
            $verified = $publish($company, $account, $prepared);
            self::assertSame($f->readAt->format('U.u'), $verified->readAt->format('U.u'));
            self::assertIsArray($verified->manifest['metadata']);
            self::assertSame('validation_pending', $verified->manifest['metadata']['publicPolicy']);
            $summary = null;
            $evidenceRows = 0;
            $lateSeen = false;
            foreach ($reader->segments($company, $account, $verified) as $segment) {
                if ('forecast_summary' === $segment['type']) {
                    $summary = $segment['rows'][0];
                }
                if ('evidence' === $segment['type']) {
                    $evidenceRows += \count($segment['rows']);
                    foreach ($segment['rows'] as $evidenceRow) {
                        $values = $evidenceRow['values'] ?? null;
                        if (\is_array($values) && 'LATE-COMMIT' === ($values['source_row_id'] ?? null)) {
                            $lateSeen = true;
                        }
                    }
                }
            }
            self::assertNotNull($summary);
            self::assertNotNull($summary['quantities']);
            self::assertNull($summary['reason']);
            self::assertGreaterThan(500, $evidenceRows);
            self::assertFalse($lateSeen, 'Commit after RR pin must remain absent despite captured_at<T.');
            self::assertSame(1, $clock->calls);
            $nextRequest = new AppNet\NetForwardCaptureRequest(Uuid::v7()->toRfc4122(), $planId, $day, $day);
            $next = (new AppNet\NetTrainingSnapshot($db))->read(static fn () => $prepare($company, $account, $nextRequest, $plan, (int) (hrtime(true) + 30000000000)));
            $nextSeen = false;
            foreach ($next->spool->descriptors() as $descriptor) {
                if ('evidence' === $descriptor['type']) {
                    $chunk = $codec->decode($next->spool->body($descriptor['ordinal']));
                    self::assertIsArray($chunk['rows']);
                    foreach ($chunk['rows'] as $evidenceRow) {
                        self::assertIsArray($evidenceRow);
                        $values = $evidenceRow['values'] ?? null;
                        if (\is_array($values) && 'LATE-COMMIT' === ($values['source_row_id'] ?? null)) {
                            $nextSeen = true;
                        }
                    }
                }
            }
            self::assertTrue($nextSeen, 'Next real snapshot sees the committed row and receipts.');
            unset($next);
            self::assertSame($verified->publication->root->sha256, $publish($company, $account, $prepared)->publication->root->sha256);
            $f->returned('D', 'SKU', 60, $f->readAt->modify('+1 day'));
            $replay = new AppNet\ReplayNetForecastArtifactAction($reader, $inputCodec, $aggregate, $codec);
            $result = $replay($company, $account, $request->requestId, $verified->publication->root->sha256);
            self::assertNotNull($result->quantities);
            self::assertSame($summary['quantities'], $codec->decode($codec->encode(['value' => $inputCodec->pack($result->quantities)]))['value']);
            $slowStorage = new class($storage) implements NetForwardArtifactStorage {
                public int $delayedChunkReads = 0;

                public function __construct(private NetForwardArtifactStorage $inner)
                {
                }

                public function putContent(string $companyId, string $accountId, NetForwardArtifactKey $key, string $body): void
                {
                    $this->inner->putContent($companyId, $accountId, $key, $body);
                }

                public function get(string $companyId, string $accountId, NetForwardArtifactKey $key, int $maxBytes): string
                {
                    if (0 === $key->ordinal && $this->delayedChunkReads < 2) {
                        ++$this->delayedChunkReads;
                        usleep(600_000);
                    }

                    return $this->inner->get($companyId, $accountId, $key, $maxBytes);
                }
            };
            $slowReader = new AppNet\NetForwardArtifactReader($db, $repository, $slowStorage, $codec);
            $boundedReplay = new AppNet\ReplayNetForecastArtifactAction($slowReader, $inputCodec, $aggregate, $codec, wallBudgetNanoseconds: 1_000_000_000);
            try {
                $boundedReplay($company, $account, $request->requestId, $verified->publication->root->sha256);
                self::fail('The shared replay deadline must include the initial verification read.');
            } catch (\OverflowException $error) {
                self::assertSame('Replay wall budget exceeded.', $error->getMessage());
            }
            self::assertSame(2, $slowStorage->delayedChunkReads);
            $budgetRequest = new AppNet\NetForwardCaptureRequest(Uuid::v7()->toRfc4122(), $planId, $day, $day);
            $budgetDeadline = (int) (hrtime(true) + 30000000000);
            $budgetSpool = new AppNet\NetForwardSpool($codec, $budgetDeadline);
            foreach ($prepared->spool->descriptors() as $descriptor) {
                $chunk = $codec->decode($prepared->spool->body($descriptor['ordinal']));
                $type = $chunk['type'] ?? null;
                $rows = $chunk['rows'] ?? null;
                if (!\is_string($type) || !\is_array($rows)) {
                    throw new \UnexpectedValueException('Invalid test artifact chunk.');
                }
                foreach ($rows as $row) {
                    if (!\is_array($row)) {
                        throw new \UnexpectedValueException('Invalid test artifact row.');
                    }
                    if ('forecast_day' === $type) {
                        continue;
                    }
                    if ('forecast_summary' === $type) {
                        $row = ['quantities' => $inputCodec->pack(null), 'revenue' => $inputCodec->pack(null), 'reason' => 'input_budget_exceeded', 'moneyReason' => 'input_budget_exceeded', 'futureT1Policy' => 'pre_t1_gross_first_valid_v1', 'futureT1Provenance' => [], 'series' => $inputCodec->pack(null)];
                    }
                    $budgetSpool->append($type, $row);
                }
            }
            $budgetAttempt = Uuid::v7()->toRfc4122();
            $budgetRoot = $codec->encode(['companyId' => $company, 'marketplaceAccountId' => $account, 'kind' => 'input', 'requestId' => $budgetRequest->requestId, 'requestHash' => $budgetRequest->hash(), 'attemptId' => $budgetAttempt, 'readAtUtc' => $verified->readAt->format('Y-m-d\TH:i:s.u\Z'), 'parent' => null, 'chunks' => $budgetSpool->descriptors(), 'metadata' => $verified->manifest['metadata']]);
            $budgetPrepared = new AppNet\PreparedNetForwardArtifact(new \App\Ingestion\Domain\NetForecastArtifactPublication(new NetForwardArtifactKey($company, $account, NetForwardArtifactKind::Input, $budgetRequest->requestId, $budgetAttempt, hash('sha256', $budgetRoot)), $budgetRequest->hash(), \strlen($budgetRoot)), $budgetRoot, $budgetSpool);
            $budgetArtifact = $publish($company, $account, $budgetPrepared);
            $budgetReplay = $replay($company, $account, $budgetRequest->requestId, $budgetArtifact->publication->root->sha256);
            self::assertSame('input_budget_exceeded', $budgetReplay->unavailableReason);
            self::assertNull($budgetReplay->quantities);
            $timeoutRequest = new AppNet\NetForwardCaptureRequest(Uuid::v7()->toRfc4122(), $planId, $day, $day);
            $timeoutSpool = new AppNet\NetForwardSpool($codec, (int) (hrtime(true) + 30000000000));
            foreach ($prepared->spool->descriptors() as $descriptor) {
                $chunk = $codec->decode($prepared->spool->body($descriptor['ordinal']));
                $type = $chunk['type'] ?? null;
                $rows = $chunk['rows'] ?? null;
                if (!\is_string($type) || !\is_array($rows)) {
                    throw new \UnexpectedValueException('Invalid timeout test artifact chunk.');
                }
                if ('target_day' === $type || 'forecast_day' === $type) {
                    continue;
                }
                foreach ($rows as $row) {
                    if (!\is_array($row)) {
                        throw new \UnexpectedValueException('Invalid timeout test artifact row.');
                    }
                    if ('target_request' === $type) {
                        $row = ['value' => $inputCodec->pack(new Query\NetTargetRequest(['SKU'], new \DateTimeImmutable($day), new \DateTimeImmutable($day)))];
                    } elseif ('target_metadata' === $type) {
                        $row = ['unavailableReason' => 'query_budget_exceeded', 'budgetFailure' => $inputCodec->pack(null)];
                    } elseif ('forecast_summary' === $type) {
                        $row = ['quantities' => $inputCodec->pack(null), 'revenue' => $inputCodec->pack(null), 'reason' => 'query_budget_exceeded', 'moneyReason' => 'query_budget_exceeded', 'futureT1Policy' => 'pre_t1_gross_first_valid_v1', 'futureT1Provenance' => [], 'series' => $inputCodec->pack(null)];
                    }
                    $timeoutSpool->append($type, $row);
                }
            }
            $timeoutAttempt = Uuid::v7()->toRfc4122();
            $timeoutRoot = $codec->encode(['companyId' => $company, 'marketplaceAccountId' => $account, 'kind' => 'input', 'requestId' => $timeoutRequest->requestId, 'requestHash' => $timeoutRequest->hash(), 'attemptId' => $timeoutAttempt, 'readAtUtc' => $verified->readAt->format('Y-m-d\TH:i:s.u\Z'), 'parent' => null, 'chunks' => $timeoutSpool->descriptors(), 'metadata' => $verified->manifest['metadata']]);
            $timeoutPrepared = new AppNet\PreparedNetForwardArtifact(new \App\Ingestion\Domain\NetForecastArtifactPublication(new NetForwardArtifactKey($company, $account, NetForwardArtifactKind::Input, $timeoutRequest->requestId, $timeoutAttempt, hash('sha256', $timeoutRoot)), $timeoutRequest->hash(), \strlen($timeoutRoot)), $timeoutRoot, $timeoutSpool);
            $timeoutArtifact = $publish($company, $account, $timeoutPrepared);
            $timeoutReplay = $replay($company, $account, $timeoutRequest->requestId, $timeoutArtifact->publication->root->sha256);
            self::assertSame('query_budget_exceeded', $timeoutReplay->unavailableReason);
            self::assertNull($timeoutReplay->quantities);
            $truthRequest = Uuid::v7()->toRfc4122();
            $truthAction = new AppNet\CaptureNetForecastTruthAction($db, $repository, $reader, $publish, $codec, $inputCodec, $epochs, new Query\NetTargetQuery($db), new Query\NetForwardEvidenceQuery($db), new MockClock($f->readAt->modify('+90 days')));
            $pending = $truthAction($company, $account, $request->requestId, $verified->publication->root->sha256, $truthRequest);
            self::assertSame(NetForwardArtifactKind::Truth, $pending->publication->root->kind);
            foreach ($reader->segments($company, $account, $pending) as $segment) {
                if ('truth_summary' === $segment['type']) {
                    self::assertSame('pending', $segment['rows'][0]['state']);
                    self::assertNull($segment['rows'][0]['quantity']);
                }
            }
            $f->sale('OPEN', 'SKU', 50, 'delivered', $f->readAt->modify('+20 days'), amount: \App\Shared\Domain\ValueObject\Money::ofMinor(600, 'RUB'));
            $f->returned('OPEN', 'SKU', 5, $f->readAt->modify('+75 days'), source: 'RETURN-OPEN');
            $f->returned('FULL', 'SKU', 10, $f->readAt->modify('+20 days'), source: 'RETURN-FULL');
            $f->returned('GAP', 'SKU', 10, $f->readAt->modify('+20 days'), source: 'RETURN-GAP');
            $future = new NetTargetFixture($db, $f->readAt->modify('+90 days'), $f->company, $f->account);
            $future->daily($gapOrigin, missing: [40], deep: true);
            $future->daily($fullOrigin, deep: true);
            $future->daily($f->readAt->modify('+20 days'), deep: true);
            $future->currentReturns();
            $missingDailyDate = $gapOrigin->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0, 0)->modify('+40 days')->format('Y-m-d');
            $db->executeStatement("DELETE FROM marketplace_day_load_receipt WHERE company_id = ? AND marketplace_account_id = ? AND report_type = 'ozon_returns_list' AND date_from = ? AND date_to = ?", [$company, $account, $missingDailyDate, $missingDailyDate]);
            $ready = $truthAction($company, $account, $request->requestId, $verified->publication->root->sha256, Uuid::v7()->toRfc4122());
            $readySummary = null;
            foreach ($reader->segments($company, $account, $ready) as $segment) {
                if ('truth_summary' === $segment['type']) {
                    $readySummary = $segment['rows'][0];
                }
            }
            self::assertNotNull($readySummary);
            $fullyReturnedState = $coverageGapState = null;
            foreach ($reader->segments($company, $account, $ready) as $segment) {
                if ('truth_part' !== $segment['type']) {
                    continue;
                }
                foreach ($segment['rows'] as $part) {
                    $truth = $inputCodec->unpack($part['result'] ?? null);
                    if (!$truth instanceof \App\Ingestion\Domain\ValueObject\NetForwardTruthResult) {
                        continue;
                    }
                    if ('FULL' === ($part['orderNumber'] ?? null)) {
                        $fullyReturnedState = $truth;
                    } elseif ('GAP' === ($part['orderNumber'] ?? null)) {
                        $coverageGapState = $truth;
                    }
                }
            }
            self::assertNotNull($fullyReturnedState);
            self::assertSame('ready', $fullyReturnedState->state);
            self::assertSame(0, $fullyReturnedState->quantity);
            self::assertNotNull($coverageGapState);
            self::assertSame('pending', $coverageGapState->state);
            self::assertSame('returns_coverage_missing', $coverageGapState->reason);
            self::assertSame('pending', $readySummary['state']);
            self::assertNull($readySummary['quantity']);
            self::assertNull($readySummary['money']);
            $scopeRequest = new AppNet\NetForwardCaptureRequest(Uuid::v7()->toRfc4122(), $planId, $day, (new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d'));
            $scopeTargetRequest = new Query\NetTargetRequest(['SKU'], new \DateTimeImmutable($day), (new \DateTimeImmutable($day))->modify('+1 day'), new \DateTimeImmutable($day));
            $scopeSpool = new AppNet\NetForwardSpool($codec, (int) (hrtime(true) + 30000000000));
            foreach ($prepared->spool->descriptors() as $descriptor) {
                $chunk = $codec->decode($prepared->spool->body($descriptor['ordinal']));
                $type = $chunk['type'] ?? null;
                $rows = $chunk['rows'] ?? null;
                if (!\is_string($type) || !\is_array($rows)) {
                    throw new \UnexpectedValueException('Invalid test artifact chunk.');
                }
                foreach ($rows as $row) {
                    if (!\is_array($row)) {
                        throw new \UnexpectedValueException('Invalid test artifact row.');
                    }
                    if ('target_request' === $type) {
                        $row = ['value' => $inputCodec->pack($scopeTargetRequest)];
                    }
                    $scopeSpool->append($type, $row);
                }
            }
            $scopeSpool->append('target_day', ['sku' => 'SKU', 'value' => $inputCodec->pack(new Query\NetTargetDayRow((new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d'), [], false, 'postings_coverage_missing'))]);
            $scopeAttempt = Uuid::v7()->toRfc4122();
            $scopeRoot = $codec->encode(['companyId' => $company, 'marketplaceAccountId' => $account, 'kind' => 'input', 'requestId' => $scopeRequest->requestId, 'requestHash' => $scopeRequest->hash(), 'attemptId' => $scopeAttempt, 'readAtUtc' => $verified->readAt->format('Y-m-d\TH:i:s.u\Z'), 'parent' => null, 'chunks' => $scopeSpool->descriptors(), 'metadata' => $verified->manifest['metadata']]);
            $scopePrepared = new AppNet\PreparedNetForwardArtifact(new \App\Ingestion\Domain\NetForecastArtifactPublication(new NetForwardArtifactKey($company, $account, NetForwardArtifactKind::Input, $scopeRequest->requestId, $scopeAttempt, hash('sha256', $scopeRoot)), $scopeRequest->hash(), \strlen($scopeRoot)), $scopeRoot, $scopeSpool);
            $scopeArtifact = $publish($company, $account, $scopePrepared);
            $scopeTruth = $truthAction($company, $account, $scopeRequest->requestId, $scopeArtifact->publication->root->sha256, Uuid::v7()->toRfc4122());
            foreach ($reader->segments($company, $account, $scopeTruth) as $segment) {
                if ('truth_summary' === $segment['type']) {
                    self::assertSame('pending', $segment['rows'][0]['state']);
                    self::assertNull($segment['rows'][0]['quantity']);
                }
            }
            self::assertSame($pending->publication->root->sha256, $truthAction($company, $account, $request->requestId, $verified->publication->root->sha256, $truthRequest)->publication->root->sha256);
            $key = array_key_first($storage->objects);
            self::assertNotNull($key);
            $storage->objects[$key] = '{}';
            $this->expectException(\UnexpectedValueException::class);
            $reader->read($company, $account, NetForwardArtifactKind::Input, $request->requestId);
        } finally {
            if ($lateConnection->isTransactionActive()) {
                $lateConnection->rollBack();
            }
            $lateConnection->close();
            $db->executeStatement('DELETE FROM net_forecast_artifact WHERE company_id = ? AND marketplace_account_id = ?', [$company, $account]);
            $f->cleanup();
            $db->close();
        }
    }
}

final class TestCaseProxy
{
    public static function assertNoTransaction(Connection $db): void
    {
        \PHPUnit\Framework\Assert::assertFalse($db->isTransactionActive(), 'S3 IO must be outside RR and publication transaction.');
    }
}
