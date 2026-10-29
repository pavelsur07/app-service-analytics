<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\NetForecastArtifactRepository;
use App\Ingestion\Domain\NetForwardArtifactKey;
use App\Ingestion\Domain\NetForwardArtifactKind;
use App\Ingestion\Domain\NetForwardArtifactMissing;
use App\Ingestion\Domain\NetForwardArtifactStorage;
use Doctrine\DBAL\Connection;

final readonly class NetForwardArtifactReader
{
    public function __construct(private Connection $connection, private NetForecastArtifactRepository $repository, private NetForwardArtifactStorage $storage, private NetForwardManifestCodec $codec)
    {
    }

    public function read(string $companyId, string $accountId, NetForwardArtifactKind $kind, string $requestId, ?string $expectedHash = null, ?string $requestHash = null, ?int $deadline = null, ?NetQualityBudget $budget = null, int $depth = 0): VerifiedNetForwardArtifact
    {
        $this->assertNoTransaction();
        $budget?->guard();
        if ($depth < 0 || $depth >= 6) {
            throw new \UnexpectedValueException('Artifact parent depth exceeded.');
        }
        if (null !== $deadline && hrtime(true) >= $deadline) {
            throw new \OverflowException('Artifact read deadline exceeded.');
        }
        $deadline ??= (int) (hrtime(true) + 30000000000);
        $publication = $budget?->artifacts->find($companyId, $accountId, $kind, $requestId) ?? $this->repository->find($companyId, $accountId, $kind, $requestId) ?? throw new NetForwardArtifactMissing('Artifact publication missing.');
        $publication->root->assertScope($companyId, $accountId);
        $budget?->artifacts->remember($publication);
        if ((null !== $expectedHash && $expectedHash !== $publication->root->sha256) || (null !== $requestHash && $requestHash !== $publication->requestHash)) {
            throw new \DomainException('Artifact reference or request conflicts.');
        }
        $budget?->readBytes($publication->manifestByteSize);
        $rootBytes = $this->storage->get($companyId, $accountId, $publication->root, $publication->manifestByteSize);
        $budget?->guard();
        if (\strlen($rootBytes) !== $publication->manifestByteSize || hash('sha256', $rootBytes) !== $publication->root->sha256) {
            throw new \UnexpectedValueException('Root anchor integrity mismatch.');
        }
        $root = $this->codec->decode($rootBytes);
        $required = ['attemptId', 'chunks', 'companyId', 'kind', 'marketplaceAccountId', 'metadata', 'parent', 'readAtUtc', 'requestHash', 'requestId'];
        if (array_keys($root) !== $required || $root['companyId'] !== $companyId || $root['marketplaceAccountId'] !== $accountId || $root['kind'] !== $kind->value || $root['requestId'] !== $requestId || $root['requestHash'] !== $publication->requestHash || $root['attemptId'] !== $publication->root->attemptId || !\is_string($root['readAtUtc']) || !\is_array($root['metadata']) || !\is_array($root['chunks'])) {
            throw new \UnexpectedValueException('Artifact root identity mismatch.');
        }
        if (null !== $kind->schemaVersion() && ($root['metadata']['schema'] ?? null) !== $kind->schemaVersion()) {
            throw new \UnexpectedValueException('Unsupported quality artifact schema version.');
        }
        $readAt = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $root['readAtUtc'], new \DateTimeZone('UTC'));
        if (false === $readAt || $readAt->format('Y-m-d\TH:i:s.u\Z') !== $root['readAtUtc']) {
            throw new \UnexpectedValueException('Invalid exact artifact readAt.');
        }
        $verified = new VerifiedNetForwardArtifact($publication, $readAt, $root);
        foreach ($this->segments($companyId, $accountId, $verified, $deadline, $budget) as $_) {
            // Verify every chunk before returning any successful reference.
        }
        $parentKind = $kind->parentKind();
        if (null === $parentKind) {
            if (null !== $root['parent']) {
                throw new \UnexpectedValueException('Root artifact cannot have a parent.');
            }
        } else {
            $parent = $root['parent'];
            $keys = NetForwardArtifactKind::Truth === $kind ? ['artifactHash', 'requestId'] : ['artifactHash', 'kind', 'requestId'];
            if (!\is_array($parent) || array_keys($parent) !== $keys || !\is_string($parent['requestId']) || !\is_string($parent['artifactHash']) || (NetForwardArtifactKind::Truth !== $kind && $parent['kind'] !== $parentKind->value)) {
                throw new \UnexpectedValueException('Artifact parent kind or identity mismatch.');
            }
            new NetQualityArtifactRef($parentKind, $parent['requestId'], $parent['artifactHash']);
            $this->read($companyId, $accountId, $parentKind, $parent['requestId'], $parent['artifactHash'], deadline: $deadline, budget: $budget, depth: $depth + 1);
        }

        return $verified;
    }

    /** @return \Generator<int, array{type: string, rows: list<array<array-key, mixed>>}> */
    public function segments(string $companyId, string $accountId, VerifiedNetForwardArtifact $artifact, ?int $deadline = null, ?NetQualityBudget $budget = null): \Generator
    {
        $this->assertNoTransaction();
        $artifact->publication->root->assertScope($companyId, $accountId);
        $deadline ??= (int) (hrtime(true) + 30000000000);
        $descriptors = $artifact->manifest['chunks'];
        if (!\is_array($descriptors) || !array_is_list($descriptors) || \count($descriptors) > 128) {
            throw new \UnexpectedValueException('Invalid chunk list.');
        }
        $total = 0;
        foreach ($descriptors as $ordinal => $descriptor) {
            if (hrtime(true) > $deadline || !\is_array($descriptor) || array_keys($descriptor) !== ['bytes', 'ordinal', 'rows', 'sha256', 'type'] || $descriptor['ordinal'] !== $ordinal || !\is_int($descriptor['bytes']) || $descriptor['bytes'] < 1 || $descriptor['bytes'] > 1048576 || !\is_int($descriptor['rows']) || $descriptor['rows'] < 1 || $descriptor['rows'] > 500 || !\is_string($descriptor['sha256']) || !\is_string($descriptor['type'])) {
                throw new \UnexpectedValueException('Invalid artifact chunk descriptor or deadline.');
            }
            $total += $descriptor['bytes'];
            if ($total > 134217728) {
                throw new \OverflowException('Artifact aggregate byte limit exceeded.');
            }
            $root = $artifact->publication->root;
            $key = new NetForwardArtifactKey($companyId, $accountId, $root->kind, $root->requestId, $root->attemptId, $descriptor['sha256'], $ordinal);
            $budget?->readBytes($descriptor['bytes']);
            $body = $this->storage->get($companyId, $accountId, $key, $descriptor['bytes']);
            $budget?->guard();
            if (\strlen($body) !== $descriptor['bytes'] || hash('sha256', $body) !== $descriptor['sha256']) {
                throw new \UnexpectedValueException('Artifact chunk size mismatch.');
            }
            $segment = $this->codec->decode($body);
            if (array_keys($segment) !== ['rows', 'type'] || $segment['type'] !== $descriptor['type'] || !\is_array($segment['rows']) || !array_is_list($segment['rows']) || \count($segment['rows']) !== $descriptor['rows']) {
                throw new \UnexpectedValueException('Artifact segment mismatch.');
            }
            foreach ($segment['rows'] as $row) {
                if (!\is_array($row)) {
                    throw new \UnexpectedValueException('Invalid artifact segment row.');
                }
            }
            /** @var list<array<array-key, mixed>> $rows */
            $rows = $segment['rows'];
            yield ['type' => $descriptor['type'], 'rows' => $rows];
        }
    }

    public function assertNoTransaction(): void
    {
        $native = $this->connection->getNativeConnection();
        if ($this->connection->isTransactionActive() || ($native instanceof \PDO && $native->inTransaction())) {
            throw new \LogicException('Artifact IO requires no active DB transaction.');
        }
    }

    /** @param list<string> $requestIds */
    public function prefetch(string $companyId, string $accountId, NetForwardArtifactKind $kind, array $requestIds, NetQualityBudget $budget): void
    {
        $this->assertNoTransaction();
        if (\count($requestIds) > 200) {
            throw new \OverflowException('Too many explicit artifact references.');
        }
        foreach (array_chunk(array_values(array_unique($requestIds)), 50) as $page) {
            $budget->guard();
            $identities = array_map(static fn (string $id): array => ['kind' => $kind, 'requestId' => $id], $page);
            foreach ($this->repository->findBatch($companyId, $accountId, $identities) as $publication) {
                $publication->root->assertScope($companyId, $accountId);
                $budget->artifacts->remember($publication);
            }
        }
    }
}
