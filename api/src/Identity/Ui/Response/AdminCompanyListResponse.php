<?php

declare(strict_types=1);

namespace App\Identity\Ui\Response;

/**
 * Offset-пагинация (docs/patterns.md, «Контракт списочного эндпоинта»):
 * компании — справочник, растущий числом клиентов, а не объёмом данных
 * одного клиента, поэтому не keyset. Первый такой ответ в проекте:
 * до сих пор все списки были курсорными.
 */
final readonly class AdminCompanyListResponse
{
    /**
     * @param list<AdminCompanyResponse> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $pages,
        public int $page,
        public int $per_page,
    ) {
    }
}
