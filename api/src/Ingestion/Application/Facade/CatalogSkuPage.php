<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

/**
 * Страница каталога: keyset по артикулу (docs/patterns.md, «Пагинация»).
 * `nextCursor` — null на последней странице.
 */
final readonly class CatalogSkuPage
{
    /**
     * @param list<CatalogSku> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
