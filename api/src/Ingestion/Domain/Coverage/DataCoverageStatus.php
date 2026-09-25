<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Coverage;

/**
 * Статус дня в отчёте о полноте данных. Порядок «хуже — лучше» для строки
 * «Итого»: ошибка хуже отсутствия, отсутствие хуже загрузки.
 */
enum DataCoverageStatus: string
{
    /** Есть выгрузка, покрывающая этот день. */
    case Loaded = 'loaded';

    /** День уже должен быть загружен, а выгрузки нет. */
    case Missing = 'missing';

    /** Выгрузки нет, а загрузка этого дня лежит в очереди `failed`. */
    case Failed = 'failed';

    /** День ещё не наступил. */
    case Pending = 'pending';

    public function severity(): int
    {
        return match ($this) {
            self::Pending, self::Loaded => 0,
            self::Missing => 1,
            self::Failed => 2,
        };
    }
}
