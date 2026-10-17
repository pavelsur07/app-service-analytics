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
}
