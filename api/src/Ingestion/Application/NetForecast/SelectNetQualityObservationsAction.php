<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\NetForwardArtifactKind;

final readonly class SelectNetQualityObservationsAction
{
    public function __construct(private NetForwardArtifactReader $reader, private NetQualityInventoryReader $inventories, private NetQualityProtocolReader $protocols, private NetQualityInputReader $inputs, private NetQualitySelection $selector, private NetQualityArtifactWriter $writer)
    {
    }

    public function __invoke(string $companyId, string $accountId, NetQualitySelectionRequest $request): NetQualityArtifactRef
    {
        $budget = new NetQualityBudget();
        $this->reader->assertNoTransaction();
        $context = $this->protocols->context($companyId, $accountId, $request->protocol, $budget);
        $inventory = $this->inventories->read($companyId, $accountId, $request->inventory, $budget);
        if (!$inventory instanceof NetQualityCaptureInventory || $inventory->protocolRef->toArray() !== $request->protocol->toArray()) {
            throw new \DomainException('Selection needs a capture inventory from the same protocol.');
        }
        $stats = new NetQualitySelectionDiagnostics();
        $slots = $inventory->slots;
        $ids = [];
        foreach ($slots as $slot) {
            if ('success' === ($slot['status'] ?? null)) {
                $ids[] = NetQualitySchema::string($slot, 'requestId');
            }
        }
        $this->reader->prefetch($companyId, $accountId, NetForwardArtifactKind::Input, $ids, $budget);
        usort($slots, static fn (array $a, array $b): int => [$a['actualReadAtUtc'] ?? '', $a['requestId']] <=> [$b['actualReadAtUtc'] ?? '', $b['requestId']]);
        $observations = function () use ($companyId, $accountId, $slots, $context, $budget, $stats): \Generator {
            foreach ($slots as $slot) {
                $status = NetQualitySchema::string($slot, 'status');
                $stats->add('input_slot_'.$status);
                if ('success' === $status) {
                    if (!\is_array($slot['artifact'] ?? null)) {
                        throw new \UnexpectedValueException('Successful input slot has no artifact.');
                    }
                    yield from $this->inputs->observations($companyId, $accountId, NetQualityArtifactRef::fromArray($slot['artifact']), $context, $budget, $stats);
                }
            }
        };
        $segments = function () use ($observations, $budget, $stats): \Generator {
            $counts = [];
            foreach ($this->selector->select($observations(), $budget) as $observation) {
                $counts[$observation->group] = ($counts[$observation->group] ?? 0) + 1;
                yield ['type' => 'quality_selection', 'row' => ['value' => $observation->toArray()]];
            }
            ksort($counts, \SORT_STRING);
            yield ['type' => 'selection_summary', 'row' => ['counts' => $counts, 'candidateObservations' => $budget->summary()['candidates'], 'diagnostics' => $stats->toArray()]];
        };
        $requestHash = hash('sha256', (new NetForwardManifestCodec())->encode(['policy' => 'net_quality_selection_v1', 'value' => $request->toArray()]));

        return $this->writer->publish($companyId, $accountId, new NetQualityArtifactDocument(NetForwardArtifactKind::QualitySelection, $request->requestId, $requestHash, $request->inventory, ['schema' => 'net_quality_selection_v1', 'protocol' => $request->protocol->toArray(), 'inventory' => $request->inventory->toArray()], $segments()), $budget);
    }
}
