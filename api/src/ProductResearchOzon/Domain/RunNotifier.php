<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain;

interface RunNotifier
{
    public function notify(string $companyId, string $runId): void;
}
