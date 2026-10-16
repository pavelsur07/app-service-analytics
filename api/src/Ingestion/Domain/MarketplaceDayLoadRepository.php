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
 * все дни, которые она загружает, снимок — день снимка. Все отметки
 * прогона пишутся одним вызовом — одним запросом, не циклом (§6).
 */
interface MarketplaceDayLoadRepository
{
    /** Потолок диапазона одной отметки — больше года выгрузки не бывает. */
    public const int MAX_DAYS = 366;

    /**
     * Дни одного типа в списке не пересекаются — иначе исход дня
     * неоднозначен, и вызов отклоняется.
     *
     * @param list<DayLoadMark> $marks
     */
    public function mark(string $companyId, string $marketplaceAccountId, array $marks, \DateTimeImmutable $at): void;
}
