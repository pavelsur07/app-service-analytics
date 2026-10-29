<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\Ingestion\Application\Facade\IngestionFacade;
use App\Ingestion\Application\Facade\SalesCohortGrouping;
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
            $bySku = [];
            foreach ($cohorts->skus as $sku) {
                foreach ($sku->points as $point) {
                    $bySku[$sku->marketplaceSku][$point->bucketFrom.'|'.$point->bucketTo] = $point;
                }
            }
            $today = $cohorts->calculatedAt->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d');
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
                        $this->calculator->calculate($p->periodQuantity, Money::ofMinor($p->periodRevenueMinor, $base->currency), $days, $metric, 'complete' === $periodCoverage ? $c->period : null),
                        $this->calculator->calculate($p->cumulativeQuantity, Money::ofMinor($p->cumulativeRevenueMinor, $base->currency), $cumulativeDays, $metric, 'complete' === $cumulativeCoverage ? $c->cumulative : null));
                }
                $skus[] = new SalesPlanPerformanceSku($row->marketplaceSku, $points);
            }
            $last = [] === $rows ? null : $rows[array_key_last($rows)];
            $nextCursor = $hasMore && null !== $last ? base64_encode(json_encode(['scope' => $scope, 'sku' => $last->marketplaceSku], \JSON_THROW_ON_ERROR)) : null;
            $coverage = $cohorts->ordersCoverage;
            if ($to >= $today && 'complete' === $coverage) {
                $coverage = 'partial';
            }

            return new SalesPlanPerformanceView($planId, $base->id, $metric->value, $base->currency, $base->dateFrom,
                $from, $to, $request->groupBy->value, $cohorts->calculatedAt, $cohorts->methodology,
                $coverage, $cohorts->returnsCoverage, $skus, $nextCursor, SalesPlanPerformanceNetMetadata::of($cohorts->netForecast));
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
