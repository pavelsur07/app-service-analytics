<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain;

use App\ProductResearchOzon\Domain\ValueObject\RunDelivery;
use App\ProductResearchOzon\Domain\ValueObject\RunProgress;

interface RunDeliveryRepository
{
    public function claimDelivery(string $companyId, string $runId, string $owner, \DateTimeImmutable $at): ?RunDelivery;

    public function delivered(string $companyId, string $runId, string $owner, \DateTimeImmutable $at): bool;

    public function claimPoll(string $companyId, string $runId, string $owner, \DateTimeImmutable $at): ?string;

    public function apply(string $companyId, string $runId, string $owner, RunProgress $progress, \DateTimeImmutable $at): bool;

    public function failed(string $companyId, string $runId, string $owner, string $reason, \DateTimeImmutable $at): void;
}
