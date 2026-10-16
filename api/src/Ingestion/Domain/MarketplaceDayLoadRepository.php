<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Отметки загрузки дня (ADR-038). Upsert по ключу
 * (company_id, marketplace_account_id, report_type, period): повтор
 * прогона перезаписывает своё время, а не добавляет строку.
 */
interface MarketplaceDayLoadRepository
{
    /** Все страницы дня записаны. */
    public function markCompleted(string $companyId, string $marketplaceAccountId, string $reportType, \DateTimeImmutable $period, \DateTimeImmutable $at): void;

    /** Прогон дня отказал после начала — часть страниц могла записаться. */
    public function markFailed(string $companyId, string $marketplaceAccountId, string $reportType, \DateTimeImmutable $period, \DateTimeImmutable $at, string $failure): void;
}
