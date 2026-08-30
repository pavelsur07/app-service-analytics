<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

interface MarketplacePostingStatusRepository
{
    /**
     * @param list<MarketplacePostingStatus> $statuses
     *
     * @return int число добавленных наблюдений
     */
    public function recordChanged(string $companyId, array $statuses): int;
}
