<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Разобранная страница каталога: позиции и курсор на следующую.
 *
 * $lastId пустой означает «страниц больше нет» — так отвечает сама
 * площадка, отдельного признака конца у неё нет.
 */
final readonly class OzonProductListPage
{
    /**
     * @param list<OzonProductListItem> $items
     */
    public function __construct(
        public array $items,
        public string $lastId,
        public int $itemsOnPage,
    ) {
    }

    /**
     * Идентификаторы для запроса деталей. Отдельным методом, а не
     * заботой вызывающего: product_id нужен ровно для этого запроса
     * и никуда больше не уходит.
     *
     * @return list<int>
     */
    public function productIds(): array
    {
        return array_map(
            static fn (OzonProductListItem $item): int => $item->productId,
            $this->items,
        );
    }
}
