<?php

declare(strict_types=1);

namespace App\Planning\Domain;

interface PlanImportPreviewRepository
{
    public function addOrGetReady(string $companyId, PlanImportPreview $preview, \DateTimeImmutable $now): PlanImportPreview;

    public function get(string $companyId, string $marketplaceAccountId, string $previewId): ?PlanImportPreview;
}
