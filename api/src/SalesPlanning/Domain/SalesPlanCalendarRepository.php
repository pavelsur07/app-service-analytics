<?php

declare(strict_types=1);

namespace App\SalesPlanning\Domain;

use Symfony\Component\Uid\Uuid;

/** Human aggregate writes; the plan transaction owns flush/commit (ADR-046/048). */
interface SalesPlanCalendarRepository
{
    /**
     * Command-only ORM aggregate, never used as an entity list for display.
     * Throws on >500 rather than silently dropping entries.
     *
     * @return list<SalesPlanCalendarEntry>
     */
    public function entriesForUpdate(string $companyId, Uuid $planId, ?string $marketplaceSku = null): array;

    /** @param list<string> $marketplaceSkus */
    public function allSkusExist(string $companyId, Uuid $planId, array $marketplaceSkus): bool;

    public function add(SalesPlanCalendarEntry $entry): void;

    public function remove(SalesPlanCalendarEntry $entry): void;
}
