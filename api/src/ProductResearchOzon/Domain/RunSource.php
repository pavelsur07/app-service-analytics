<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain;

use App\ProductResearchOzon\Domain\ValueObject\RunDelivery;
use App\ProductResearchOzon\Domain\ValueObject\RunProgress;

interface RunSource
{
    public function submit(string $companyId, RunDelivery $delivery): void;

    public function progress(string $companyId, string $runId, string $sourceId): RunProgress;
}
