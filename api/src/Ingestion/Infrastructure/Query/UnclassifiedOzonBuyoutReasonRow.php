<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/** Агрегированная operational-диагностика исходов, оставшихся NULL. */
final readonly class UnclassifiedOzonBuyoutReasonRow
{
    public function __construct(
        public ?string $returnType,
        public ?string $returnReasonName,
        public ?string $status,
        public ?string $substatus,
        public ?int $cancelReasonId,
        public int $affectedRows,
        public string $firstBusinessDate,
        public string $lastBusinessDate,
    ) {
    }
}
