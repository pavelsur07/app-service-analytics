<?php

declare(strict_types=1);

namespace App\SalesPlanning\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * Интерфейс в Domain, реализация — Infrastructure/Persistence.
 *
 * Каждое чтение первым параметром принимает companyId (CLAUDE.md §1):
 * поиска плана по одному лишь идентификатору нет — план продаж
 * компании это её коммерческая тайна.
 */
interface SalesPlanRepository
{
    /**
     * Сохраняет новый план вместе со строками одной транзакцией:
     * план без части дней не появляется. Строки сбрасываются порциями
     * (CLAUDE.md §6), иначе память растёт с числом строк.
     *
     * @param list<SalesPlanLine> $lines
     */
    public function add(SalesPlan $plan, array $lines): void;

    public function get(string $companyId, Uuid $id): ?SalesPlan;

    /**
     * Строки перечисленных SKU плана — одним запросом на весь набор,
     * не SKU за запросом (CLAUDE.md §6).
     *
     * @param list<string> $marketplaceSkus
     *
     * @return array<string, list<SalesPlanLine>> по SKU; SKU без строк не входит
     */
    public function linesOfSkus(string $companyId, Uuid $planId, array $marketplaceSkus): array;

    /**
     * Сколько разных SKU в плане — для лимита плана (ADR-040 п. 13).
     */
    public function skuCount(string $companyId, Uuid $planId): int;

    /**
     * Фиксирует правку плана одной транзакцией: изменённые строки,
     * новые строки порциями, шапку с версией и записи журнала,
     * поставленные сценарием.
     *
     * @param list<SalesPlanLine> $newLines
     *
     * @throws \Doctrine\ORM\OptimisticLockException план изменил кто-то ещё
     */
    public function saveChanges(array $newLines): void;
}
