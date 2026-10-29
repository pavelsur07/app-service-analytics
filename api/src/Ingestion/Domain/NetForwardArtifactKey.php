<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Symfony\Component\Uid\Uuid;

final readonly class NetForwardArtifactKey
{
    public const string PREFIX = 'conwix/monolith/net-forecast/v1';

    public function __construct(public string $companyId, public string $accountId, public NetForwardArtifactKind $kind, public string $requestId, public string $attemptId, public string $sha256, public ?int $ordinal = null)
    {
        foreach ([$companyId, $accountId, $requestId, $attemptId] as $id) {
            if (!Uuid::isValid($id) || Uuid::fromString($id)->toRfc4122() !== $id) {
                throw new \InvalidArgumentException('Artifact identity requires canonical UUIDs.');
            }
        }
        foreach ([$requestId, $attemptId] as $id) {
            if ('7' !== $id[14]) {
                throw new \InvalidArgumentException('Artifact request and attempt require UUIDv7.');
            }
        }
        if (1 !== preg_match('/^[a-f0-9]{64}$/D', $sha256) || (null !== $ordinal && ($ordinal < 0 || $ordinal >= 128))) {
            throw new \InvalidArgumentException('Invalid artifact hash or ordinal.');
        }
    }

    public function assertScope(string $companyId, string $accountId): void
    {
        if ($companyId !== $this->companyId || $accountId !== $this->accountId) {
            throw new \InvalidArgumentException('Artifact scope mismatch.');
        }
    }

    public function toString(): string
    {
        return self::PREFIX.'/companies/'.$this->companyId.'/accounts/'.$this->accountId.'/'.$this->kind->value.'/'.$this->requestId.'/attempts/'.$this->attemptId.'/'.(null === $this->ordinal ? 'manifests/'.$this->sha256 : 'chunks/'.$this->ordinal.'-'.$this->sha256).'.json';
    }
}
