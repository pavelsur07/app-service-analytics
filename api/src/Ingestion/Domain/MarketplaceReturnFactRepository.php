<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

interface MarketplaceReturnFactRepository
{
    /**
     * Bulk upsert по (company_id, marketplace_account_id, source_row_id).
     * Повтор неизменившегося события не обновляет строку; исчезнувшие из
     * нового окна события не удаляются, потому что это event facts.
     *
     * @param list<MarketplaceReturnFact> $facts
     */
    public function upsertAll(array $facts): void;
}
