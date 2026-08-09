<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

/**
 * Ответ keyset-списка (docs/patterns.md, «Контракт списочного эндпоинта»):
 * items + next_cursor, null в конце — без total, COUNT(*) на факт-таблицах
 * не выполняется.
 */
final readonly class SalesFactListResponse
{
    /**
     * @param list<SalesFactListItemResponse> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
