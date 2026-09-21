<?php

declare(strict_types=1);

namespace App\Planning\Application;

use App\Planning\Infrastructure\Repository\ExpiredPlanImportPreviewCleaner;

final readonly class CleanupExpiredPlanImportsAction
{
    public function __construct(private ExpiredPlanImportPreviewCleaner $cleaner)
    {
    }

    public function __invoke(?\DateTimeImmutable $now = null): int
    {
        return $this->cleaner->delete($now ?? new \DateTimeImmutable());
    }
}
