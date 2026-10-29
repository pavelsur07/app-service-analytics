<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\NetForecastArtifactRepository;
use App\Ingestion\Domain\NetForwardArtifactKey;
use App\Ingestion\Domain\NetForwardArtifactStorage;

final readonly class PublishNetForecastCaptureAction
{
    public function __construct(private NetForecastArtifactRepository $repository, private NetForwardArtifactStorage $storage, private NetForwardArtifactReader $reader)
    {
    }

    public function __invoke(string $companyId, string $accountId, PreparedNetForwardArtifact $artifact, ?NetQualityBudget $budget = null): VerifiedNetForwardArtifact
    {
        $this->reader->assertNoTransaction();
        $root = $artifact->publication->root;
        $beforeRead = null === $budget ? null : static fn (int $bytes) => $budget->readBytes($bytes);
        $root->assertScope($companyId, $accountId);
        $artifact->spool->guard();
        $existing = $this->repository->find($companyId, $accountId, $root->kind, $root->requestId);
        if (null === $existing) {
            foreach ($artifact->spool->descriptors() as $descriptor) {
                $artifact->spool->guard();
                $key = new NetForwardArtifactKey($companyId, $accountId, $root->kind, $root->requestId, $root->attemptId, $descriptor['sha256'], $descriptor['ordinal']);
                $this->storage->putContent($companyId, $accountId, $key, $artifact->spool->body($descriptor['ordinal']), $beforeRead);
            }
            $artifact->spool->guard();
            $this->storage->putContent($companyId, $accountId, $root, $artifact->rootBytes, $beforeRead);
            $artifact->spool->guard();
            $this->repository->publishFirst($companyId, $accountId, $artifact->publication, $artifact->spool->deadlineNs);
        }

        return $this->reader->read($companyId, $accountId, $root->kind, $root->requestId, requestHash: $artifact->publication->requestHash, deadline: $artifact->spool->deadlineNs, budget: $budget);
    }
}
