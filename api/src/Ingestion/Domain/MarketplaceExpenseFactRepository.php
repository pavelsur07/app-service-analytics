<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

interface MarketplaceExpenseFactRepository
{
    /**
     * Upsert по естественному ключу (company_id, marketplace_account_id,
     * source_row_id) — обновление только при изменившемся row_hash
     * (ADR-006). Идемпотентно: повторный запуск на тех же входных данных
     * не меняет результат.
     *
     * Удаления исчезнувших здесь нет, в отличие от каталога: начисление,
     * однажды выпущенное площадкой, не пропадает — пересчёт приходит
     * новым начислением с новым идентификатором.
     *
     * @param list<MarketplaceExpenseFact> $facts
     */
    public function upsertAll(array $facts): void;
}
