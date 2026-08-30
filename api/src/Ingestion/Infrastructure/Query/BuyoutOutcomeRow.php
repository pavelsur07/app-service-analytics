<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/** Одна sales_fact с вычисленным, но не материализованным исходом. */
final readonly class BuyoutOutcomeRow
{
    public function __construct(
        public string $companyId,
        public string $marketplaceAccountId,
        public string $sourceRowId,
        public ?string $postingNumber,
        public ?string $orderNumber,
        public string $marketplaceSku,
        public int $quantity,
        public string $businessDate,
        public ?string $outcome,
        public ?string $handedOverAt,
        public ?string $resolvedAt,
    ) {
    }
}
