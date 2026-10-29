<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\NetForwardArtifactKind;
use Symfony\Component\Uid\Uuid;

final readonly class NetQualityArtifactRef
{
    public function __construct(public NetForwardArtifactKind $kind, public string $requestId, public string $sha256)
    {
        if (!Uuid::isValid($requestId) || Uuid::fromString($requestId)->toRfc4122() !== $requestId || '7' !== $requestId[14] || 1 !== preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
            throw new \InvalidArgumentException('An artifact reference requires a canonical UUIDv7 and SHA256.');
        }
    }

    /** @return array{kind: string, requestId: string, artifactHash: string} */
    public function toArray(): array
    {
        return ['kind' => $this->kind->value, 'requestId' => $this->requestId, 'artifactHash' => $this->sha256];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys);
        if (['artifactHash', 'kind', 'requestId'] !== $keys || !\is_string($value['kind']) || !\is_string($value['requestId']) || !\is_string($value['artifactHash'])) {
            throw new \InvalidArgumentException('Invalid artifact reference fields.');
        }

        return new self(NetForwardArtifactKind::from($value['kind']), $value['requestId'], $value['artifactHash']);
    }
}
