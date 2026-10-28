<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\NetForecastQuantities;
use App\Ingestion\Domain\ValueObject\NetForecastRevenue;

/** One chronological pass, with exact current-period and cumulative sums. */
final readonly class ProjectNetForecastSeriesAction
{
    /** @param \Closure(): void $guard */
    public function __invoke(NetForecastBatch $batch, NetForecastSeriesRequest $request, \Closure $guard): NetForecastSeries
    {
        $guard();
        $batch->context?->assertScope($batch->companyId, $batch->accountId);
        $dates = [];
        for ($date = $request->accumulationFrom ?? $request->from; $date <= $request->to; $date = $date->modify('+1 day')) {
            $dates[] = $date->format('Y-m-d');
        }
        $expected = $request->marketplaceSkus;
        $actual = array_column($batch->skus, 'marketplaceSku');
        sort($expected, \SORT_STRING);
        sort($actual, \SORT_STRING);
        if ($actual !== $expected || !array_is_list($batch->skus)) {
            return new NetForecastSeries([], 'source_history_unavailable');
        }
        // Check the entire dense shape before a known empty day may mean exact zero.
        foreach ($batch->skus as $sku) {
            $guard();
            if (!array_is_list($sku->days) || array_column($sku->days, 'businessDate') !== $dates) {
                return new NetForecastSeries([], 'source_history_unavailable');
            }
        }
        if ([] === $batch->skus) {
            return new NetForecastSeries([], $batch->unavailableReason);
        }
        $output = [];
        $revenue = $emptyRevenue = NetForecastRevenue::empty();
        $emptyScope = new NetForecastScope(NetForecastQuantities::zero(), null, null, 'money_currency_missing', [], []);
        foreach ($batch->skus as $sku) {
            $projected = $this->projectSku($sku, $request, $guard, $revenue, $emptyScope, $emptyRevenue);
            if (null === $projected) {
                return new NetForecastSeries([], 'source_history_unavailable');
            }
            $output[] = $projected;
        }

        return new NetForecastSeries($output);
    }

    /**
     * Final exact cumulative is also the existing SKU total; never sum it twice.
     *
     * @param \Closure(): void $guard
     */
    public function projectSku(NetForecastSku $sku, NetForecastSeriesRequest $request, \Closure $guard, NetForecastRevenue &$revenue, ?NetForecastScope $emptyScope = null, ?NetForecastRevenue $emptyRevenue = null): ?NetForecastSeriesSku
    {
        $guard();
        $dates = [];
        for ($date = $request->accumulationFrom ?? $request->from; $date <= $request->to; $date = $date->modify('+1 day')) {
            $dates[] = $date->format('Y-m-d');
        }
        if (!array_is_list($sku->days) || array_column($sku->days, 'businessDate') !== $dates) {
            return null;
        }
        $emptyScope ??= new NetForecastScope(NetForecastQuantities::zero(), null, null, 'money_currency_missing', [], []);
        $zero = $emptyScope->quantities;
        $emptyRevenue ??= NetForecastRevenue::empty();
        $publishedCumulative = null;
        $publishedRevenue = null;
        $cumulative = $period = $zero;
        $cumulativeRevenue = $periodRevenue = $emptyRevenue;
        $orderedCurrency = $orderedMoneyFailure = null;
        $cumulativeReason = $periodReason = null;
        $cumulativeBasis = $periodBasis = $cumulativeReasons = $periodReasons = [];
        $points = [];
        $bucketFrom = $request->from->format('Y-m-d');
        $bucketTo = $this->bucketEnd($request, $bucketFrom);
        foreach ($sku->days as $day) {
            $guard();
            [$basis, $reasons] = $this->quality($day, $guard);
            // Exact numeric sums may be folded; first failure and currency order may not.
            $state = $day->exactRevenue()->aggregationState();
            if (null === $orderedMoneyFailure) {
                $orderedMoneyFailure = $state['failure'];
                if (null === $orderedMoneyFailure && null !== $state['currency']) {
                    if (null !== $orderedCurrency && $orderedCurrency !== $state['currency']) {
                        $orderedMoneyFailure = 'money_currency_mismatch';
                    }
                    $orderedCurrency = $state['currency'];
                }
            }
            if ($day->businessDate < $bucketFrom) {
                $cumulative = $this->add($cumulative, $day->quantities, $day->unavailableReason, $guard);
                $cumulativeRevenue = $this->addRevenue($cumulativeRevenue, $day->exactRevenue(), $guard);
                $orderedMoneyFailure ??= $cumulativeRevenue->aggregationState()['failure'];
                $cumulativeReason = self::reason($cumulativeReason, $day->unavailableReason ?? (null === $day->quantities ? 'quantity_unavailable' : null));
                $cumulativeBasis += $basis;
                $cumulativeReasons += $reasons;
                continue;
            }
            $period = $this->add($period, $day->quantities, $day->unavailableReason, $guard);
            $periodRevenue = $this->addRevenue($periodRevenue, $day->exactRevenue(), $guard);
            $orderedMoneyFailure ??= $periodRevenue->aggregationState()['failure'];
            $periodReason = self::reason($periodReason, $day->unavailableReason ?? (null === $day->quantities ? 'quantity_unavailable' : null));
            $periodBasis += $basis;
            $periodReasons += $reasons;
            if ($day->businessDate === $bucketTo) {
                // Only exact transient accumulators feed the prefix, never published Money.
                $cumulative = $this->add($cumulative, $period, $periodReason, $guard);
                if (null === $orderedMoneyFailure) {
                    $cumulativeRevenue = $this->addRevenue($cumulativeRevenue, $periodRevenue, $guard);
                    $orderedMoneyFailure = $cumulativeRevenue->aggregationState()['failure'];
                } elseif ($cumulativeRevenue->aggregationState()['failure'] !== $orderedMoneyFailure) {
                    $cumulativeRevenue = NetForecastRevenue::unavailable($orderedMoneyFailure);
                }
                $cumulativeReason = self::reason($cumulativeReason, $periodReason);
                $cumulativeBasis += $periodBasis;
                $cumulativeReasons += $periodReasons;
                $publishedCumulative = $this->publish($cumulative, $cumulativeRevenue, $cumulativeReason, $cumulativeBasis, $cumulativeReasons, $guard, $publishedCumulative, $publishedRevenue);
                $publishedRevenue = $cumulativeRevenue;
                $points[] = new NetForecastSeriesPoint($bucketFrom, $bucketTo,
                    $this->publish($period, $periodRevenue, $periodReason, $periodBasis, $periodReasons, $guard, $emptyScope, $emptyRevenue),
                    $publishedCumulative);
                $guard();
                $period = $zero;
                $periodRevenue = $emptyRevenue;
                $periodReason = null;
                $periodBasis = $periodReasons = [];
                $bucketFrom = (new \DateTimeImmutable($bucketTo))->modify('+1 day')->format('Y-m-d');
                $bucketTo = $this->bucketEnd($request, $bucketFrom);
            }
        }
        $revenue = null === $cumulative ? NetForecastRevenue::unavailable($cumulativeReason ?? 'quantity_unavailable') : $cumulativeRevenue;
        $guard();

        return new NetForecastSeriesSku($sku->marketplaceSku, $points);
    }

    private static function reason(?string $current, ?string $next): ?string
    {
        if (null === $current) {
            return $next;
        }
        if (null === $next) {
            return $current;
        }
        $model = ['return_risk_unavailable', 'open_retention_unavailable', 'training_context_missing'];

        return \in_array($current, $model, true) && !\in_array($next, $model, true) ? $next : $current;
    }

    private function bucketEnd(NetForecastSeriesRequest $request, string $from): string
    {
        $date = new \DateTimeImmutable($from);
        $end = match ($request->groupBy) {
            'week' => $date->modify('+'.(7 - (int) $date->format('N')).' days')->format('Y-m-d'),
            'month' => $date->format('Y-m-t'),
            'period' => $request->to->format('Y-m-d'),
            default => $from,
        };

        return min($end, $request->to->format('Y-m-d'));
    }

    /** @param \Closure(): void $guard */
    private function add(?NetForecastQuantities $sum, ?NetForecastQuantities $next, ?string $reason, \Closure $guard): ?NetForecastQuantities
    {
        $guard();
        $result = null === $sum || null === $next || null !== $reason ? null : $sum->plus($next);
        $guard();

        return $result;
    }

    /** @param \Closure(): void $guard */
    private function addRevenue(NetForecastRevenue $sum, NetForecastRevenue $next, \Closure $guard): NetForecastRevenue
    {
        $guard();
        try {
            $result = $sum->plus($next);
        } catch (\InvalidArgumentException) {
            $result = NetForecastRevenue::unavailable('money_currency_mismatch');
        }
        $guard();

        return $result;
    }

    /** @param \Closure(): void $guard
     * @return array{array<string, true>, array<string, true>} */
    private function quality(NetForecastDay $day, \Closure $guard): array
    {
        $basis = $reasons = [];
        if (null !== $day->unavailableReason) {
            $reasons[$day->unavailableReason] = true;
        }
        foreach ($day->groups as $group) {
            $guard();
            foreach ($group->parts as $part) {
                $guard();
                foreach ([$part->delivered, $part->openPre, $part->openPost] as $component) {
                    if (null !== $component->basis) {
                        $basis[$component->basis] = true;
                    }
                    if (null !== $component->unavailableReason) {
                        $reasons[$component->unavailableReason] = true;
                    }
                    foreach ($component->attempts as $attempt) {
                        if (null !== $attempt['reason']) {
                            $reasons[$attempt['reason']] = true;
                        }
                    }
                }
            }
        }

        return [$basis, $reasons];
    }

    /** @param array<string, true> $basis
     * @param array<string, true> $reasons
     * @param \Closure(): void $guard */
    private function publish(?NetForecastQuantities $quantities, NetForecastRevenue $revenue, ?string $reason, array $basis, array $reasons, \Closure $guard, ?NetForecastScope $previous = null, ?NetForecastRevenue $previousRevenue = null): NetForecastScope
    {
        $guard();
        $basis = array_keys($basis);
        $reasons = array_keys($reasons);
        sort($basis, \SORT_STRING);
        sort($reasons, \SORT_STRING);
        if (null !== $previous && $previous->quantities === $quantities && $previousRevenue === $revenue
            && $previous->unavailableReason === $reason && $previous->basis === $basis && $previous->reasons === $reasons) {
            $guard();

            return $previous;
        }
        $money = null;
        $moneyReason = $reason ?? $revenue->unavailableReason();
        if (null !== $quantities) {
            try {
                $money = $revenue->toMoney();
            } catch (\OverflowException) {
                $moneyReason = 'money_overflow';
            }
        }

        $scope = new NetForecastScope($quantities, $money, $reason, $moneyReason, $basis, $reasons);
        $guard();

        return $scope;
    }
}
