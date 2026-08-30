<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

interface SalesFactRepository
{
    /**
     * Upsert по естественному ключу (company_id, marketplace_account_id,
     * source_row_id) — обновление только при изменившемся row_hash
     * (ADR-006). Идемпотентно: повторный запуск на тех же входных данных
     * не меняет результат.
     *
     * @param list<SalesFact> $facts
     */
    public function upsertAll(array $facts): void;

    /**
     * Восстанавливает отсутствующие posting/order links из исторического raw,
     * не откатывая mutable snapshot уже существующей sales_fact.
     *
     * @param list<SalesFact> $facts
     */
    public function backfillLinks(string $companyId, array $facts): void;
}
