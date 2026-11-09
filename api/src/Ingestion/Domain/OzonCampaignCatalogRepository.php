<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

interface OzonCampaignCatalogRepository
{
    public function replace(string $companyId, OzonCampaignCatalogSnapshot $snapshot): void;
}
