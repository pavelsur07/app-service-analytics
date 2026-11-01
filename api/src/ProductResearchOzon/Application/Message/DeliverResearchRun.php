<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Application\Message;

final readonly class DeliverResearchRun
{
    public function __construct(public string $companyId, public string $runId)
    {
    }
}
