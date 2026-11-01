<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Application;

use App\ProductResearchOzon\Domain\RunDeliveryRepository;
use App\ProductResearchOzon\Domain\RunSource;
use App\ProductResearchOzon\Domain\RunSyncFailure;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class RelayResearchRunAction
{
    public function __construct(private RunDeliveryRepository $runs, private RunSource $source, private ClockInterface $clock)
    {
    }

    public function __invoke(string $companyId, string $runId): void
    {
        $owner = Uuid::v7()->toRfc4122();
        $delivery = $this->runs->claimDelivery($companyId, $runId, $owner, $this->clock->now());
        if (null === $delivery) {
            return;
        }
        try {
            $this->source->submit($companyId, $delivery);
            $this->runs->delivered($companyId, $runId, $owner, $this->clock->now());
        } catch (RunSyncFailure $failure) {
            $this->runs->failed($companyId, $runId, $owner, $failure->reason, $this->clock->now());
        }
    }
}
