<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\NetForwardArtifactKind;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Private replay of frozen artifacts only. A completed report never opens rollout. */
final readonly class EvaluateNetForecastQualityAction
{
    public function __construct(private NetForwardArtifactReader $reader, private NetQualityProtocolReader $protocols, private NetQualityInventoryReader $inventories, private NetQualityTruthReader $truths, private NetQualityArtifactWriter $writer, private NetQualityMetrics $metrics, private NetQualityDecision $decisions, private NetForwardManifestCodec $codec, private NetQualityReportCodec $reports, #[Autowire('%app.version%')] private string $softwareRelease)
    {
    }

    public function __invoke(string $companyId, string $accountId, NetQualityArtifactRef $selectionRef, NetQualityTruthInventory $truthInventory, string $evaluationId, ?NetQualityBudget $budget = null): NetQualityEvaluationResult
    {
        NetQualitySchema::uuid($evaluationId, true);
        if (NetForwardArtifactKind::QualitySelection !== $selectionRef->kind) {
            throw new \InvalidArgumentException('Expected quality selection.');
        }
        $budget ??= new NetQualityBudget();
        $this->reader->assertNoTransaction();
        $inventory = $this->inventories->read($companyId, $accountId, $truthInventory->reference, $budget);
        if (!$inventory instanceof NetQualityTruthInventory) {
            throw new \DomainException('Evaluation requires the sealed final truth inventory.');
        }
        $context = $this->protocols->context($companyId, $accountId, $inventory->protocolRef, $budget);
        $selection = $this->reader->read($companyId, $accountId, $selectionRef->kind, $selectionRef->requestId, $selectionRef->sha256, deadline: $budget->deadline, budget: $budget);
        $metadata = $selection->manifest['metadata'];
        if (!\is_array($metadata) || !\is_array($metadata['protocol'] ?? null) || NetQualityArtifactRef::fromArray($metadata['protocol'])->toArray() !== $context->protocolRef->toArray() || $selection->readAt > $inventory->sealedAt || $inventory->cutoff->format('Y-m-d\TH:i:s.u\Z') !== $context->protocol->data['truthCutoffUtc']) {
            throw new \UnexpectedValueException('Selection and final truth inventory do not share the prospective protocol.');
        }
        $requestHash = hash('sha256', $this->codec->encode(['policy' => 'net_quality_evaluation_v1', 'protocol' => $context->protocolRef->toArray(), 'selection' => $selectionRef->toArray(), 'truthInventory' => $inventory->reference->toArray(), 'softwareRelease' => $this->softwareRelease]));
        $observations = new NetQualitySelectionSpool($budget);
        $byInput = [];
        $seen = [];
        $summary = null;
        foreach ($this->reader->segments($companyId, $accountId, $selection, $budget->deadline, $budget) as $segment) {
            foreach ($segment['rows'] as $row) {
                if ('selection_summary' === $segment['type'] && null === $summary) {
                    $summary = $row;
                    continue;
                }
                if ('quality_selection' !== $segment['type'] || !\is_array($row['value'] ?? null) || null !== $summary) {
                    throw new \UnexpectedValueException('Invalid sealed selection population.');
                }
                $observation = NetQualityObservation::fromArray(NetQualitySchema::rows([$row['value']], 1)[0]);
                $key = $observation->caseKey().':'.$observation->group;
                if (isset($seen[$key]) || $observation->companyId !== $companyId || $observation->accountId !== $accountId || \count($seen) >= 40000) {
                    throw new \UnexpectedValueException('Duplicate or foreign selected case.');
                }
                $seen[$key] = true;
                // Overall aliases are not additional source candidates.
                if ('overall' !== $observation->group) {
                    $budget->candidate();
                }
                $byInput[$observation->inputRef->requestId][] = $observations->append($observation);
            }
        }
        if (null === $summary) {
            throw new \UnexpectedValueException('Missing sealed selection summary.');
        }
        $this->reader->prefetch($companyId, $accountId, NetForwardArtifactKind::Input, array_keys($byInput), $budget);
        $truthIds = [];
        $truthSlots = [];
        $violations = [];
        $truthSlotCounts = [];
        foreach ($inventory->slots as $slot) {
            $status = NetQualitySchema::string($slot, 'status');
            $truthSlotCounts[$status] = ($truthSlotCounts[$status] ?? 0) + 1;
            $inputId = NetQualitySchema::string($slot, 'inputId');
            $truthSlots[$inputId][] = $slot;
            if (isset($byInput[$inputId]) && 'success' === ($slot['status'] ?? null)) {
                $truthIds[] = NetQualitySchema::string($slot, 'requestId');
            } elseif (isset($byInput[$inputId])) {
                $violations[] = 'selected_input_truth_slot_'.NetQualitySchema::string($slot, 'status');
            }
        }
        $this->reader->prefetch($companyId, $accountId, NetForwardArtifactKind::Truth, $truthIds, $budget);
        $dataset = new NetForwardSpool($this->codec, $budget->deadline, $budget);
        $scorer = new NetQualityCaseScorer();
        $accumulators = [];
        foreach (['overall', ...NetQualityProtocol::PARAMETERS['ageGroups']] as $group) {
            $accumulators[$group] = new NetQualityGroupAccumulator($this->metrics->accumulator());
        }
        foreach ($byInput as $inputId => $positions) {
            $first = $observations->read($positions[0][0], $positions[0][1]);
            $frames = [];
            foreach ($truthSlots[$inputId] ?? [] as $slot) {
                if ('success' !== ($slot['status'] ?? null)) {
                    continue;
                }
                if (!\is_array($slot['artifact'] ?? null)) {
                    throw new \UnexpectedValueException('Successful truth slot is incomplete.');
                }
                $frames[] = $this->truths->read($companyId, $accountId, NetQualityArtifactRef::fromArray($slot['artifact']), $first->inputRef, $context, $budget);
            }
            if (!isset($truthSlots[$inputId])) {
                throw new \UnexpectedValueException('Selected input has no registered truth slots.');
            }
            foreach ($positions as [$offset, $length]) {
                $observation = $observations->read($offset, $length);
                if ($observation->inputRef->toArray() !== $first->inputRef->toArray()) {
                    throw new \UnexpectedValueException('Selection substituted an input hash.');
                }
                $outcome = $this->truths->choose($observation, $frames);
                if ([] !== $outcome->invalidations) {
                    $violations[] = 'selected_truth_invalidated';
                }
                $row = $scorer->score($observation, $outcome);
                $dataset->append('quality_dataset', $row);
                $accumulators[$observation->group]->add($row);
            }
            unset($frames);
        }
        unset($observations, $byInput, $seen);
        $groups = [];
        $counts = [];
        foreach (['overall', ...NetQualityProtocol::PARAMETERS['ageGroups']] as $group) {
            $groups[$group] = $accumulators[$group]->result();
            $counts[$group] = $groups[$group]->metrics->count;
        }
        $decision = $this->decisions->decide(new NetQualityDecisionInput($groups, $context->protocol->currencies, array_values(array_unique($violations))));
        $datasetRef = $this->writer->publishStaged($companyId, $accountId, new NetQualityArtifactDocument(NetForwardArtifactKind::QualityDataset, $evaluationId, $requestHash, $selectionRef, ['schema' => 'net_quality_dataset_v1', 'protocol' => $context->protocolRef->toArray(), 'truthInventory' => $inventory->reference->toArray(), 'softwareRelease' => $this->softwareRelease], []), $dataset, $budget);
        $report = ['schema' => 'net_quality_report_v1', 'protocol' => $context->protocolRef->toArray(), 'selection' => $selectionRef->toArray(), 'truthInventory' => $inventory->reference->toArray(), 'dataset' => $datasetRef->toArray(), 'softwareRelease' => $this->softwareRelease, 'parameters' => $context->protocol->data['parameters'], 'counts' => $counts, 'groups' => array_map(static fn (NetQualityGroupResult $result): array => $result->toArray(), $groups), 'selectionSummary' => $summary, 'truthSlotCounts' => $truthSlotCounts, 'legacyComparison' => ['status' => 'target_not_comparable', 'reason' => 'legacy_buyout_and_sales_cohort_are_aggregate_gross_targets_without_order_sku_retained_component_masks'], 'resources' => $budget->summary(), 'readByteAccounting' => 'verified_sizes_including_put_readback_with_failed_get_upper_bounds', 'mechanicalValidation' => 'not_run', 'performanceValidation' => 'not_run', 'B17' => 'OPEN', 'B18' => 'OPEN', ...$decision->toArray()];
        $reportRef = $this->writer->publish($companyId, $accountId, new NetQualityArtifactDocument(NetForwardArtifactKind::QualityReport, $evaluationId, $requestHash, $datasetRef, ['schema' => 'net_quality_report_v1', 'protocol' => $context->protocolRef->toArray(), 'softwareRelease' => $this->softwareRelease], [['type' => 'quality_report', 'row' => ['value' => $report]]]), $budget);
        [, $winner] = (new NetQualityArtifactPayloadReader($this->reader))->read($companyId, $accountId, $reportRef, 'quality_report', $budget);

        return $this->reports->result($winner, $reportRef, $datasetRef);
    }
}
