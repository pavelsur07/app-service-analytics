<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

interface AdSkuExpenseFactRepository
{
    /**
     * Upsert по естественному ключу (company_id, marketplace_account_id,
     * source_row_id) — ADR-035 п. 2. Строка обновляется, только если
     * изменилась сумма и ответ, из которого она пришла, получен не раньше
     * записанного: старый отчёт, обработанный после нового, свежие цифры
     * не перезаписывает. Идемпотентно (CLAUDE.md §4).
     *
     * Удаления исчезнувших нет: SKU, пропавший из нового ответа за прошлый
     * день, не встречался ни разу, а сверка с `by-day` покажет пару, где
     * это случилось (ADR-035, отвергнутая альтернатива 3).
     *
     * @param list<AdSkuExpenseFact> $facts
     */
    public function upsertAll(array $facts): void;
}
