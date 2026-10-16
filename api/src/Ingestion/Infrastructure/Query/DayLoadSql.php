<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/**
 * Общее условие «день отклонён» для запросов полноты (ADR-038): у дня
 * есть raw, но последний прогон отказал, и записано могло быть не всё.
 *
 * Подставляется в WHERE запроса по `marketplace_raw_document` с алиасом
 * `d`: отклонённый день из подсчёта загруженных дней выпадает. Отметки
 * нет — день из истории до ADR-038, о нём судят по raw, как раньше.
 * Ключ отметки совпадает с префиксом запроса, поэтому подзапрос идёт
 * по первичному ключу таблицы отметок.
 */
final class DayLoadSql
{
    public const string NOT_REJECTED = <<<'SQL'
        NOT EXISTS (
            SELECT 1
            FROM marketplace_day_load AS l
            WHERE l.company_id = d.company_id
              AND l.marketplace_account_id = d.marketplace_account_id
              AND l.report_type = d.report_type
              AND l.period = d.period
              AND l.failed_at IS NOT NULL
        )
        SQL;

    private function __construct()
    {
    }
}
