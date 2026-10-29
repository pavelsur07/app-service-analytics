<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\NetForwardArtifactKind;

final readonly class NetQualityInventoryReader
{
    public function __construct(private NetForwardArtifactReader $reader)
    {
    }

    public function read(string $companyId, string $accountId, NetQualityArtifactRef $ref, NetQualityBudget $budget): NetQualityCaptureInventory|NetQualityTruthInventory
    {
        if (NetForwardArtifactKind::QualityInventory !== $ref->kind) {
            throw new \DomainException('Expected a sealed quality inventory.');
        }
        [$artifact, $data] = (new NetQualityArtifactPayloadReader($this->reader))->read($companyId, $accountId, $ref, 'quality_inventory', $budget);
        NetQualitySchema::keys($data, ['requestId', 'protocol', 'subtype', 'cutoffUtc', 'slots']);
        $slots = NetQualitySchema::rows($data['slots'], 200);
        $requestSlots = [];
        foreach ($slots as $slot) {
            NetQualitySchema::keys($slot, ['requestId', 'status', 'artifact', 'reason', 'actualReadAtUtc', 'scheduledAtUtc', 'inputId']);
            NetQualitySchema::utc(NetQualitySchema::string($slot, 'scheduledAtUtc'));
            if (null !== $slot['actualReadAtUtc']) {
                NetQualitySchema::utc(NetQualitySchema::string($slot, 'actualReadAtUtc'));
            }
            $requestSlots[] = ['requestId' => $slot['requestId'], 'status' => $slot['status'], 'artifact' => $slot['artifact'], 'reason' => $slot['reason']];
        }
        $requestData = $data;
        $requestData['slots'] = $requestSlots;
        $request = new NetQualityInventoryRequest($requestData);
        $parent = $artifact->manifest['parent'];
        if ($request->requestId !== $ref->requestId || !\is_array($parent) || NetQualityArtifactRef::fromArray($parent)->toArray() !== $request->protocol->toArray()) {
            throw new \UnexpectedValueException('Inventory parent or request identity mismatch.');
        }

        return 'capture' === $request->subtype
            ? new NetQualityCaptureInventory($ref, $request->protocol, $request->cutoff, $slots, $artifact->readAt)
            : new NetQualityTruthInventory($ref, $request->protocol, $request->cutoff, $slots, $artifact->readAt);
    }
}
