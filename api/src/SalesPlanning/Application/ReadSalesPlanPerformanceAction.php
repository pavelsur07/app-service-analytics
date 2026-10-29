<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\Ingestion\Application\Facade\IngestionFacade;
use App\Ingestion\Application\Facade\SalesCohortGrouping;
use App\Ingestion\Application\Facade\SalesCohortPoint;
use App\Ingestion\Application\Facade\SalesCohortRequest;
use App\SalesPlanning\Domain\SalesPlanMetric;
use App\SalesPlanning\Infrastructure\Query\SalesPlanBaselineQuery;
use App\SalesPlanning\Infrastructure\Query\SalesPlanPerformanceQuery;
use App\SalesPlanning\Infrastructure\Query\SalesPlansQuery;
use App\Shared\Domain\ValueObject\Money;

final readonly class ReadSalesPlanPerformanceAction
{
    public function __construct(
        private SalesPlanPerformanceSnapshot $snapshot,
        private SalesPlansQuery $headers,
        private SalesPlanBaselineQuery $baselines,
        private SalesPlanPerformanceQuery $plans,
        private IngestionFacade $ingestion,
        private SalesPlanPerformanceCalculator $calculator,
    ) {
    }

    public function __invoke(string $companyId, string $planId, SalesPlanPerformanceRequest $request): SalesPlanPerformanceView
    {
        return $this->snapshot->read(function () use ($companyId, $planId, $request): SalesPlanPerformanceView {
            $header = $this->headers->withPlanId($companyId, $planId)->executeQuery()->fetchAssociative();
            if (false === $header) {
                throw new SalesPlanPerformanceReadException('sales_plan_not_found', 404);
            }
            $plan = SalesPlansQuery::mapRow($header);
            if (\in_array($plan->status, ['discarded', 'cancelled', 'superseded'], true)) {
                throw new SalesPlanPerformanceReadException('sales_plan_closed', 409);
            }
            $rawBase = $this->baselines->build($companyId, $planId)->executeQuery()->fetchAssociative();
            if (false === $rawBase) {
                throw new SalesPlanPerformanceReadException('sales_plan_no_approved_baseline', 409);
            }
            $base = SalesPlanBaselineQuery::mapRow($rawBase);
            $metric = null === $plan->metric ? null : SalesPlanMetric::tryFrom($plan->metric);
            if (null === $metric) {
                throw new SalesPlanPerformanceReadException('sales_plan_metric_required', 422);
            }
            $from = $request->from->format('Y-m-d');
            $to = $request->to->format('Y-m-d');
            if ($from < $base->dateFrom || $to > $base->dateTo) {
                throw new \InvalidArgumentException('Performance range is outside the original season.');
            }
            $scope = hash('sha256', json_encode([$companyId, $planId, $base->id, $plan->version, $metric->value, $from, $to, $request->groupBy->value, $request->limit], \JSON_THROW_ON_ERROR));
            $cursor = self::decodeCursor($request->cursor, $scope);
            $rawRows = $this->plans->build($companyId, $planId, $base->id, $from, $to, $base->dateFrom, $request->groupBy->value, $cursor, $request->limit)
                ->executeQuery()->fetchAllAssociative();
            $hasMore = \count($rawRows) > $request->limit;
            $rows = array_map(SalesPlanPerformanceQuery::mapRow(...), \array_slice($rawRows, 0, $request->limit));
            $cohorts = $this->ingestion->salesCohortMetrics($companyId, $plan->marketplaceAccountId,
                new SalesCohortRequest(array_map(static fn ($row): string => $row->marketplaceSku, $rows), $request->from, $request->to, new \DateTimeImmutable($base->dateFrom), SalesCohortGrouping::from($request->groupBy->value), $base->currency));
            $budget = $cohorts->forecastBudget;
            $guard = null;
            if (null !== $budget) {
                $used = $budget->outputBytesUsed;
                $guard = static function (int $extraBytes = 0) use ($budget, &$used): void {
                    $outputLimit = min(33554432, $budget->outputLimitBytes);
                    if ($extraBytes < 0 || $used < 0 || $extraBytes > $outputLimit - $used) {
                        throw new \OverflowException('Goal output budget exceeded.');
                    }
                    $used += $extraBytes;
                    if (hrtime(true) - $budget->grossSqlElapsedNs > $budget->computeDeadlineNs
                        || memory_get_peak_usage(true) > min(100663296, $budget->peakLimitBytes)
                        || memory_get_usage(true) + 8388608 > min(100663296, $budget->peakLimitBytes)
                        || memory_get_usage(true) - $budget->allocationBaselineBytes + 8388608 > min(50331648, $budget->allocationLimitBytes)) {
                        throw new \OverflowException('Goal compute budget exceeded.');
                    }
                };
            }
            $missingReason = 'validation_pending' === $cohorts->netForecast->validationStatus ? 'validation_pending' : 'orders_coverage_unavailable';
            $calculatedAt = $cohorts->calculatedAt;
            $today = $calculatedAt->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d');
            $methodology = $cohorts->methodology;
            $ordersCoverage = $cohorts->ordersCoverage;
            $returnsCoverage = $cohorts->returnsCoverage;
            $metadata = SalesPlanPerformanceNetMetadata::of($cohorts->netForecast);
            $bySku = [];
            foreach ($cohorts->skus as $sku) {
                foreach ($sku->points as $point) {
                    $bySku[$sku->marketplaceSku][$point->bucketFrom.'|'.$point->bucketTo] = $point;
                }
            }
            unset($cohorts, $sku, $point);
            $build = function (?\Closure $guard, string $missingReason) use (&$bySku, $rows, $base, $metric, $today, $hasMore, $scope, $planId, $from, $to, $request, $calculatedAt, $methodology, $ordersCoverage, $returnsCoverage, $metadata): SalesPlanPerformanceView {
                $guard?->__invoke();
                $skus = [];
                foreach ($rows as $row) {
                    $points = [];
                    foreach ($row->points as $p) {
                        $c = $bySku[$row->marketplaceSku][$p->bucketFrom.'|'.$p->bucketTo] ?? throw new \UnexpectedValueException('Cohort and plan buckets differ.');
                        $periodCoverage = $c->periodCoverage;
                        $cumulativeCoverage = $c->cumulativeCoverage;
                        if ($p->bucketTo >= $today) {
                            $periodCoverage = 'complete' === $periodCoverage ? 'partial' : $periodCoverage;
                            $cumulativeCoverage = 'complete' === $cumulativeCoverage ? 'partial' : $cumulativeCoverage;
                        }
                        $days = self::days($p->bucketFrom, $p->bucketTo);
                        $cumulativeDays = self::days($base->dateFrom, $p->bucketTo);
                        $points[] = new SalesPlanPerformancePoint($p->bucketFrom, $p->bucketTo, $periodCoverage, $cumulativeCoverage,
                            $this->calculator->calculate($p->periodQuantity, Money::ofMinor($p->periodRevenueMinor, $base->currency), $days, $metric, 'complete' === $periodCoverage ? $c->period : null, $missingReason, $guard),
                            $this->calculator->calculate($p->cumulativeQuantity, Money::ofMinor($p->cumulativeRevenueMinor, $base->currency), $cumulativeDays, $metric, 'complete' === $cumulativeCoverage ? $c->cumulative : null, $missingReason, $guard));
                        $guard?->__invoke();
                    }
                    $guard?->__invoke();
                    $skus[] = new SalesPlanPerformanceSku($row->marketplaceSku, $points);
                }
                $guard?->__invoke();
                $last = [] === $rows ? null : $rows[array_key_last($rows)];
                $nextCursor = $hasMore && null !== $last ? base64_encode(json_encode(['scope' => $scope, 'sku' => $last->marketplaceSku], \JSON_THROW_ON_ERROR)) : null;
                $coverage = $ordersCoverage;
                if ($to >= $today && 'complete' === $coverage) {
                    $coverage = 'partial';
                }

                $view = new SalesPlanPerformanceView($planId, $base->id, $metric->value, $base->currency, $base->dateFrom,
                    $from, $to, $request->groupBy->value, $calculatedAt, $methodology,
                    $coverage, $returnsCoverage, $skus, $nextCursor, $metadata);
                $guard?->__invoke();

                return $view;
            };
            try {
                return $build($guard, $missingReason);
            } catch (\OverflowException) {
                // The partial view was local to the unwound projection. Free exact carriers
                // while retaining only already-read factual/gross components for fallback.
                foreach ($bySku as &$points) {
                    foreach ($points as &$point) {
                        $point = new SalesCohortPoint($point->bucketFrom, $point->bucketTo, $point->periodCoverage, $point->cumulativeCoverage,
                            $point->period?->withoutForecast(), $point->cumulative?->withoutForecast());
                    }
                    unset($point);
                }
                unset($points, $guard, $budget);

                return $build(null, 'input_budget_exceeded');
            }
        });
    }

    private static function days(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from, new \DateTimeZone('UTC')))->diff(new \DateTimeImmutable($to, new \DateTimeZone('UTC')))->days + 1;
    }

    private static function decodeCursor(?string $cursor, string $scope): ?string
    {
        if (null === $cursor) {
            return null;
        }
        $raw = base64_decode($cursor, true);
        try {
            $data = false === $raw ? null : json_decode($raw, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }
        if (!\is_array($data) || ($data['scope'] ?? null) !== $scope || !\is_string($data['sku'] ?? null) || '' === trim($data['sku']) || \strlen($data['sku']) > 64 || str_contains($data['sku'], "\0")) {
            throw new \InvalidArgumentException('Invalid or mismatched performance cursor.');
        }

        return $data['sku'];
    }
}
