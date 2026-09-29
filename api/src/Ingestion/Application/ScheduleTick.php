<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

/**
 * Что поставил один тик планировщика — для строки журнала.
 *
 * Рескан по сырью не виден: raw дедуплицируется по содержимому (ADR-006),
 * и перечитанный день без изменений следа не оставляет. Поэтому тик сам
 * называет окна, которые поставил, — иначе «рескан прошёл» доказывалось бы
 * только косвенно.
 */
final readonly class ScheduleTick
{
    public function __construct(
        public int $accounts,
        public bool $rescan,
        public int $postingDays,
        public int $expenseDays,
        public int $returnDays,
    ) {
    }
}
