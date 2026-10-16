<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Отметки загрузки дня (ADR-038). Upsert по ключу
 * (company_id, marketplace_account_id, report_type, period): повтор
 * прогона перезаписывает своё время, а не добавляет строку.
 *
 * Отметка ставится на каждый день `from..to` включительно: дневная
 * выгрузка — диапазон из одного дня, диапазонная (возвраты, реклама) —
 * все дни, которые она загружает, снимок — день снимка.
 */
interface MarketplaceDayLoadRepository
{
    /** Потолок диапазона одной отметки — больше года выгрузки не бывает. */
    public const int MAX_DAYS = 366;

    /** Все страницы выгрузки записаны. */
    public function markCompleted(string $companyId, string $marketplaceAccountId, string $reportType, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $at): void;

    /** Прогон отказал после начала — часть страниц могла записаться. */
    public function markFailed(string $companyId, string $marketplaceAccountId, string $reportType, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $at, string $failure): void;
}
