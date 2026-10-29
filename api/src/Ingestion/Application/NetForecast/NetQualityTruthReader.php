<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\NetForwardArtifactKind;
use App\Ingestion\Domain\ValueObject\NetForecastCaptureEpoch;
use App\Ingestion\Domain\ValueObject\NetForwardTruthResult;
use App\Ingestion\Domain\ValueObject\NetQualityExact;

final readonly class NetQualityTruthReader
{
    public function __construct(private NetForwardArtifactReader $reader, private NetForwardInputCodec $inputs)
    {
    }

    public function read(string $companyId, string $accountId, NetQualityArtifactRef $ref, NetQualityArtifactRef $input, NetQualityCaptureContext $context, NetQualityBudget $budget): NetQualityTruthFrame
    {
        if (NetForwardArtifactKind::Truth !== $ref->kind || NetForwardArtifactKind::Input !== $input->kind) {
            throw new \DomainException('Truth requires a truth reference and its exact input.');
        }
        $artifact = $this->reader->read($companyId, $accountId, $ref->kind, $ref->requestId, $ref->sha256, deadline: $budget->deadline, budget: $budget);
        $parent = $artifact->manifest['parent'];
        $metadata = $artifact->manifest['metadata'];
        if (!\is_array($parent) || ($parent['requestId'] ?? null) !== $input->requestId || ($parent['artifactHash'] ?? null) !== $input->sha256 || !\is_array($metadata) || ($metadata['policy'] ?? null) !== 'component_truth_h60_l7_v1' || ($metadata['horizonDays'] ?? null) !== 60 || ($metadata['reportingLagDays'] ?? null) !== 7 || !\is_string($metadata['softwareRelease'] ?? null) || \in_array($metadata['softwareRelease'], ['', 'unversioned'], true)) {
            throw new \UnexpectedValueException('Truth parent, policy, or producer version mismatch.');
        }
        $binding = $metadata['qualityProtocol'] ?? null;
        if (!\is_array($binding) || !\is_array($binding['protocol'] ?? null) || NetQualityArtifactRef::fromArray($binding['protocol'])->toArray() !== $context->protocolRef->toArray()) {
            throw new \UnexpectedValueException('Truth has no matching protocol binding.');
        }
        $epoch = $this->inputs->unpack($metadata['epoch'] ?? null);
        if (null !== $epoch && (!$epoch instanceof NetForecastCaptureEpoch || $epoch->id !== ($context->protocol->data['epochId'] ?? null))) {
            throw new \UnexpectedValueException('Truth crossed the registered capture epoch.');
        }
        $parts = [];
        $summary = false;
        $evidence = 0;
        foreach ($this->reader->segments($companyId, $accountId, $artifact, $budget->deadline, $budget) as $segment) {
            foreach ($segment['rows'] as $row) {
                $budget->guard();
                if ('truth_part' === $segment['type']) {
                    $id = NetQualitySchema::string($row, 'sourceRowId');
                    $key = 's:'.$id;
                    if (isset($parts[$key]) || \count($parts) >= 50000) {
                        throw new \UnexpectedValueException('Duplicate or oversized truth component population.');
                    }
                    $result = $this->inputs->unpack($row['result'] ?? null);
                    if (!$result instanceof NetForwardTruthResult || ('ready' === $result->state && (null === $epoch || null !== $result->reason || null === $result->readyAt || $result->readyAt > $artifact->readAt))) {
                        throw new \UnexpectedValueException('Truth component claims readiness without its evidence horizon.');
                    }
                    $parts[$key] = ['orderNumber' => NetQualitySchema::nullableString($row['orderNumber'] ?? null), 'sku' => NetQualitySchema::string($row, 'sku'), 'day' => NetQualitySchema::date(NetQualitySchema::string($row, 'day')), 'quantity' => $result->quantity, 'state' => $result->state, 'reason' => $result->reason, 'readyAtUtc' => $result->readyAt?->format('Y-m-d\TH:i:s.u\Z'), 'unitPriceMinor' => NetQualitySchema::nullableString($row['unitPriceMinor'] ?? null), 'currency' => NetQualitySchema::nullableString($row['currency'] ?? null)];
                } elseif ('truth_summary' === $segment['type']) {
                    if ($summary || !\in_array($row['state'] ?? null, ['ready', 'pending'], true)) {
                        throw new \UnexpectedValueException('Invalid truth summary.');
                    }
                    $summary = true;
                } elseif ('truth_evidence' === $segment['type']) {
                    ++$evidence;
                    $values = $row['values'] ?? null;
                    if (!\is_array($values)) {
                        throw new \UnexpectedValueException('Truth source evidence missing.');
                    }
                    foreach (['captured_at', 'marked_at', 'observed_at'] as $field) {
                        if (\is_string($values[$field] ?? null) && new \DateTimeImmutable($values[$field], new \DateTimeZone('UTC')) > $artifact->readAt) {
                            throw new \UnexpectedValueException('Truth contains an observation after its winning readAt.');
                        }
                    }
                } else {
                    throw new \UnexpectedValueException('Unknown truth segment type.');
                }
            }
        }
        if (!$summary || (null !== $epoch && 0 === $evidence)) {
            throw new \UnexpectedValueException('Incomplete truth evidence.');
        }

        return new NetQualityTruthFrame($ref, $input, $artifact->readAt, $parts, $metadata['softwareRelease']);
    }

    /** @param list<NetQualityTruthFrame> $frames */
    public function choose(NetQualityObservation $observation, array $frames): NetQualityTruthOutcome
    {
        usort($frames, static fn (NetQualityTruthFrame $a, NetQualityTruthFrame $b): int => [$a->readAt->format('Y-m-d\TH:i:s.u\Z'), $a->reference->requestId] <=> [$b->readAt->format('Y-m-d\TH:i:s.u\Z'), $b->reference->requestId]);
        $first = null;
        $pending = null;
        $ready = null;
        $invalidations = [];
        foreach ($frames as $frame) {
            if ($frame->inputRef->toArray() !== $observation->inputRef->toArray() || $frame->readAt < NetQualitySchema::utc($observation->readAtUtc)) {
                throw new \UnexpectedValueException('Truth cannot precede or replace its selected input.');
            }
            $sum = NetQualityExact::fraction('0');
            $quantities = [];
            $reasons = [];
            $state = 'ready';
            $censored = false;
            $seen = [];
            foreach (NetQualitySchema::rows($observation->payload['parts'] ?? null, 128) as $part) {
                $id = NetQualitySchema::string($part, 'sourceRowId');
                $key = 's:'.$id;
                $truth = $frame->parts[$key] ?? null;
                if (isset($seen[$key]) || !\in_array($id, $observation->components, true) || null === $truth || $truth['orderNumber'] !== $observation->orderNumber || $truth['sku'] !== $observation->sku || $truth['day'] !== $observation->businessDate || $truth['unitPriceMinor'] !== ($part['unitPriceMinor'] ?? null) || $truth['currency'] !== ($part['currency'] ?? null)) {
                    throw new \UnexpectedValueException('Truth components or input price/mask do not match selection.');
                }
                $seen[$key] = true;
                if ((null !== $ready && 'ready' !== $truth['state']) || 'source_correction' === $truth['reason']) {
                    $invalidations[] = ['truth' => $frame->reference->toArray(), 'sourceRowId' => $id, 'reason' => $truth['reason'] ?? 'selected_truth_invalidated'];
                }
                if (($part['truthScope'] ?? null) !== 'part') {
                    $state = 'ineligible';
                    $reasons[] = 'scope_not_comparable';
                } elseif ('ready' !== $truth['state']) {
                    $state = 'ineligible' === $truth['state'] || 'ineligible' === $state ? 'ineligible' : 'pending';
                    $reasons[] = $truth['reason'] ?? 'components_not_ready';
                    $censored = $censored || null === $truth['readyAtUtc'];
                } else {
                    $units = $truth['quantity'];
                    if (null === $units || $units < 0 || $units > NetQualitySchema::integer($part, 'rawQuantity')) {
                        throw new \UnexpectedValueException('Truth exceeds the selected ordered units.');
                    }
                    $quantities[$key] = $units;
                    $sum = $sum->plus(NetQualityExact::fraction((string) $units));
                }
            }
            if (\count($seen) !== \count($observation->components)) {
                throw new \UnexpectedValueException('Incomplete selected component mask.');
            }
            $outcome = new NetQualityTruthOutcome($state, 'ready' === $state ? $sum : null, $frame->reference, 'ready' === $state ? $quantities : [], array_values(array_unique($reasons)), $censored);
            if ('ready' === $state) {
                if (null !== $ready && $ready->quantities !== $outcome->quantities) {
                    $invalidations[] = ['truth' => $frame->reference->toArray(), 'reason' => 'ready_truth_changed'];
                }
                $ready ??= $outcome;
            }
            $first ??= $outcome;
            if ('pending' === $state) {
                $pending ??= $outcome;
            }
        }

        $chosen = $ready ?? $pending ?? $first ?? new NetQualityTruthOutcome('pending', null, null, [], ['truth_not_captured'], true);

        return new NetQualityTruthOutcome($chosen->state, $chosen->quantity, $chosen->truthRef, $chosen->quantities, $chosen->reasons, $chosen->censored, $invalidations);
    }
}
