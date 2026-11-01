<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain;

final class RunSyncFailure extends \RuntimeException
{
    public readonly string $reason;

    public function __construct(string $reason = 'research_service_unavailable')
    {
        $this->reason = \in_array($reason, ['research_service_unavailable', 'research_service_not_configured', 'invalid_run_response', 'run_source_mismatch', 'run_progress_conflict'], true) ? $reason : 'research_service_unavailable';
        parent::__construct($this->reason);
    }
}
