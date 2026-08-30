<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Message;

/**
 * Одно последовательное окно возвратов одного кабинета, обе даты включены.
 * В отличие от postings окно нельзя дробить на конкурентные дни: cursor
 * обслуживает весь диапазон, а account-lock исключает пересечение окон.
 */
final readonly class FetchOzonReturnsMessage
{
    public function __construct(
        public string $companyId,
        public string $marketplaceAccountId,
        public string $from,
        public string $to,
    ) {
    }
}
