<?php

declare(strict_types=1);

namespace App\Ingestion\Application\UnitEconomics;

/**
 * Статья начисления — один тип площадки — за период.
 *
 * `amountMinor` — нетто: начислено плюс возвращено. Начислено — строки
 * со знаком самой статьи (у затраты — «минус», у компенсации — «плюс»),
 * возвращено — строки с обратным знаком: возврат комиссии, эквайринга,
 * отмена логистики. Знак статьи — знак нетто; нулевое нетто считается
 * затратой.
 */
final readonly class AccrualReconciliationItem
{
    public function __construct(
        public int $feeTypeId,
        public string $name,
        public int $amountMinor,
        public int $accruedMinor,
        /** 0 — возвратов по статье за период не было. */
        public int $reversedMinor,
    ) {
    }
}
