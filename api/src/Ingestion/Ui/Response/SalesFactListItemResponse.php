<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

/**
 * amountMinor/currency, не Money — brick/money не выходит в контракт API
 * (ADR-004), фронтенд форматирует сам через formatMinorAmount(), денежная
 * арифметика в компонентах запрещена (docs/patterns.md).
 */
final readonly class SalesFactListItemResponse
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
