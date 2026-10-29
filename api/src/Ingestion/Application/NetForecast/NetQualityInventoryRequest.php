<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\NetForwardArtifactKind;

final readonly class NetQualityInventoryRequest
{
    public string $requestId;
    public NetQualityArtifactRef $protocol;
    public string $subtype;
    public \DateTimeImmutable $cutoff;
    /** @var list<array<string, mixed>> */
    public array $slots;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        NetQualitySchema::keys($data, ['requestId', 'protocol', 'subtype', 'cutoffUtc', 'slots']);
        $this->requestId = NetQualitySchema::uuid(NetQualitySchema::string($data, 'requestId'), true);
        if (!\is_array($data['protocol'])) {
            throw new \InvalidArgumentException('Inventory protocol reference missing.');
        }
        $this->protocol = NetQualityArtifactRef::fromArray($data['protocol']);
        $this->subtype = NetQualitySchema::string($data, 'subtype');
        if (NetForwardArtifactKind::QualityProtocol !== $this->protocol->kind || !\in_array($this->subtype, ['capture', 'truth'], true)) {
            throw new \InvalidArgumentException('Invalid quality inventory subtype or parent.');
        }
        $this->cutoff = NetQualitySchema::utc(NetQualitySchema::string($data, 'cutoffUtc'));
        $slots = [];
        foreach (NetQualitySchema::rows($data['slots'], 200) as $slot) {
            NetQualitySchema::keys($slot, ['requestId', 'status', 'artifact', 'reason']);
            $id = NetQualitySchema::uuid(NetQualitySchema::string($slot, 'requestId'), true);
            $status = NetQualitySchema::string($slot, 'status');
            $hasArtifact = \in_array($status, ['success', 'late'], true);
            if (isset($slots[$id]) || !\in_array($status, ['success', 'missing', 'failed', 'late'], true) || ($hasArtifact !== \is_array($slot['artifact'])) || ('success' === $status ? null !== $slot['reason'] : !\is_string($slot['reason']))) {
                throw new \InvalidArgumentException('Invalid or duplicate inventory receipt.');
            }
            if ($hasArtifact) {
                if (!\is_array($slot['artifact'])) {
                    throw new \InvalidArgumentException('Successful inventory receipt requires an artifact reference.');
                }
                $ref = NetQualityArtifactRef::fromArray($slot['artifact']);
                $kind = 'capture' === $this->subtype ? NetForwardArtifactKind::Input : NetForwardArtifactKind::Truth;
                if ($ref->kind !== $kind || $ref->requestId !== $id) {
                    throw new \InvalidArgumentException('Inventory receipt identity mismatch.');
                }
            } elseif (null !== $slot['artifact']) {
                throw new \InvalidArgumentException('A missing receipt cannot contain an artifact.');
            }
            $slots[$id] = $slot;
        }
        ksort($slots, \SORT_STRING);
        $this->slots = array_values($slots);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['requestId' => $this->requestId, 'protocol' => $this->protocol->toArray(), 'subtype' => $this->subtype, 'cutoffUtc' => $this->cutoff->format('Y-m-d\TH:i:s.u\Z'), 'slots' => $this->slots];
    }
}
