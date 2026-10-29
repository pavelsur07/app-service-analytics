<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\NetForecastArtifactPublication;
use App\Ingestion\Domain\NetForecastArtifactRepository;
use App\Ingestion\Domain\NetForwardArtifactKey;
use App\Ingestion\Domain\NetForwardArtifactKind;
use App\Ingestion\Domain\ValueObject\NetForecastQuantities;
use App\Ingestion\Domain\ValueObject\NetForecastRevenue;
use App\Ingestion\Domain\ValueObject\NetForwardReturnDelta;
use App\Ingestion\Domain\ValueObject\NetForwardReturnRevisionPolicy;
use App\Ingestion\Domain\ValueObject\NetForwardTerminalCoverage;
use App\Ingestion\Domain\ValueObject\NetForwardTruth;
use App\Ingestion\Domain\ValueObject\NetForwardTruthResult;
use App\Ingestion\Infrastructure\Config\NetForecastCaptureEpochs;
use App\Ingestion\Infrastructure\Query\NetForecast\NetForwardEvidenceQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetDayRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetGroupRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetPartRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetRequest;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingValue;
use App\Shared\Application\QueryTelemetry;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/** New observation and new immutable artifact; input and previous truth never mutate. */
final readonly class CaptureNetForecastTruthAction
{
    public function __construct(private Connection $connection, private NetForecastArtifactRepository $repository, private NetForwardArtifactReader $reader, private PublishNetForecastCaptureAction $publish, private NetForwardManifestCodec $codec, private NetForwardInputCodec $inputs, private NetForecastCaptureEpochs $epochs, private NetTargetQuery $targets, private NetForwardEvidenceQuery $evidence, private ClockInterface $clock = new NativeClock(), #[Autowire('%app.version%')] private string $softwareRelease = 'unversioned', private QueryTelemetry $telemetry = new QueryTelemetry())
    {
    }

    public function __invoke(string $companyId, string $accountId, string $inputId, string $inputHash, string $requestId): VerifiedNetForwardArtifact
    {
        return $this->telemetry->run('net_forecast.truth', $companyId, $accountId, $requestId, fn () => $this->capture($companyId, $accountId, $inputId, $inputHash, $requestId));
    }

    private function capture(string $companyId, string $accountId, string $inputId, string $inputHash, string $requestId): VerifiedNetForwardArtifact
    {
        $this->reader->assertNoTransaction();
        $deadline = (int) (hrtime(true) + 30000000000);
        $requestHash = hash('sha256', json_encode(['policy' => 'component_truth_h60_l7_v1', 'inputId' => $inputId, 'inputHash' => $inputHash], \JSON_THROW_ON_ERROR));
        if (null !== $this->repository->find($companyId, $accountId, NetForwardArtifactKind::Truth, $requestId)) {
            return $this->reader->read($companyId, $accountId, NetForwardArtifactKind::Truth, $requestId, requestHash: $requestHash, deadline: $deadline);
        }
        $parent = $this->reader->read($companyId, $accountId, NetForwardArtifactKind::Input, $inputId, $inputHash, deadline: $deadline);
        $request = null;
        $groups = [];
        $parentDays = [];
        $parentScopeComplete = true;
        $parentTargetMetadata = null;
        foreach ($this->reader->segments($companyId, $accountId, $parent, $deadline) as $segment) {
            foreach ($segment['rows'] as $row) {
                if ('target_request' === $segment['type']) {
                    $request = $this->inputs->unpack($row['value'] ?? null);
                } elseif ('target_metadata' === $segment['type']) {
                    $parentTargetMetadata = $row;
                } elseif ('target_day' === $segment['type']) {
                    $day = $this->inputs->unpack($row['value'] ?? null);
                    $sku = $row['sku'] ?? null;
                    if (!$day instanceof NetTargetDayRow || !\is_string($sku) || isset($parentDays[$sku][$day->businessDate])) {
                        throw new \UnexpectedValueException('Invalid truth input day.');
                    }
                    $parentDays[$sku][$day->businessDate] = $day;
                    $parentScopeComplete = $parentScopeComplete && (null === $day->unavailableReason && ([] !== $day->groups || $day->emptyComplete));
                    foreach ($day->groups as $group) {
                        $parentScopeComplete = $parentScopeComplete && $group->inputComplete && null === $group->unavailableReason;
                        $groups[] = $group;
                    }
                }
            }
        }
        if (!$request instanceof NetTargetRequest || \count($groups) > 20000 || !\is_array($parentTargetMetadata)) {
            throw new \UnexpectedValueException('Invalid truth target input.');
        }
        $parentScopeComplete = $parentScopeComplete && null === ($parentTargetMetadata['unavailableReason'] ?? null) && null === ($parentTargetMetadata['budgetFailure'] ?? null);
        $date = $request->accumulationFrom ?? $request->from;
        while ($date <= $request->to) {
            $key = $date->format('Y-m-d');
            foreach ($request->marketplaceSkus as $sku) {
                if (!isset($parentDays[$sku][$key])) {
                    $parentScopeComplete = false;
                }
            }
            $date = $date->modify('+1 day');
        }
        $spool = new NetForwardSpool($this->codec, $deadline);
        $allocation = memory_get_usage(true);
        $prepared = (new NetTrainingSnapshot($this->connection))->read(function (string $snapshotId) use ($companyId, $accountId, $requestId, $requestHash, $request, $parent, $groups, $parentScopeComplete, $spool, $allocation): PreparedNetForwardArtifact {
            $readAt = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
            $epoch = $this->epochs->at($readAt);
            $context = null === $epoch ? null : new NetTrainingContext($companyId, $accountId, $readAt, $epoch, $snapshotId);
            $this->connection->executeStatement('SET LOCAL jit = off');
            $this->connection->executeStatement("SET LOCAL statement_timeout = '5s'");
            $current = [];
            $returns = [];
            $receipts = [];
            $statuses = [];
            $currentScopeComplete = false;
            if (null !== $context) {
                $headroom = min(50331648, 100663296 - memory_get_usage(true) - 8388608);
                if ($headroom <= 0) {
                    throw new \OverflowException('Truth target headroom exhausted.');
                }
                $query = $this->targets->build($companyId, $accountId, $context, $request);
                $query->setParameter('maxPhpAllocationBytes', $headroom);
                $rows = $query->executeQuery()->fetchAllAssociative();
                $batch = $this->targets->mapRows($context, $request, $rows);
                unset($rows);
                $currentScopeComplete = null === $batch->unavailableReason && null === $batch->budgetFailure;
                $seenSkuDays = [];
                foreach ($batch->skus as $sku) {
                    foreach ($sku->days as $day) {
                        $seenSkuDays[$sku->marketplaceSku][$day->businessDate] = true;
                        $currentScopeComplete = $currentScopeComplete && null === $day->unavailableReason
                            && ([] !== $day->groups || $day->emptyComplete);
                        foreach ($day->groups as $group) {
                            $currentScopeComplete = $currentScopeComplete && $group->inputComplete && null === $group->unavailableReason;
                            foreach ($group->parts as $part) {
                                $current[$part->sourceRowId] = ['group' => $group, 'part' => $part];
                            }
                        }
                    }
                }
                $date = $request->accumulationFrom ?? $request->from;
                while ($date <= $request->to) {
                    $key = $date->format('Y-m-d');
                    foreach ($request->marketplaceSkus as $sku) {
                        if (!isset($seenSkuDays[$sku][$key])) {
                            $currentScopeComplete = false;
                        }
                    }
                    $date = $date->modify('+1 day');
                }
                unset($batch);
                foreach ($this->evidence->pages($companyId, $accountId, $context, $request, 'target', $spool->deadlineNs) as $page) {
                    $spool->guard();
                    if (memory_get_usage(true) - $allocation + 8388608 > 50331648) {
                        throw new \OverflowException('Truth working allocation budget exceeded.');
                    }
                    $this->telemetry->count('evidence.target.rows', \count($page));
                    foreach ($page as $row) {
                        $values = $row->values;
                        if ('input_budget' === $row->relation && true !== ($values['ok'] ?? null)) {
                            throw new \OverflowException('Truth evidence population budget exceeded.');
                        }
                        $spool->append('truth_evidence', ['relation' => $row->relation, 'values' => $values]);
                        if ('return_revisions' === $row->relation) {
                            $source = NetTrainingValue::nullableString($values['source_row_id']) ?? throw new \UnexpectedValueException('Missing return identity.');
                            $revision = NetTrainingValue::integer($values['fact_revision']);
                            $returns[$source][$revision] = $values;
                        } elseif ('receipt_inputs' === $row->relation) {
                            $receipts[] = $values;
                        } elseif ('status_inputs' === $row->relation) {
                            $posting = NetTrainingValue::nullableString($values['posting_number']) ?? '';
                            $statuses[$posting][] = $values;
                        }
                    }
                }
            }
            $knownByGroup = [];
            $knownOwners = [];
            $partCounts = [];
            foreach ($groups as $group) {
                $key = json_encode([$group->orderNumber, $group->marketplaceSku], \JSON_THROW_ON_ERROR);
                $partCounts[$key] = ($partCounts[$key] ?? 0) + \count($group->parts);
                foreach ($group->returns as $return) {
                    $knownByGroup[$key][$return->sourceRowId] = $return->latestFactRevision;
                    $knownOwners[$return->sourceRowId][$key] = true;
                }
            }
            $byGroupDeltas = [];
            foreach ($returns as $source => $revisions) {
                $spool->guard();
                ksort($revisions, \SORT_NUMERIC);
                $previousQuantity = 0;
                $previousType = $previousOrder = $previousSku = null;
                foreach ($revisions as $revision => $value) {
                    $order = NetTrainingValue::nullableString($value['order_number']);
                    $sku = NetTrainingValue::nullableString($value['marketplace_sku']);
                    $type = NetTrainingValue::nullableString($value['return_type']);
                    $quantity = NetTrainingValue::integer($value['quantity']);
                    $atValue = NetTrainingValue::nullableString($value['captured_at']) ?? throw new \UnexpectedValueException('Missing return capture time.');
                    $at = new \DateTimeImmutable($atValue, new \DateTimeZone('UTC'));
                    $owner = json_encode([$order, $sku], \JSON_THROW_ON_ERROR);
                    $owners = $knownOwners[$source] ?? [];
                    $owners[$owner] = true;
                    foreach ($owners as $key => $_) {
                        if (!isset($partCounts[$key])) {
                            continue;
                        }
                        $delta = 'ClientReturn' === $type ? $quantity - ('ClientReturn' === $previousType ? $previousQuantity : 0) : 0;
                        $sameIdentity = $key === $owner && (null === $previousOrder || $previousOrder === $order) && (null === $previousSku || $previousSku === $sku);
                        $valid = NetForwardReturnRevisionPolicy::isProven($previousType, $type, $sameIdentity);
                        $byGroupDeltas[$key][] = new NetForwardReturnDelta($delta, $at, $revision <= ($knownByGroup[$key][$source] ?? 0), $valid, $type);
                    }
                    $previousQuantity = $quantity;
                    $previousType = $type;
                    $previousOrder = $order;
                    $previousSku = $sku;
                }
            }
            unset($returns);
            $allReady = [] !== $groups && $parentScopeComplete && $currentScopeComplete;
            $total = 0;
            $money = NetForecastRevenue::empty();
            foreach ($groups as $group) {
                $key = json_encode([$group->orderNumber, $group->marketplaceSku], \JSON_THROW_ON_ERROR);
                $deltas = $byGroupDeltas[$key] ?? [];
                foreach ($group->parts as $part) {
                    $spool->guard();
                    $live = $current[$part->sourceRowId] ?? null;
                    $truth = $this->component($group, $part, $live, $deltas, $statuses[$part->postingNumber ?? ''] ?? [], $receipts, $parent->readAt, $readAt, $partCounts[$key]);
                    $allReady = $allReady && 'ready' === $truth->state;
                    if (null !== $truth->quantity) {
                        if ($truth->quantity > \PHP_INT_MAX - $total) {
                            throw new \OverflowException('Truth aggregate overflow.');
                        }
                        $total += $truth->quantity;
                        $partMoney = NetForecastRevenue::fromSource($part->trace->amountMinor, $part->trace->currency, NetForecastQuantities::fromComponents((string) $truth->quantity, '0', '0', '1', '0', '1'));
                        try {
                            $money = $money->plus($partMoney);
                        } catch (\InvalidArgumentException) {
                            $money = NetForecastRevenue::unavailable('money_currency_mismatch');
                        }
                    }
                    $spool->append('truth_part', ['orderNumber' => $group->orderNumber, 'sku' => $group->marketplaceSku, 'day' => $group->businessDate, 'sourceRowId' => $part->sourceRowId, 'inputD' => $part->currentD, 'inputKnownR' => $part->knownR, 'origin' => $this->inputs->pack($part->deliveryOrigin), 'unitPriceMinor' => $part->trace->amountMinor, 'currency' => $part->trace->currency, 'result' => $this->inputs->pack($truth)]);
                }
            }
            [$truthMoney, $moneyReason] = $allReady ? $money->toMoneyOutcome() : [null, 'components_not_ready'];
            $spool->append('truth_summary', ['state' => $allReady ? 'ready' : 'pending', 'quantity' => $allReady ? (string) $total : null, 'money' => $allReady ? $this->inputs->pack($truthMoney) : null, 'moneyReason' => $moneyReason, 'componentGroups' => \count($groups), 'reason' => $allReady ? null : 'components_not_ready']);
            $attempt = Uuid::v7()->toRfc4122();
            $rootData = ['companyId' => $companyId, 'marketplaceAccountId' => $accountId, 'kind' => 'truth', 'requestId' => $requestId, 'requestHash' => $requestHash, 'attemptId' => $attempt, 'readAtUtc' => $readAt->format('Y-m-d\TH:i:s.u\Z'), 'parent' => ['requestId' => $parent->publication->root->requestId, 'artifactHash' => $parent->publication->root->sha256], 'chunks' => $spool->descriptors(), 'metadata' => ['policy' => 'component_truth_h60_l7_v1', 'snapshot' => $snapshotId, 'epoch' => $this->inputs->pack($epoch), 'horizonDays' => 60, 'reportingLagDays' => 7, 'publicPolicy' => 'validation_pending', 'softwareRelease' => $this->softwareRelease]];
            $parentMetadata = $parent->manifest['metadata'];
            if (\is_array($parentMetadata) && \is_array($parentMetadata['qualityProtocol'] ?? null)) {
                $rootData['metadata']['qualityProtocol'] = $parentMetadata['qualityProtocol'];
            }
            $root = $this->codec->encode($rootData);

            return new PreparedNetForwardArtifact(new NetForecastArtifactPublication(new NetForwardArtifactKey($companyId, $accountId, NetForwardArtifactKind::Truth, $requestId, $attempt, hash('sha256', $root)), $requestHash, \strlen($root)), $root, $spool);
        });

        return ($this->publish)($companyId, $accountId, $prepared);
    }

    /** @param array{group: NetTargetGroupRow, part: NetTargetPartRow}|null $live
     * @param list<NetForwardReturnDelta> $deltas
     * @param list<array<array-key, mixed>> $statuses
     * @param list<array<array-key, mixed>> $receipts */
    private function component(NetTargetGroupRow $group, NetTargetPartRow $part, ?array $live, array $deltas, array $statuses, array $receipts, \DateTimeImmutable $inputAt, \DateTimeImmutable $readAt, int $partCount): NetForwardTruthResult
    {
        if (!$part->inputComplete || null !== $part->unavailableReason || null === $live) {
            return new NetForwardTruthResult('pending', null, $part->unavailableReason ?? 'source_history_unavailable', null);
        }
        $now = $live['part'];
        if ($live['group']->orderNumber !== $group->orderNumber || $live['group']->marketplaceSku !== $group->marketplaceSku || $now->rawQuantity !== $part->rawQuantity || $now->trace->firstRevisionId !== $part->trace->firstRevisionId) {
            return new NetForwardTruthResult('ineligible', null, 'source_correction', null);
        }
        if ($partCount > 1 && [] !== array_filter($deltas, static fn (NetForwardReturnDelta $delta): bool => !$delta->visibleAtInput && 0 !== $delta->quantity)) {
            return new NetForwardTruthResult('ineligible', null, 'ambiguous_component_allocation', null);
        }
        $open = ($part->eligibleOpenPre ?? 0) + ($part->eligibleOpenPost ?? 0);
        if (($part->currentD ?? 0) > 0 || ($open > 0 && null !== $now->deliveryOrigin) || null !== $part->deliveryOrigin) {
            $origin = $open > 0 ? $now->deliveryOrigin : $part->deliveryOrigin;
            if (null === $origin || (0 === $open && $origin->format('U.u') !== $now->deliveryOrigin?->format('U.u'))) {
                return new NetForwardTruthResult('ineligible', null, 'delivery_origin_ambiguous', null);
            }
            $coverage = $now->inputComplete && null === $now->unavailableReason && null !== $now->readiness && $now->readiness->returnsCoverageComplete && $now->readiness->coverageThrough >= $origin->modify('+67 days') && null !== $now->readiness->deepCompletedAt;
            $baseline = $part->currentD ?? 0;
            if ($open > 0) {
                $resolvedBaseline = NetForwardTruth::deliveredBaselineForOpenInput(
                    $now->rawQuantity,
                    $now->currentD,
                    $now->knownR,
                    $now->t1,
                    $now->t2,
                    $now->p,
                    $now->eligibleOpenPre,
                    $now->eligibleOpenPost,
                    $now->unknownQuantity,
                );
                if (null === $resolvedBaseline) {
                    return new NetForwardTruthResult('pending', null, $now->unavailableReason ?? 'outcome_not_observed', $origin->modify('+67 days'));
                }
                $baseline = $resolvedBaseline;
            }

            return NetForwardTruth::delivered($baseline, $origin, $inputAt, $readAt, $deltas, $open > 0, $coverage);
        }
        if ($now->inputComplete && null === $now->unavailableReason && (($now->t1 ?? 0) + ($now->t2 ?? 0) + ($now->p ?? 0)) > 0 && 0 === ($now->unknownQuantity ?? 1)) {
            $terminal = null;
            foreach ($statuses as $status) {
                if ('cancelled' === ($status['status'] ?? null) && \is_string($status['observed_at'] ?? null)) {
                    $at = new \DateTimeImmutable($status['observed_at'], new \DateTimeZone('UTC'));
                    $terminal = null === $terminal ? $at : min($terminal, $at);
                }
            }
            if (null !== $terminal) {
                return NetForwardTruth::terminal($terminal, $readAt, NetForwardTerminalCoverage::isComplete($terminal, $receipts));
            }
        }
        // Known returned units at T are already absent from the rolling target.
        if ($now->inputComplete && null === $now->unavailableReason && 0 === $open && 0 === $part->currentD && 0 === ($part->unknownQuantity ?? 1) && ($part->knownR ?? 0) === $part->rawQuantity) {
            return new NetForwardTruthResult('ready', 0, null, $inputAt);
        }

        return new NetForwardTruthResult('pending', null, $now->unavailableReason ?? 'outcome_not_observed', null);
    }
}
