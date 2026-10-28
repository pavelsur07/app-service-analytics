<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\DeliveredRiskQuantities;
use App\Ingestion\Domain\ValueObject\GrossProbability;
use App\Ingestion\Domain\ValueObject\NetForecastQuantities;
use App\Ingestion\Domain\ValueObject\OpenRetainedQuantities;
use App\Ingestion\Domain\ValueObject\ReturnProbability;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetBatch;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetDayRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetGroupRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetLimits;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetPartRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetReadinessRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetSkuRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;

/**
 * Pure current-RR composition. Memo values never retain targets or training graphs.
 *
 * @phpstan-type DeliveredCoefficient array{probability: ?ReturnProbability, diagnostic: NetForecastComponent}
 * @phpstan-type OpenCoefficient array{gross: ?GrossProbability, cumulativeH: ?ReturnProbability, diagnostic: NetForecastComponent}
 */
final readonly class AggregateNetForecastAction
{
    public function __construct(private AssessDeliveredRiskAction $delivered, private AssessOpenRetentionAction $open, private NetTargetLimits $limits = new NetTargetLimits())
    {
    }

    public function __invoke(string $companyId, string $accountId, NetTargetBatch $targets, NetTrainingBatch $training, ?int $computeStarted = null, ?int $allocationBaseline = null, ?NetTargetLimits $runtimeLimits = null): NetForecastBatch
    {
        $started = min(hrtime(true), $computeStarted ?? hrtime(true));
        $baseline = min(memory_get_usage(true), $allocationBaseline ?? memory_get_usage(true));
        // Traverse every proof before any metadata, refusal or zero shortcut.
        $targets->context->assertScope($companyId, $accountId);
        if ($training->companyId !== $companyId || $training->accountId !== $accountId) {
            throw new \InvalidArgumentException('Training batch belongs to another company or account.');
        }
        $training->context?->assertScope($companyId, $accountId);
        $proofMismatch = false;
        foreach ($targets->skus as $sku) {
            foreach ($sku->days as $day) {
                foreach ($day->groups as $group) {
                    foreach ($group->parts as $part) {
                        if (null !== $part->readiness) {
                            $part->readiness->context->assertScope($companyId, $accountId);
                            $proofMismatch = !self::sameContext($targets->context, $part->readiness->context) || $proofMismatch;
                        }
                    }
                }
            }
        }
        $empty = [] === $targets->skus && null === $targets->unavailableReason && null === $targets->budgetFailure;
        $context = $targets->context;
        if (!$empty && (null === $context->snapshotId || '' === trim($context->snapshotId) || 'single_statement' === $context->snapshotId)) {
            return $this->refuse($companyId, $accountId, $context, 'target_snapshot_missing');
        }
        if ($proofMismatch || $training->readAt->format('U.u') !== $context->readAt->format('U.u')
            || (null !== $training->context && !self::sameContext($context, $training->context))) {
            return $this->refuse($companyId, $accountId, $context, 'training_context_mismatch');
        }
        if ($empty) {
            return new NetForecastBatch($companyId, $accountId, null, null, [], NetForecastQuantities::zero(), null);
        }
        if (null !== $targets->budgetFailure || 'input_budget_exceeded' === $targets->unavailableReason) {
            return $this->refuse($companyId, $accountId, $context, 'input_budget_exceeded');
        }
        if ('query_budget_exceeded' === $targets->unavailableReason) {
            return $this->refuse($companyId, $accountId, $context, 'query_budget_exceeded');
        }
        if ([] === $targets->skus) {
            return $this->refuse($companyId, $accountId, $context, $targets->unavailableReason ?? 'source_history_unavailable');
        }
        try {
            $runtimeLimits ??= $this->limits;
            $peakLimit = min($this->limits->phpPeakBytes, $runtimeLimits->phpPeakBytes);
            $allocationLimit = min($this->limits->phpAllocationBytes, $runtimeLimits->phpAllocationBytes);
            $seconds = min($this->limits->computeSeconds, $runtimeLimits->computeSeconds);
            $guard = static function () use ($started, $baseline, $peakLimit, $allocationLimit, $seconds): void {
                if (memory_get_peak_usage(true) > $peakLimit
                    || memory_get_usage(true) + 8388608 > $peakLimit
                    || memory_get_usage(true) - $baseline + 8388608 > $allocationLimit
                    || hrtime(true) - $started > $seconds * 1000000000) {
                    throw new \OverflowException('Forecast compute budget exceeded.');
                }
            };
            $guard();
            $this->checkShape($targets, $guard);
            $used = 0;
            $this->charge([$companyId, $accountId, $context], $used, $guard);
            $skus = $targets->skus;
            usort($skus, static fn (NetTargetSkuRow $a, NetTargetSkuRow $b): int => strcmp($a->marketplaceSku, $b->marketplaceSku));
            $output = [];
            $total = NetForecastQuantities::zero();
            $reason = null;
            $deliveredMemo = [];
            $openMemo = [];
            foreach ($skus as $sku) {
                $result = $this->sku($sku, $context, $training, $deliveredMemo, $openMemo, $used, $guard);
                $output[] = $result;
                $total = self::add($total, $result->quantities);
                $reason = self::reason($reason, $result->unavailableReason);
            }
            $reason = self::reason($reason, $targets->unavailableReason);
            if (null !== $reason) {
                $total = null;
            }
            $this->charge([$total, $reason], $used, $guard);

            return new NetForecastBatch($companyId, $accountId, $context->readAt, $context, $output, $total, $reason);
        } catch (\OverflowException) {
            // Never expose a truncated hierarchy or a partial quantity under a full-total name.
            return $this->refuse($companyId, $accountId, $context, 'input_budget_exceeded');
        }
    }

    private static function sameContext(NetTrainingContext $a, NetTrainingContext $b): bool
    {
        return $a->fingerprint() === $b->fingerprint()
            && $a->epoch->start->format('U.u') === $b->epoch->start->format('U.u')
            && $a->epoch->end?->format('U.u') === $b->epoch->end?->format('U.u');
    }

    private function refuse(string $companyId, string $accountId, NetTrainingContext $context, string $reason): NetForecastBatch
    {
        return new NetForecastBatch($companyId, $accountId, $context->readAt, $context, [], null, $reason);
    }

    /** @param \Closure(): void $guard */
    private function checkShape(NetTargetBatch $targets, \Closure $guard): void
    {
        if (!array_is_list($targets->skus) || \count($targets->skus) > 50) {
            throw new \OverflowException('Target SKU budget exceeded.');
        }
        $groups = 0;
        $sources = 0;
        foreach ($targets->skus as $sku) {
            $guard();
            if (!array_is_list($sku->days) || \count($sku->days) > 184) {
                throw new \OverflowException('Target day budget exceeded.');
            }
            foreach ($sku->days as $day) {
                $guard();
                if (!array_is_list($day->groups) || \count($day->groups) > $this->limits->groupsPerSkuDay) {
                    throw new \OverflowException('Target group budget exceeded.');
                }
                $groups += \count($day->groups);
                if ($groups > $this->limits->groups) {
                    throw new \OverflowException('Target groups budget exceeded.');
                }
                foreach ($day->groups as $group) {
                    $guard();
                    $size = \count($group->parts) + \count($group->returns);
                    if (!array_is_list($group->parts) || !array_is_list($group->returns) || $size > $this->limits->sourcesPerGroup) {
                        throw new \OverflowException('Target sources budget exceeded.');
                    }
                    $sources += $size;
                    if ($sources > $this->limits->sourceKeys) {
                        throw new \OverflowException('Target source keys budget exceeded.');
                    }
                    foreach ($group->parts as $part) {
                        $guard();
                        if (!array_is_list($part->trace->statusWitnessIds) || !array_is_list($part->trace->receiptWitnessIds)
                            || \count($part->trace->statusWitnessIds) > 128 || \count($part->trace->receiptWitnessIds) > 128) {
                            throw new \OverflowException('Target witness budget exceeded.');
                        }
                    }
                }
            }
        }
    }

    /**
     * @param array<string, DeliveredCoefficient> $deliveredMemo
     * @param array<string, OpenCoefficient>      $openMemo
     * @param \Closure(): void                    $guard
     */
    private function sku(NetTargetSkuRow $sku, NetTrainingContext $context, NetTrainingBatch $training, array &$deliveredMemo, array &$openMemo, int &$used, \Closure $guard): NetForecastSku
    {
        $days = $sku->days;
        usort($days, static fn (NetTargetDayRow $a, NetTargetDayRow $b): int => strcmp($a->businessDate, $b->businessDate));
        $output = [];
        $total = NetForecastQuantities::zero();
        $reason = [] === $days ? 'source_history_unavailable' : null;
        foreach ($days as $day) {
            $guard();
            $result = $this->day($sku->marketplaceSku, $day, $context, $training, $deliveredMemo, $openMemo, $used, $guard);
            $output[] = $result;
            $total = self::add($total, $result->quantities);
            $reason = self::reason($reason, $result->unavailableReason);
        }
        if (null !== $reason) {
            $total = null;
        }
        $this->charge([$sku->marketplaceSku, $total, $reason], $used, $guard);

        return new NetForecastSku($sku->marketplaceSku, $output, $total, $reason);
    }

    /**
     * @param array<string, DeliveredCoefficient> $deliveredMemo
     * @param array<string, OpenCoefficient>      $openMemo
     * @param \Closure(): void                    $guard
     */
    private function day(string $sku, NetTargetDayRow $day, NetTrainingContext $context, NetTrainingBatch $training, array &$deliveredMemo, array &$openMemo, int &$used, \Closure $guard): NetForecastDay
    {
        $groups = $day->groups;
        usort($groups, static fn (NetTargetGroupRow $a, NetTargetGroupRow $b): int => strcmp($a->orderNumber ?? '', $b->orderNumber ?? ''));
        $output = [];
        $total = NetForecastQuantities::zero();
        $reason = [] === $groups && !$day->emptyComplete ? 'source_history_unavailable' : null;
        foreach ($groups as $group) {
            $guard();
            $result = $this->group($sku, $day->businessDate, $group, $context, $training, $deliveredMemo, $openMemo, $used, $guard);
            $output[] = $result;
            $total = self::add($total, $result->quantities);
            $reason = self::reason($reason, $result->unavailableReason);
        }
        $reason = self::reason($reason, $day->unavailableReason);
        if (null !== $reason) {
            $total = null;
        }
        $this->charge([$day->businessDate, $total, $reason], $used, $guard);

        return new NetForecastDay($day->businessDate, $output, $total, $reason);
    }

    /**
     * @param array<string, DeliveredCoefficient> $deliveredMemo
     * @param array<string, OpenCoefficient>      $openMemo
     * @param \Closure(): void                    $guard
     */
    private function group(string $sku, string $date, NetTargetGroupRow $group, NetTrainingContext $context, NetTrainingBatch $training, array &$deliveredMemo, array &$openMemo, int &$used, \Closure $guard): NetForecastGroup
    {
        $parts = $group->parts;
        usort($parts, static fn (NetTargetPartRow $a, NetTargetPartRow $b): int => strcmp($a->sourceRowId, $b->sourceRowId));
        $output = [];
        $total = NetForecastQuantities::zero();
        $identityReason = null === $group->orderNumber || '' === trim($group->orderNumber) || '' === trim($sku)
            || $sku !== $group->marketplaceSku || $date !== $group->businessDate ? 'target_identity_mismatch' : null;
        $reason = [] === $parts ? 'source_history_unavailable' : $identityReason;
        foreach ($parts as $part) {
            $guard();
            $result = $this->part($group, $part, $context, $training, $identityReason, $deliveredMemo, $openMemo, $used, $guard);
            $output[] = $result;
            $total = self::add($total, $result->quantities);
            $reason = self::reason($reason, $result->unavailableReason);
        }
        $reason = self::reason($reason, $group->unavailableReason ?? (!$group->inputComplete ? 'target_input_incomplete' : null));
        if (null !== $reason) {
            $total = null;
        }
        $this->charge([$group->orderNumber, $group->marketplaceSku, $group->businessDate, $group->returns, $total, $reason], $used, $guard);

        return new NetForecastGroup($group->orderNumber, $group->marketplaceSku, $group->businessDate, $output, $group->returns, $total, $reason);
    }

    /**
     * @param array<string, DeliveredCoefficient> $deliveredMemo
     * @param array<string, OpenCoefficient>      $openMemo
     * @param \Closure(): void                    $guard
     */
    private function part(NetTargetGroupRow $group, NetTargetPartRow $part, NetTrainingContext $context, NetTrainingBatch $training, ?string $identityReason, array &$deliveredMemo, array &$openMemo, int &$used, \Closure $guard): NetForecastPart
    {
        $reason = $identityReason ?? self::partReason($part);
        if (null !== $reason) {
            $delivered = $pre = $post = self::diagnostic($reason);
            $quantity = null;
        } else {
            \assert(null !== $group->orderNumber && null !== $part->currentD && null !== $part->eligibleOpenPre && null !== $part->eligibleOpenPost);
            $d = $this->delivered($group, $part, $context, $training, $deliveredMemo);
            $oPre = $this->open($group, $part->eligibleOpenPre, OpenTargetEvidence::BEFORE_HANDOVER, $context, $training, $openMemo);
            $oPost = $this->open($group, $part->eligibleOpenPost, OpenTargetEvidence::AFTER_HANDOVER, $context, $training, $openMemo);
            $delivered = $d['diagnostic'];
            $pre = $oPre['diagnostic'];
            $post = $oPost['diagnostic'];
            $reason = self::reason(self::reason($delivered->unavailableReason, $pre->unavailableReason), $post->unavailableReason);
            $quantity = null;
            if (null === $reason) {
                $risk = null === $d['probability'] ? DeliveredRiskQuantities::withoutFutureReturns($part->currentD) : DeliveredRiskQuantities::fromProbability($part->currentD, $d['probability']);
                $preQ = null === $oPre['gross'] ? OpenRetainedQuantities::zero() : OpenRetainedQuantities::fromProbabilities($part->eligibleOpenPre, $oPre['gross'], $oPre['cumulativeH']);
                $postQ = null === $oPost['gross'] ? OpenRetainedQuantities::zero() : OpenRetainedQuantities::fromProbabilities($part->eligibleOpenPost, $oPost['gross'], $oPost['cumulativeH']);
                $quantity = NetForecastQuantities::fromComponents((string) $part->currentD, '0', $risk->futureReturnsNumerator, $risk->futureReturnsDenominator, '0', '1')
                    ->plus(NetForecastQuantities::fromComponents('0', (string) $part->eligibleOpenPre, '0', '1', $preQ->retainedOpenNumerator, $preQ->retainedOpenDenominator))
                    ->plus(NetForecastQuantities::fromComponents('0', (string) $part->eligibleOpenPost, '0', '1', $postQ->retainedOpenNumerator, $postQ->retainedOpenDenominator));
            }
        }
        $this->charge([$part->sourceRowId, $quantity, $delivered, $pre, $post, $part->trace, $reason], $used, $guard);

        return new NetForecastPart($part->sourceRowId, $quantity, $delivered, $pre, $post, $part->trace, $reason);
    }

    private static function partReason(NetTargetPartRow $part): ?string
    {
        if (null !== $part->unavailableReason || !$part->inputComplete) {
            return $part->unavailableReason ?? 'target_input_incomplete';
        }
        if ('' === trim($part->sourceRowId) || $part->trace->sourceRowId !== $part->sourceRowId) {
            return 'target_identity_mismatch';
        }
        if (null === $part->unknownQuantity || $part->unknownQuantity > 0) {
            return 'unknown_outcome';
        }
        $remaining = $part->rawQuantity;
        // Bounded subtraction verifies conservation without overflowing a native sum.
        foreach ([$part->currentD, $part->knownR, $part->t1, $part->t2, $part->p, $part->eligibleOpenPre, $part->eligibleOpenPost, $part->unknownQuantity] as $quantity) {
            if (null === $quantity) {
                return 'unknown_outcome';
            }
            if ($quantity < 0 || $quantity > $remaining) {
                return 'contradictory_quantity';
            }
            $remaining -= $quantity;
        }

        return 0 !== $remaining || $part->rawQuantity < 1 ? 'contradictory_quantity' : null;
    }

    /**
     * @param array<string, DeliveredCoefficient> $memo
     *
     * @return DeliveredCoefficient
     */
    private function delivered(NetTargetGroupRow $group, NetTargetPartRow $part, NetTrainingContext $context, NetTrainingBatch $training, array &$memo): array
    {
        $origin = $part->deliveryOrigin;
        // Every cache hit repeats target-local time guards; mature proofs are never cached.
        if (null !== $origin && $origin > $context->readAt) {
            return ['probability' => null, 'diagnostic' => self::diagnostic('delivery_observation_in_future')];
        }
        if (null !== $origin && !$context->epoch->contains($origin)) {
            return ['probability' => null, 'diagnostic' => self::diagnostic('source_history_unavailable')];
        }
        if (0 !== $part->currentD && null === $origin) {
            return ['probability' => null, 'diagnostic' => self::diagnostic('delivery_observation_unknown')];
        }
        $age = null;
        $key = null;
        if (0 !== $part->currentD && null !== $origin && $context->readAt < $origin->add(new \DateInterval('P60D'))) {
            $seconds = $context->readAt->getTimestamp() - $origin->getTimestamp();
            if ($context->readAt->format('u') < $origin->format('u')) {
                --$seconds;
            }
            $age = intdiv($seconds, 86400);
            $key = $group->marketplaceSku.'|'.$age;
            if (isset($memo[$key])) {
                return $memo[$key];
            }
        }
        \assert(null !== $group->orderNumber);
        $assessment = $this->delivered->assess($context->companyId, $context->accountId, new DeliveredTargetEvidence($context, $group->orderNumber, $group->marketplaceSku, $part->currentD, $origin, true, null, self::readiness($part->readiness)), $training);
        if (null !== $age && $assessment->ageDays !== $age) {
            // Explicit precision gap: a coefficient for another bin must never enter memo.
            return ['probability' => null, 'diagnostic' => self::diagnostic('target_age_mismatch', $age)];
        }
        $attempts = [];
        foreach ($assessment->attempts as $attempt) {
            $attempts[] = ['scope' => null === $attempt->source->marketplaceSku ? 'account' : 'sku', 'reason' => $attempt->assessment->unavailableReason,
                'sampleQuantity' => $attempt->source->source?->sampleQuantity, 'samplePostings' => $attempt->source->source?->samplePostings];
        }
        $probability = $assessment->probability;
        $diagnostic = new NetForecastComponent($assessment->basis, $assessment->ageDays, null === $probability ? null : (string) $probability->numerator, null === $probability ? null : (string) $probability->denominator, null, null, $assessment->unavailableReason, $attempts);
        $coefficient = ['probability' => $probability, 'diagnostic' => $diagnostic];
        if (null !== $key) {
            $memo[$key] = $coefficient;
        }

        return $coefficient;
    }

    /**
     * @param array<string, OpenCoefficient> $memo
     *
     * @return OpenCoefficient
     */
    private function open(NetTargetGroupRow $group, int $quantity, string $stage, NetTrainingContext $context, NetTrainingBatch $training, array &$memo): array
    {
        if (0 === $quantity) {
            return ['gross' => null, 'cumulativeH' => null, 'diagnostic' => new NetForecastComponent('no_eligible_open', null, null, null, null, null, null, [])];
        }
        $key = $group->marketplaceSku.'|'.$stage;
        if (isset($memo[$key])) {
            return $memo[$key];
        }
        \assert(null !== $group->orderNumber);
        $resolution = $this->open->assess($context->companyId, $context->accountId, new OpenTargetEvidence($context, $group->orderNumber, $group->marketplaceSku, $stage, $quantity, true), $training);
        $attempts = [];
        foreach ($resolution->attempts as $attempt) {
            $source = $attempt->assessment->grossRate?->source;
            $attempts[] = ['scope' => null === $attempt->marketplaceSku ? 'account' : 'sku', 'reason' => $attempt->assessment->unavailableReason,
                'sampleQuantity' => $source?->sampleQuantity, 'samplePostings' => $source?->samplePostings];
        }
        $gross = $resolution->selected?->grossProbability;
        $cumulativeH = $resolution->selected?->cumulativeReturnProbability;
        $diagnostic = new NetForecastComponent($resolution->selected?->basis, null, null === $gross ? null : (string) $gross->numerator, null === $gross ? null : (string) $gross->denominator, null === $cumulativeH ? null : (string) $cumulativeH->numerator, null === $cumulativeH ? null : (string) $cumulativeH->denominator, $resolution->unavailableReason, $attempts);

        return $memo[$key] = ['gross' => $gross, 'cumulativeH' => $cumulativeH, 'diagnostic' => $diagnostic];
    }

    private static function readiness(?NetTargetReadinessRow $row): ?DeliveredTargetReadiness
    {
        return null === $row ? null : new DeliveredTargetReadiness($row->context, $row->orderNumber, $row->marketplaceSku, $row->deliveryOrigin, $row->coveragePolicy, $row->coverageFrom, $row->coverageThrough, $row->deepCompletedAt, $row->historyComplete, $row->postingsCoverageComplete, $row->returnsCoverageComplete);
    }

    private static function diagnostic(string $reason, ?int $age = null): NetForecastComponent
    {
        return new NetForecastComponent(null, $age, null, null, null, null, $reason, []);
    }

    private static function add(?NetForecastQuantities $a, ?NetForecastQuantities $b): ?NetForecastQuantities
    {
        return null === $a || null === $b ? null : $a->plus($b);
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

    /**
     * JSON upper bound: each string byte may become six escape bytes. Containers
     * reserve property names/punctuation; each quantity uses its reduced strings.
     * Charge only this node's fields (children were already charged), before its DTO.
     *
     * @param \Closure(): void $guard
     */
    private function charge(mixed $value, int &$used, \Closure $guard, bool $propertyKey = false): void
    {
        $guard();
        $size = match (true) {
            \is_string($value) => 2,
            null === $value => 4,
            \is_bool($value) => 5,
            \is_int($value) => 20,
            default => 128,
        };
        if (\is_string($value)) {
            $escape = $propertyKey && 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $value) ? 1 : 6;
            if (\strlen($value) > intdiv($this->limits->outputBytes - $used, $escape)) {
                throw new \OverflowException('Result byte budget exceeded.');
            }
            $size += $escape * \strlen($value);
        }
        if ($size > $this->limits->outputBytes - $used) {
            throw new \OverflowException('Result byte budget exceeded.');
        }
        $used += $size;
        if ($value instanceof \DateTimeImmutable) {
            $this->charge($value->format('Y-m-d\TH:i:s.uP'), $used, $guard);
        } elseif (\is_object($value)) {
            foreach (get_object_vars($value) as $key => $field) {
                $this->charge($key, $used, $guard, true);
                $this->charge($field, $used, $guard);
            }
        } elseif (\is_array($value)) {
            foreach ($value as $key => $field) {
                $this->charge($key, $used, $guard, true);
                $this->charge($field, $used, $guard);
            }
        }
    }
}
