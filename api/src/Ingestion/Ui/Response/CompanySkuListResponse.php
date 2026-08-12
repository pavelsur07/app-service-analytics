<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

/**
 * Только сами артикулы — расширение хранит их локально и сверяет
 * с открытой карточкой. Ничего, кроме строки артикула, для этого
 * не нужно, а список бывает в тысячи элементов.
 */
final readonly class CompanySkuListResponse
{
    /**
     * @param list<string> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
