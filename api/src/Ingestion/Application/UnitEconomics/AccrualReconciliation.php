<?php

declare(strict_types=1);

namespace App\Ingestion\Application\UnitEconomics;

/**
 * Начисления периода по группам кабинета и итог к начислению.
 * Деньги — минорные единицы (ADR-004).
 */
final readonly class AccrualReconciliation
{
    /**
     * @param list<AccrualReconciliationGroup> $groups
     */
    public function __construct(
        /** null — за период нет ни одного начисления. */
        public ?string $currency,
        public array $groups,
        public int $totalMinor,
        /** Дни периода, за которые выгрузка начислений не проходила. */
        public int $daysWithoutAccruals,
    ) {
    }
}
