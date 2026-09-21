<?php

declare(strict_types=1);

namespace App\Planning\Domain;

interface PlanImportPreviewRepository
{
    public function add(PlanImportPreview $preview): void;

    public function get(string $companyId, string $marketplaceAccountId, string $previewId): ?PlanImportPreview;
}
