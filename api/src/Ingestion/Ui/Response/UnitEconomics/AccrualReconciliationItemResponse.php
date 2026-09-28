<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\UnitEconomics;

/**
 * Статья сверки. Деньги — минорные единицы со знаком площадки (ADR-004).
 */
final readonly class AccrualReconciliationItemResponse
{
    public function __construct(
        public int $feeTypeId,
        public string $name,
        /** Нетто: начислено плюс возвращено. */
        public int $amountMinor,
        /** Начислено — строки со знаком статьи. */
        public int $accruedMinor,
        /** Возвращено — строки с обратным знаком (возврат комиссии, эквайринга, отмена логистики); 0 — возвратов не было. */
        public int $reversedMinor,
    ) {
    }
}
