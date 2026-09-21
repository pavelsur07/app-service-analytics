<?php

declare(strict_types=1);

namespace App\Planning\Application;

use App\Planning\Infrastructure\Repository\CrossTenantExpiredPlanImportPreviewCleaner;

/** Операционная межарендаторная задача по CLAUDE.md §1. */
final readonly class CleanupExpiredPlanImportsAcrossCompaniesAction
{
    public function __construct(private CrossTenantExpiredPlanImportPreviewCleaner $cleaner)
    {
    }

    public function __invoke(?\DateTimeImmutable $now = null): int
    {
        return $this->cleaner->deleteExpiredAcrossCompanies($now ?? new \DateTimeImmutable());
    }
}
