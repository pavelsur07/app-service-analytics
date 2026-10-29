<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\Ingestion\Application\Facade\ForwardArtifactRef;
use App\Ingestion\Application\Facade\ForwardCaptureRequest;
use App\Ingestion\Application\Facade\ForwardPlanContext;
use App\Ingestion\Application\Facade\ForwardQualityProtocolRef;
use App\Ingestion\Application\Facade\IngestionFacade;
use App\Ingestion\Application\Facade\PreparedForwardCapture;
use App\SalesPlanning\Infrastructure\Query\SalesPlanBaselineQuery;
use App\SalesPlanning\Infrastructure\Query\SalesPlanPerformanceQuery;
use App\SalesPlanning\Infrastructure\Query\SalesPlansQuery;
use App\Shared\Application\QueryTelemetry;
use Doctrine\DBAL\Connection;

/** Explicit internal capture; all human plan values are read-only in the common pinned RR. */
final readonly class CaptureSalesPlanForwardAction
{
    public function __construct(private Connection $connection, private SalesPlansQuery $headers, private SalesPlanBaselineQuery $baselines, private SalesPlanPerformanceQuery $plans, private IngestionFacade $ingestion, private QueryTelemetry $telemetry = new QueryTelemetry())
    {
    }

    public function __invoke(string $companyId, string $accountId, string $planId, string $requestId, string $from, string $to, string $groupBy = 'day', ?string $qualityProtocolId = null, ?string $qualityProtocolHash = null): ForwardArtifactRef
    {
        return $this->telemetry->run('net_forecast.capture', $companyId, $accountId, $requestId, fn () => $this->capture($companyId, $accountId, $planId, $requestId, $from, $to, $groupBy, $qualityProtocolId, $qualityProtocolHash));
    }

    private function capture(string $companyId, string $accountId, string $planId, string $requestId, string $from, string $to, string $groupBy, ?string $qualityProtocolId, ?string $qualityProtocolHash): ForwardArtifactRef
    {
        if ((null === $qualityProtocolId) !== (null === $qualityProtocolHash)) {
            throw new \InvalidArgumentException('Quality protocol identity and hash are required together.');
        }
        $qualityProtocol = null !== $qualityProtocolId && null !== $qualityProtocolHash ? new ForwardQualityProtocolRef($qualityProtocolId, $qualityProtocolHash) : null;
        $request = new ForwardCaptureRequest($requestId, $planId, $from, $to, $groupBy, $qualityProtocol);
        $native = $this->connection->getNativeConnection();
        if ($this->connection->isTransactionActive() || ($native instanceof \PDO && $native->inTransaction())) {
            throw new \LogicException('Capture must own its outer transaction.');
        }
        $existing = $this->ingestion->findForwardCapture($companyId, $accountId, $request);
        if (null !== $existing) {
            return $existing;
        }
        $deadline = (int) (hrtime(true) + 30000000000);
        $quality = null === $qualityProtocol ? null : $this->ingestion->verifyForwardQualityProtocol($companyId, $accountId, $request, $qualityProtocol, $deadline);
        $prepared = $this->connection->transactional(function () use ($companyId, $accountId, $request, $deadline, $quality): PreparedForwardCapture {
            $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $this->connection->fetchOne('SELECT txid_current_snapshot()::text');
            $this->connection->executeStatement("SET LOCAL statement_timeout = '5s'");
            $header = $this->telemetry->stage('plan.header', fn () => $this->headers->withPlanId($companyId, $request->planId)->executeQuery()->fetchAssociative());
            $rawBase = $this->telemetry->stage('plan.baseline', fn () => $this->baselines->build($companyId, $request->planId)->executeQuery()->fetchAssociative());
            if (false === $header || false === $rawBase) {
                throw new SalesPlanPerformanceReadException('sales_plan_no_approved_baseline', 404);
            }
            $plan = SalesPlansQuery::mapRow($header);
            $base = SalesPlanBaselineQuery::mapRow($rawBase);
            if ($plan->marketplaceAccountId !== $accountId || $base->marketplaceAccountId !== $accountId || null === $plan->metric || \in_array($plan->status, ['discarded', 'cancelled', 'superseded'], true)) {
                throw new SalesPlanPerformanceReadException('sales_plan_capture_scope_invalid', 409);
            }
            if ($request->from < $base->dateFrom || $request->to > $base->dateTo) {
                throw new \InvalidArgumentException('Capture period outside original season.');
            }
            $rows = $this->telemetry->stage('plan.rows', fn () => $this->plans->build($companyId, $request->planId, $base->id, $request->from, $request->to, $base->dateFrom, $request->groupBy, null, 50)->executeQuery()->fetchAllAssociative());
            if ([] === $rows || \count($rows) > 50) {
                throw new \OverflowException('Capture requires a complete plan scope of 1..50 SKU.');
            }
            $skus = [];
            $goals = [];
            foreach ($rows as $row) {
                $mapped = SalesPlanPerformanceQuery::mapRow($row);
                $skus[] = $mapped->marketplaceSku;
                // Original dense goal values, including explicit zero rows, are sealed.
                $goals[] = ['sku' => $mapped->marketplaceSku, 'points' => array_map(static fn ($point): array => get_object_vars($point), $mapped->points)];
            }

            return $this->ingestion->prepareForwardCapture($companyId, $accountId, $request, new ForwardPlanContext($plan->id, $plan->version, $plan->versionNumber, $base->id, $base->versionNumber, $plan->metric, $base->currency, $base->dateFrom, $skus, $goals), $deadline, $quality);
        });

        return $this->ingestion->publishForwardCapture($companyId, $accountId, $prepared);
    }
}
