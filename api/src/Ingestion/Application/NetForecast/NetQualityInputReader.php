<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\NetForwardArtifactKind;
use App\Ingestion\Domain\ValueObject\NetForecastQuantities;
use App\Ingestion\Domain\ValueObject\NetQualityExact;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetDayRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetRequest;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetSourceTrace;

/** Only immutable B15 bytes and their scoped publication anchors; never source queries. */
final readonly class NetQualityInputReader
{
    public function __construct(private NetForwardArtifactReader $reader, private NetForwardInputCodec $inputs)
    {
    }

    /** @return \Generator<int, NetQualityObservation> */
    public function observations(string $companyId, string $accountId, NetQualityArtifactRef $ref, NetQualityCaptureContext $context, NetQualityBudget $budget, NetQualitySelectionDiagnostics $stats): \Generator
    {
        if (NetForwardArtifactKind::Input !== $ref->kind) {
            throw new \DomainException('Expected a sealed input.');
        }
        $protocol = $context->protocol;
        $artifact = $this->reader->read($companyId, $accountId, $ref->kind, $ref->requestId, $ref->sha256, deadline: $budget->deadline, budget: $budget);
        $metadata = $artifact->manifest['metadata'];
        if (!\is_array($metadata) || ($metadata['model'] ?? null) !== NetQualityProtocol::PARAMETERS['model'] || ($metadata['horizonDays'] ?? null) !== 60 || ($metadata['reportingLagDays'] ?? null) !== 7 || !\is_array($metadata['qualityProtocol'] ?? null)) {
            throw new \UnexpectedValueException('Unsupported or unregistered forward input.');
        }
        $binding = $metadata['qualityProtocol'];
        if (!\is_array($binding['protocol'] ?? null) || NetQualityArtifactRef::fromArray($binding['protocol'])->toArray() !== $context->protocolRef->toArray() || ($binding['frozenAtUtc'] ?? null) !== $context->frozenAt->format('Y-m-d\TH:i:s.u\Z') || !\is_string($metadata['softwareRelease'] ?? null) || !str_ends_with($metadata['softwareRelease'], substr(NetQualitySchema::string($protocol->data, 'codeCommit'), 0, 7))) {
            throw new \UnexpectedValueException('Input protocol, freeze, or code pin mismatch.');
        }
        $request = new NetForwardCaptureRequest($ref->requestId, $protocol->planId, $protocol->from, $protocol->to, qualityProtocolId: $context->protocolRef->requestId, qualityProtocolHash: $context->protocolRef->sha256);
        if ($request->hash() !== $artifact->publication->requestHash) {
            throw new \UnexpectedValueException('Input request hash does not bind the protocol and scope.');
        }
        $training = null;
        $plan = null;
        $targetRequest = null;
        $summary = null;
        $baseline = null;
        $targetMetadata = null;
        $groups = $targets = $identities = $models = $simple = $policies = $days = [];
        $evidence = 0;
        foreach ($this->reader->segments($companyId, $accountId, $artifact, $budget->deadline, $budget) as $segment) {
            foreach ($segment['rows'] as $row) {
                $budget->guard();
                switch ($segment['type']) {
                    case 'training':
                        if (null !== $training) {
                            throw new \UnexpectedValueException('Duplicate training frame.');
                        }
                        $training = $this->inputs->unpack($row['value'] ?? null);
                        if (!$training instanceof NetTrainingBatch || $training->companyId !== $companyId || $training->accountId !== $accountId || $training->readAt->format('U.u') !== $artifact->readAt->format('U.u')) {
                            throw new \UnexpectedValueException('Training scope or actual readAt mismatch.');
                        }
                        if (null !== $training->context && ($training->context->epoch->id !== ($protocol->data['epochId'] ?? null) || $training->context->epoch->start->format('Y-m-d\TH:i:s.u\Z') !== ($protocol->data['epochStartUtc'] ?? null) || $training->context->snapshotId !== ($metadata['snapshot'] ?? null))) {
                            throw new \UnexpectedValueException('Training epoch or RR membership frame mismatch.');
                        }
                        break;
                    case 'target_request':
                        if (null !== $targetRequest) {
                            throw new \UnexpectedValueException('Duplicate target request.');
                        }
                        $targetRequest = $this->inputs->unpack($row['value'] ?? null);
                        if (!$targetRequest instanceof NetTargetRequest || $targetRequest->from->format('Y-m-d') !== $protocol->from || $targetRequest->to->format('Y-m-d') !== $protocol->to) {
                            throw new \UnexpectedValueException('Target request scope mismatch.');
                        }
                        break;
                    case 'plan':
                        if (null !== $plan) {
                            throw new \UnexpectedValueException('Duplicate plan frame.');
                        }
                        $p = NetQualitySealedValues::fields($row['value'] ?? null, NetForwardPlanContext::class);
                        $plan = new NetForwardPlanContext(NetQualitySchema::string($p, 'planId'), NetQualitySchema::integer($p, 'planVersion'), NetQualitySchema::integer($p, 'versionNumber'), NetQualitySchema::string($p, 'baselineId'), NetQualitySchema::integer($p, 'baselineVersion'), NetQualitySchema::string($p, 'metric'), NetQualitySchema::string($p, 'currency'), NetQualitySchema::string($p, 'accumulationFrom'), NetQualitySchema::strings($p['marketplaceSkus'] ?? null, 50), NetQualitySchema::rows($p['goalRows'] ?? null, 50));
                        $context->assertPlan($companyId, $accountId, $request, $plan, $artifact->readAt);
                        break;
                    case 'target_metadata':
                        if (null !== $targetMetadata) {
                            throw new \UnexpectedValueException('Duplicate target metadata.');
                        }
                        $targetMetadata = $row;
                        break;
                    case 'target_day':
                        $day = $this->inputs->unpack($row['value'] ?? null);
                        $sku = NetQualitySchema::string($row, 'sku');
                        if (!$day instanceof NetTargetDayRow || !\in_array($sku, $protocol->skus, true)) {
                            throw new \UnexpectedValueException('Target day or SKU mismatch.');
                        }
                        $dayKey = json_encode([$sku, $day->businessDate], \JSON_THROW_ON_ERROR);
                        if (isset($days[$dayKey])) {
                            throw new \UnexpectedValueException('Duplicate dense target day.');
                        }
                        $days[$dayKey] = true;
                        foreach ($day->groups as $group) {
                            if ($group->marketplaceSku !== $sku || $group->businessDate !== $day->businessDate) {
                                throw new \UnexpectedValueException('Target group crosses its day or SKU.');
                            }
                            $case = $this->caseKey($companyId, $accountId, $group->orderNumber, $sku);
                            if (null !== $group->orderNumber) {
                                $stats->observeOrder(hash('sha256', json_encode([$companyId, $accountId, $group->orderNumber], \JSON_THROW_ON_ERROR)), $group->businessDate);
                            }
                            $eligible = null !== $group->orderNumber && '' !== trim($group->orderNumber) && $group->businessDate >= NetQualitySchema::string($protocol->data, 'holdoutFrom') && $group->businessDate <= NetQualitySchema::string($protocol->data, 'holdoutTo');
                            foreach ($group->parts as $part) {
                                $key = 's:'.$part->sourceRowId;
                                if (isset($identities[$key]) || \count($identities) >= 50000) {
                                    throw new \UnexpectedValueException('Duplicate or oversized target source population.');
                                }
                                $identities[$key] = $case.'|'.$group->businessDate;
                                $value = NetQualityTargetPart::fromSource($part, $artifact->readAt);
                                if ($eligible) {
                                    $targets[$key] = $value;
                                    $groups[$case] ??= ['order' => $group->orderNumber, 'sku' => $sku, 'day' => $group->businessDate, 'parts' => []];
                                    $groups[$case]['parts'][] = $key;
                                }
                            }
                            if (!$eligible) {
                                $stats->add(null === $group->orderNumber ? 'ineligible_order_identity' : 'outside_registered_holdout');
                            }
                        }
                        break;
                    case 'forecast_day':
                        $d = NetQualitySealedValues::fields($row['value'] ?? null, NetForecastDay::class);
                        foreach (NetQualitySchema::rows($d['groups'] ?? null, 200) as $rawGroup) {
                            $g = NetQualitySealedValues::fields($rawGroup, NetForecastGroup::class);
                            $order = $g['orderNumber'] ?? null;
                            if (null !== $order && !\is_string($order)) {
                                throw new \UnexpectedValueException('Invalid forecast order identity.');
                            }
                            $sku = NetQualitySchema::string($g, 'marketplaceSku');
                            $identity = $this->caseKey($companyId, $accountId, $order, $sku).'|'.NetQualitySchema::string($g, 'businessDate');
                            foreach (NetQualitySchema::rows($g['parts'] ?? null, 128) as $rawPart) {
                                $p = NetQualitySealedValues::fields($rawPart, NetForecastPart::class);
                                $key = 's:'.NetQualitySchema::string($p, 'sourceRowId');
                                if (($identities[$key] ?? null) !== $identity || isset($models[$key])) {
                                    throw new \UnexpectedValueException('Forecast source does not match its target.');
                                }
                                if (isset($targets[$key])) {
                                    $q = NetQualitySealedValues::quantity($p['quantities'] ?? null);
                                    $trace = $this->inputs->unpack($p['trace'] ?? null);
                                    $target = $targets[$key];
                                    if (!$trace instanceof NetTargetSourceTrace || $trace->sourceRowId !== $target->sourceRowId || $trace->amountMinor !== $target->price || $trace->currency !== $target->currency || $trace->firstRevisionId !== $target->firstRevisionId || $trace->latestRevisionId !== $target->latestRevisionId || (null !== $q && (!$target->inputComplete || null === $target->currentD || null === $target->openPre || null === $target->openPost || $q->currentD !== (string) $target->currentD || $q->eligibleOpenQuantity !== (string) ($target->openPre + $target->openPost)))) {
                                        throw new \UnexpectedValueException('Forecast source values or quantity bounds mismatch.');
                                    }
                                    $models[$key] = ['quantity' => $q, 'pre' => $this->probability($p['openPre'] ?? null), 'post' => $this->probability($p['openPost'] ?? null), 'reason' => $p['unavailableReason'] ?? null];
                                }
                            }
                        }
                        break;
                    case 'forecast_summary':
                        if (null !== $summary) {
                            throw new \UnexpectedValueException('Duplicate forecast summary.');
                        }
                        $summary = $row;
                        break;
                    case 'simple_baseline_part':
                        $key = 's:'.NetQualitySchema::string($row, 'sourceRowId');
                        if (!isset($identities[$key]) || isset($simple[$key]) || ($row['version'] ?? null) !== NetQualityProtocol::PARAMETERS['baseline']) {
                            throw new \UnexpectedValueException('Baseline identity or version mismatch.');
                        }
                        if (isset($targets[$key])) {
                            $sku = NetQualitySchema::string($row, 'sku');
                            $case = $this->caseKey($companyId, $accountId, \is_string($row['orderNumber'] ?? null) ? $row['orderNumber'] : null, $sku);
                            if ($identities[$key] !== $case.'|'.NetQualitySchema::string($row, 'day') || ($row['unitPriceMinor'] ?? null) !== $targets[$key]->price || ($row['currency'] ?? null) !== $targets[$key]->currency) {
                                throw new \UnexpectedValueException('Baseline target or fixed price mismatch.');
                            }
                            $policy = ['selected' => $row['selected'] ?? null, 'attempts' => $row['attempts'] ?? null];
                            $codec = new NetForwardManifestCodec();
                            $policyKey = 'sku:'.$sku;
                            if (isset($policies[$policyKey]) && $codec->encode($policies[$policyKey]) !== $codec->encode($policy)) {
                                throw new \UnexpectedValueException('Baseline scope varies within one SKU capture.');
                            }
                            $policies[$policyKey] = $policy;
                            $simple[$key] = ['quantity' => NetQualitySealedValues::quantity($row['quantities'] ?? null), 'reason' => $row['reason'] ?? null];
                        }
                        break;
                    case 'simple_baseline':
                        if (null !== $baseline || ($row['version'] ?? null) !== NetQualityProtocol::PARAMETERS['baseline']) {
                            throw new \UnexpectedValueException('Missing independent baseline version.');
                        }
                        $baseline = $row;
                        break;
                    case 'evidence':
                        ++$evidence;
                        $this->evidence($row, $artifact->readAt, $companyId, $accountId, $groups, $stats);
                        break;
                    case 'legacy_baseline':
                        $stats->add('legacy_target_not_comparable');
                        break;
                }
            }
        }
        if (!$training instanceof NetTrainingBatch || null === $plan || !$targetRequest instanceof NetTargetRequest || null === $summary || null === $baseline || null === $targetMetadata || (null !== $training->context && 0 === $evidence)) {
            throw new \UnexpectedValueException('Incomplete immutable input frame.');
        }
        $stats->add('verified_input');
        if ([] === $groups) {
            $stats->add('input_without_eligible_cases');
        }
        $replay = new NetQualityBaselineReplay();
        $rates = NetQualitySchema::rows($baseline['rates'] ?? [], 51);
        foreach ($policies as $policyKey => $policy) {
            if ((new NetForwardManifestCodec())->encode($policy) !== (new NetForwardManifestCodec())->encode($replay->policy($rates, substr($policyKey, 4)))) {
                throw new \UnexpectedValueException('Baseline selected scope does not replay from its sealed mature sample.');
            }
        }
        foreach ($groups as $group) {
            $budget->guard();
            $masks = [];
            $orderGroup = NetQualityObservation::orderGroup($group['day'], $artifact->readAt);
            foreach ($group['parts'] as $key) {
                $target = $targets[$key];
                if (null !== $orderGroup) {
                    $masks[$orderGroup][] = $key;
                }
                if (($target->currentD ?? 0) > 0 && null !== $target->origin) {
                    $delivery = NetQualityObservation::deliveryGroup($target->origin, $artifact->readAt);
                    if (null !== $delivery) {
                        $masks[$delivery][] = $key;
                    }
                } elseif (($target->currentD ?? 0) > 0) {
                    $stats->add('delivery_origin_unknown');
                }
            }
            foreach ($masks as $name => $keys) {
                $parts = [];
                $components = [];
                foreach ($keys as $key) {
                    $target = $targets[$key];
                    $model = $models[$key] ?? null;
                    $base = $simple[$key] ?? null;
                    if (null === $base || (null === $model && null === ($summary['reason'] ?? null))) {
                        throw new \UnexpectedValueException('A target component is missing from a healthy forecast or baseline.');
                    }
                    $policy = $replay->policy($rates, $group['sku']);
                    $expected = $replay->quantity($target, $model['pre'] ?? null, $model['post'] ?? null, $policy['selected'], $artifact->readAt);
                    if ($this->fraction($expected, false) !== $this->fraction($base['quantity'], false)) {
                        throw new \UnexpectedValueException('Independent baseline arithmetic does not replay.');
                    }
                    $delivered = str_starts_with($name, 'delivery');
                    $modelValue = $this->fraction($model['quantity'] ?? null, $delivered);
                    $baseValue = $this->fraction($base['quantity'], $delivered);
                    $components[] = $target->sourceRowId;
                    $parts[] = ['sourceRowId' => $target->sourceRowId, 'rawQuantity' => $target->rawQuantity, 'model' => $modelValue, 'simple' => $baseValue, 'modelReason' => $model['reason'] ?? $summary['reason'] ?? null, 'simpleReason' => $base['reason'], 'unitPriceMinor' => $target->price, 'currency' => $target->currency, 'truthScope' => $delivered && (($target->openPre ?? 0) + ($target->openPost ?? 0)) > 0 ? 'mixed_component_unavailable' : 'part'];
                }
                yield NetQualityObservation::fromArray(['companyId' => $companyId, 'accountId' => $accountId, 'orderNumber' => $group['order'], 'sku' => $group['sku'], 'businessDate' => $group['day'], 'readAtUtc' => $artifact->readAt->format('Y-m-d\TH:i:s.u\Z'), 'input' => $ref->toArray(), 'group' => $name, 'components' => $components, 'payload' => ['parts' => $parts]]);
            }
        }
    }

    /** @return array{numerator: string, denominator: string}|null */
    private function fraction(?NetForecastQuantities $quantity, bool $delivered): ?array
    {
        if (null === $quantity) {
            return null;
        }

        return $delivered ? NetQualityExact::fraction($quantity->currentD)->minus(NetQualityExact::fraction($quantity->futureReturnsNumerator, $quantity->futureReturnsDenominator))->toArray() : NetQualityExact::fraction($quantity->expectedNetNumerator, $quantity->expectedNetDenominator)->toArray();
    }

    /** @return array{numerator: string, denominator: string}|null */
    private function probability(mixed $value): ?array
    {
        $fields = NetQualitySealedValues::fields($value, NetForecastComponent::class);
        $n = $fields['probabilityNumerator'] ?? null;
        $d = $fields['probabilityDenominator'] ?? null;
        if (null === $n && null === $d) {
            return null;
        }
        if (!\is_string($n) || !\is_string($d)) {
            throw new \UnexpectedValueException('Invalid saved gross probability.');
        }
        $rate = NetQualityExact::fraction($n, $d);
        if ($rate->isNegative() || $rate->compare(NetQualityExact::fraction('1')) > 0) {
            throw new \UnexpectedValueException('Saved gross probability is outside zero..one.');
        }

        return $rate->toArray();
    }

    private function caseKey(string $company, string $account, ?string $order, string $sku): string
    {
        return hash('sha256', json_encode([$company, $account, $order, $sku], \JSON_THROW_ON_ERROR));
    }

    /** @param array<array-key, mixed> $row
     * @param array<string, array{order: ?string, sku: string, day: string, parts: list<string>}> $groups */
    private function evidence(array $row, \DateTimeImmutable $at, string $companyId, string $accountId, array $groups, NetQualitySelectionDiagnostics $stats): void
    {
        $values = $row['values'] ?? null;
        if (!\is_array($values)) {
            throw new \UnexpectedValueException('Missing actual source membership evidence.');
        }
        foreach (['captured_at', 'observed_at', 'marked_at'] as $key) {
            if (\is_string($values[$key] ?? null) && new \DateTimeImmutable($values[$key], new \DateTimeZone('UTC')) > $at) {
                throw new \UnexpectedValueException('A future observation leaked into a sealed input.');
            }
        }
        if ('returns' === ($row['population'] ?? null) && 'return_groups' === ($row['relation'] ?? null) && true === ($values['eligible'] ?? null)) {
            if (!\is_string($values['origin'] ?? null) || (new \DateTimeImmutable($values['origin'], new \DateTimeZone('UTC')))->modify('+67 days') >= $at) {
                throw new \UnexpectedValueException('holdout_target_leakage');
            }
            if (\is_string($values['order_number'] ?? null) && \is_string($values['marketplace_sku'] ?? null) && isset($groups[$this->caseKey($companyId, $accountId, $values['order_number'], $values['marketplace_sku'])])) {
                $stats->add('known_on_t_mature_training_overlap');
            }
        }
    }
}
