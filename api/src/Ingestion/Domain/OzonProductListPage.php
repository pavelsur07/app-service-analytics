<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Разобранная страница каталога: артикулы и курсор на следующую.
 *
 * $lastId пустой означает «страниц больше нет» — так отвечает сама
 * площадка, отдельного признака конца у неё нет.
 */
final readonly class OzonProductListPage
{
    /**
     * @param list<string> $skus
     */
    public function __construct(
        public array $skus,
        public string $lastId,
        public int $itemsOnPage,
    ) {
    }
}
