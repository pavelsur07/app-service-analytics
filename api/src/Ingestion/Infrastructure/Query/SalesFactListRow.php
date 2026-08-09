<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/**
 * Строго типизированный результат SalesFactListQuery — снимает
 * приведение типов из mixed-строк DBAL с вызывающего кода (контроллера).
 */
final readonly class SalesFactListRow
{
    public function __construct(
        public string $marketplaceAccountId,
        public string $sourceRowId,
        public string $businessDate,
        public string $status,
        public string $marketplaceSku,
        public int $quantity,
        public int $amountMinor,
        public int $commissionAmountMinor,
        public string $currency,
    ) {
    }
}
