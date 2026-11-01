<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\ProductResearchOzon\Domain\RunSource;
use App\ProductResearchOzon\Domain\RunSyncFailure;
use App\ProductResearchOzon\Domain\ValueObject\RunDelivery;
use App\ProductResearchOzon\Domain\ValueObject\RunProgress;

final class ResearchRunSource implements RunSource
{
    public int $submissions = 0;
    public bool $loseAck = true;

    public function __construct(public RunProgress $result)
    {
    }

    public function submit(string $companyId, RunDelivery $delivery): void
    {
        ++$this->submissions;
        if ($this->loseAck) {
            throw new RunSyncFailure();
        }
    }

    public function progress(string $companyId, string $runId, string $sourceId): RunProgress
    {
        return $this->result;
    }
}
