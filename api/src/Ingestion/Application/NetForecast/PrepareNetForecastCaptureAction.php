<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\NetForecastArtifactPublication;
use App\Ingestion\Domain\NetForwardArtifactKey;
use App\Ingestion\Domain\NetForwardArtifactKind;
use App\Ingestion\Domain\ValueObject\ObservedReturnCurve;
use App\Ingestion\Infrastructure\Query\Buyout\BuyoutMaturityQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\NetForwardBaselineQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\NetForwardBaselineRate;
use App\Ingestion\Infrastructure\Query\NetForecast\NetForwardEvidenceQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetRequest;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingRequest;
use App\Ingestion\Infrastructure\Query\SalesCohort\SalesCohortSeriesQuery;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

final readonly class PrepareNetForecastCaptureAction
{
    public function __construct(private Connection $connection, private ReadNetForecastInputsAction $inputs, private AggregateNetForecastAction $aggregate, private NetForwardEvidenceQuery $evidence, private NetForwardBaselineQuery $baseline, private BuyoutMaturityQuery $maturity, private SalesCohortSeriesQuery $legacy, private NetForwardManifestCodec $codec, private NetForwardInputCodec $inputCodec, #[Autowire('%app.version%')] private string $softwareRelease)
    {
    }

    public function __invoke(string $companyId, string $accountId, NetForwardCaptureRequest $request, NetForwardPlanContext $plan, int $deadlineNs): PreparedNetForwardArtifact
    {
        if (!$this->connection->isTransactionActive() || 'on' !== $this->connection->fetchOne('SHOW transaction_read_only') || !\in_array($this->connection->fetchOne('SHOW transaction_isolation'), ['repeatable read', 'serializable'], true)) {
            throw new \LogicException('Prepare requires the caller pinned read-only snapshot.');
        }
        if ($request->planId !== $plan->planId) {
            throw new \InvalidArgumentException('Capture plan mismatch.');
        }
        $spool = new NetForwardSpool($this->codec, $deadlineNs);
        $snapshotId = $this->connection->fetchOne('SELECT txid_current_snapshot()::text');
        if (!\is_string($snapshotId)) {
            throw new \UnexpectedValueException('Snapshot identity unavailable.');
        }
        $targetRequest = new NetTargetRequest($plan->marketplaceSkus, new \DateTimeImmutable($request->from), new \DateTimeImmutable($request->to), new \DateTimeImmutable($plan->accumulationFrom));
        $frame = ($this->inputs)($companyId, $accountId, $targetRequest, $snapshotId);
        $training = $frame->training;
        $readAt = $training->readAt;
        $context = $training->context;
        $forecast = null === $frame->targets ? new NetForecastBatch($companyId, $accountId, $readAt, $context, [], null, $frame->unavailableReason, null, $frame->unavailableReason)
            : ($this->aggregate)($companyId, $accountId, $frame->targets, $training, $frame->computeStartedNs, $frame->allocationBaselineBytes, $frame->limits, new NetForecastSeriesRequest($plan->marketplaceSkus, $targetRequest->from, $targetRequest->to, $targetRequest->accumulationFrom, $request->groupBy));
        $spool->guard();
        $spool->append('training', ['value' => $this->inputCodec->pack($training)]);
        $spool->append('target_request', ['value' => $this->inputCodec->pack($targetRequest)]);
        $spool->append('plan', ['value' => $this->inputCodec->pack($plan)]);
        $spool->append('target_metadata', ['unavailableReason' => null === $frame->targets ? $frame->unavailableReason : $frame->targets->unavailableReason, 'budgetFailure' => $this->inputCodec->pack($frame->targets?->budgetFailure)]);
        if (null !== $frame->targets) {
            foreach ($frame->targets->skus as $sku) {
                foreach ($sku->days as $day) {
                    $spool->append('target_day', ['sku' => $sku->marketplaceSku, 'value' => $this->inputCodec->pack($day)]);
                }
            }
        }
        foreach ($forecast->skus as $sku) {
            foreach ($sku->days as $day) {
                $spool->append('forecast_day', ['sku' => $sku->marketplaceSku, 'value' => $this->inputCodec->pack($day), 'futureT1' => $this->inputCodec->pack($day->futureT1())]);
            }
        }
        $spool->append('forecast_summary', ['quantities' => $this->inputCodec->pack($forecast->quantities), 'revenue' => $this->inputCodec->pack($forecast->expectedNetRevenue), 'reason' => $forecast->unavailableReason, 'moneyReason' => $forecast->moneyUnavailableReason, 'futureT1Policy' => 'pre_t1_gross_first_valid_v1', 'futureT1Provenance' => $forecast->futureT1Provenance(), 'series' => $this->inputCodec->pack($forecast->series)]);
        if (null !== $context) {
            $rates = NetForwardBaselineQuery::mapRows($this->baseline->build($companyId, $accountId, $context, new NetTrainingRequest($plan->marketplaceSkus))->executeQuery());
            if (null !== $frame->targets) {
                (new CaptureSimpleNetBaselineAction($this->inputCodec))($frame->targets, $forecast, $rates, $spool);
            }
            unset($frame, $forecast);
            $spool->append('simple_baseline', ['version' => 'simple_mature_return_linear_h60_v1', 'from' => $readAt->modify('-157 days')->format('Y-m-d\TH:i:s.u\Z'), 'to' => $readAt->modify('-67 days')->format('Y-m-d\TH:i:s.u\Z'), 'rates' => array_map(static fn (NetForwardBaselineRate $rate): array => $rate->toArray(), $rates), 'minimumUnits' => 100, 'minimumPostings' => 30, 'fallback' => ['sku', 'account'], 'reason' => null]);
            foreach (['gross', 'returns', 'target'] as $population) {
                $after = null;
                do {
                    $spool->guard();
                    $rows = NetForwardEvidenceQuery::mapRows($this->evidence->build($companyId, $accountId, $context, $targetRequest, $population, $after)->executeQuery());
                    $hasMore = NetForwardEvidenceQuery::PAGE_SIZE === \count($rows);
                    foreach ($rows as $row) {
                        if ('input_budget' === $row->relation && true !== ($row->values['ok'] ?? null)) {
                            throw new \OverflowException('Evidence population budget exceeded.');
                        }
                        $spool->append('evidence', ['population' => $population, 'relation' => $row->relation, 'values' => $row->values]);
                        $after = $row->cursor;
                    }
                } while ($hasMore);
            }
        } else {
            $spool->append('simple_baseline', ['version' => 'simple_mature_return_linear_h60_v1', 'reason' => $training->unavailableReason]);
        }
        // Preserve the old method and its own dense scope at the winning T.
        $spool->guard();
        $maturity = $this->maturity->build($companyId, $accountId, $readAt)->executeQuery()->fetchAssociative();
        if (false === $maturity) {
            throw new \UnexpectedValueException('Legacy baseline maturity missing.');
        }
        $p95 = BuyoutMaturityQuery::mapRow($maturity)->p95Seconds;
        $legacy = $this->legacy->build($companyId, $accountId, $plan->marketplaceSkus, $targetRequest->from, $targetRequest->to, $targetRequest->accumulationFrom ?? $targetRequest->from, $request->groupBy, $plan->currency, $readAt, $p95)->executeQuery()->fetchAllAssociative();
        foreach ($legacy as $row) {
            $spool->append('legacy_baseline', ['method' => 'adr030_projected_buyout', 'maturity' => $maturity, 'scope' => ['from' => $request->from, 'to' => $request->to, 'groupBy' => $request->groupBy, 'currency' => $plan->currency], 'value' => $this->inputCodec->pack(SalesCohortSeriesQuery::mapRow($row)), 'comparisonReason' => 'gross_buyout_not_retained_net']);
        }
        $attemptId = Uuid::v7()->toRfc4122();
        $root = $this->codec->encode(['companyId' => $companyId, 'marketplaceAccountId' => $accountId, 'kind' => 'input', 'requestId' => $request->requestId, 'requestHash' => $request->hash(), 'attemptId' => $attemptId, 'readAtUtc' => $readAt->format('Y-m-d\TH:i:s.u\Z'), 'parent' => null, 'chunks' => $spool->descriptors(), 'metadata' => ['model' => ObservedReturnCurve::MODEL_VERSION, 'training' => NetTrainingContext::MODEL_VERSION, 'coverage' => NetTrainingContext::COVERAGE_POLICY, 'capture' => $context?->epoch->captureVersion, 'epoch' => $this->inputCodec->pack($context?->epoch), 'snapshot' => $snapshotId, 'softwareRelease' => $this->softwareRelease, 'timezone' => 'Europe/Moscow', 'horizonDays' => 60, 'reportingLagDays' => 7, 'groupBy' => $request->groupBy, 'publicPolicy' => 'validation_pending', 'sourceReason' => $training->unavailableReason, 'modelCaps' => ['computeSeconds' => 5, 'peakBytes' => 100663296, 'allocationBytes' => 50331648, 'reserveBytes' => 8388608, 'outputBytes' => 33554432, 'digits' => 16384], 'artifactCaps' => ['wallSeconds' => 30, 'chunkBytes' => 1048576, 'chunks' => 128, 'pageRows' => NetForwardEvidenceQuery::PAGE_SIZE, 'spoolBytes' => 136314880]]]);
        $spool->guard();

        return new PreparedNetForwardArtifact(new NetForecastArtifactPublication(new NetForwardArtifactKey($companyId, $accountId, NetForwardArtifactKind::Input, $request->requestId, $attemptId, hash('sha256', $root)), $request->hash(), \strlen($root)), $root, $spool);
    }
}
