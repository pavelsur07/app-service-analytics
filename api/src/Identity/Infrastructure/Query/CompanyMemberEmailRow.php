<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Query;

/**
 * Строка результата CompanyMemberEmailsQuery (CLAUDE.md §5: результат
 * DBAL-запроса маппится в readonly DTO).
 *
 * Поле пока одно, и обёртка вокруг строки выглядит избыточной ровно
 * до первого расширения: письмо с обращением по имени или отбор
 * получателей по роли добавят второе поле, и тогда сигнатуры
 * не придётся менять по всей цепочке.
 */
final readonly class CompanyMemberEmailRow
{
    public function __construct(
        public string $email,
    ) {
    }
}
