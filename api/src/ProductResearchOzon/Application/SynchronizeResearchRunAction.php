<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Application;

use App\ProductResearchOzon\Domain\RunDeliveryRepository;
use App\ProductResearchOzon\Domain\RunSource;
use App\ProductResearchOzon\Domain\RunSyncFailure;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class SynchronizeResearchRunAction
{
    public function __construct(private RunDeliveryRepository $runs, private RunSource $source, private ClockInterface $clock)
    {
    }

    public function __invoke(string $companyId, string $runId, bool $repair = false): bool
    {
        $owner = Uuid::v7()->toRfc4122();
        $source = $this->runs->claimPoll($companyId, $runId, $owner, $this->clock->now(), $repair);
        if (null === $source) {
            return false;
        }
        try {
            return $this->runs->apply($companyId, $runId, $owner, $this->source->progress($companyId, $runId, $source), $this->clock->now());
        } catch (RunSyncFailure $failure) {
            $this->runs->failed($companyId, $runId, $owner, $failure->reason, $this->clock->now());

            return false;
        }
    }
}
