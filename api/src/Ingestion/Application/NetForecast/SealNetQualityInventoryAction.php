<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\NetForecastArtifactRepository;
use App\Ingestion\Domain\NetForwardArtifactKind;
use Psr\Clock\ClockInterface;

final readonly class SealNetQualityInventoryAction
{
    public function __construct(private NetQualityArtifactWriter $writer, private NetForwardArtifactReader $reader, private NetQualityProtocolReader $protocols, private NetForecastArtifactRepository $repository, private ClockInterface $clock)
    {
    }

    public function __invoke(string $companyId, string $accountId, NetQualityInventoryRequest $request): NetQualityArtifactRef
    {
        $budget = new NetQualityBudget();
        $protocol = $this->protocols->read($companyId, $accountId, $request->protocol, $budget);
        $expectedCutoff = NetQualitySchema::utc(NetQualitySchema::string($protocol->data, 'capture' === $request->subtype ? 'captureToUtc' : 'truthCutoffUtc'));
        if ($request->cutoff->format('U.u') !== $expectedCutoff->format('U.u') || $this->clock->now() < $request->cutoff) {
            throw new \DomainException('Inventory must seal the registered, elapsed cutoff.');
        }
        $schedule = [];
        foreach ('capture' === $request->subtype ? $protocol->slots : $protocol->truthSlots as $slot) {
            $schedule[$slot['requestId']] = $slot;
        }
        $ids = array_keys($schedule);
        $received = array_map(static fn (array $slot): string => NetQualitySchema::string($slot, 'requestId'), $request->slots);
        sort($ids, \SORT_STRING);
        sort($received, \SORT_STRING);
        if ($ids !== $received) {
            throw new \DomainException('Inventory must include every registered slot exactly once.');
        }
        $kind = 'capture' === $request->subtype ? NetForwardArtifactKind::Input : NetForwardArtifactKind::Truth;
        $anchors = [];
        foreach (array_chunk($ids, 50) as $page) {
            $identities = array_map(static fn (string $id): array => ['kind' => $kind, 'requestId' => $id], $page);
            foreach ($this->repository->findBatch($companyId, $accountId, $identities) as $anchor) {
                $anchors[$anchor->root->requestId] = true;
            }
        }
        $slots = [];
        foreach ($request->slots as $slot) {
            $id = NetQualitySchema::string($slot, 'requestId');
            $planned = $schedule[$id];
            $at = null;
            if (\is_array($slot['artifact'])) {
                $ref = NetQualityArtifactRef::fromArray($slot['artifact']);
                $artifact = $this->reader->read($companyId, $accountId, $ref->kind, $id, $ref->sha256, deadline: $budget->deadline, budget: $budget);
                $at = $artifact->readAt;
                if (('success' === $slot['status']) !== ($at <= $request->cutoff)) {
                    throw new \DomainException('Inventory success/late status conflicts with actual winning readAt.');
                }
                if ('capture' === $request->subtype) {
                    $metadata = $artifact->manifest['metadata'];
                    $binding = \is_array($metadata) ? ($metadata['qualityProtocol'] ?? null) : null;
                    $boundRef = \is_array($binding) ? ($binding['protocol'] ?? null) : null;
                    if (!\is_array($boundRef) || NetQualityArtifactRef::fromArray($boundRef)->toArray() !== $request->protocol->toArray()) {
                        throw new \DomainException('Capture has no matching pre-registered protocol.');
                    }
                } else {
                    $parent = $artifact->manifest['parent'];
                    if (!\is_array($parent) || ($parent['requestId'] ?? null) !== ($planned['inputId'] ?? null)) {
                        throw new \DomainException('Truth is linked to a different registered input.');
                    }
                }
            } elseif (isset($anchors[$id])) {
                throw new \DomainException('An existing published artifact cannot be hidden as missing.');
            }
            $slot['scheduledAtUtc'] = $planned['scheduledAtUtc'];
            $slot['inputId'] = $planned['inputId'] ?? null;
            $slot['actualReadAtUtc'] = $at?->format('Y-m-d\TH:i:s.u\Z');
            $slots[] = $slot;
        }
        $data = $request->toArray();
        $data['slots'] = $slots;
        $requestHash = hash('sha256', (new NetForwardManifestCodec())->encode($request->toArray()));

        return $this->writer->publish($companyId, $accountId, new NetQualityArtifactDocument(NetForwardArtifactKind::QualityInventory, $request->requestId, $requestHash, $request->protocol, ['schema' => 'net_quality_inventory_v1', 'subtype' => $request->subtype], [['type' => 'quality_inventory', 'row' => ['value' => $data]]]), $budget);
    }
}
