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
     * Дни перечисленных SKU плана — одним запросом на весь набор,
     * не SKU за запросом (CLAUDE.md §6).
     *
     * @param list<string> $marketplaceSkus
     *
     * @return array<array-key, list<PlannedDay>> по SKU; SKU без строк не входит
     */
    public function daysOfSkus(string $companyId, Uuid $planId, array $marketplaceSkus): array;

    /**
     * Сколько разных SKU в плане — для лимита плана (ADR-040 п. 13).
     */
    public function skuCount(string $companyId, Uuid $planId): int;

    /**
     * Фиксирует ввод SKU одной транзакцией (ADR-041): сначала шапка
     * с проверкой версии в самом UPDATE и записи журнала, поставленные
     * сценарием, затем дни SKU — пакетной вставкой с обновлением
     * существующих по естественному ключу строки.
     *
     * @param list<SkuPeriodPlan> $skuPlans
     *
     * @throws \Doctrine\ORM\OptimisticLockException план изменил кто-то ещё
     */
    public function saveSkuPlans(SalesPlan $plan, array $skuPlans): void;

    /**
     * Правка одного дня SKU (ADR-041): шапка с версией и журнал — первыми,
     * затем UPDATE строки по естественному ключу.
     *
     * @throws \Doctrine\ORM\OptimisticLockException план изменил кто-то ещё
     */
    public function saveDay(SalesPlan $plan, string $marketplaceSku, PlannedDay $day): void;

    /**
     * SKU убран из черновика (ADR-040 п. 8): шапка с версией и журнал —
     * первыми, затем DELETE строк SKU. Прежние дни — в журнале.
     *
     * @throws \Doctrine\ORM\OptimisticLockException план изменил кто-то ещё
     */
    public function removeSku(SalesPlan $plan, string $marketplaceSku): void;
}
