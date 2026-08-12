<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

/**
 * Итог по артикулу за окно дней. `totals` — список, а не одно значение,
 * потому что валюты не складываются (CLAUDE.md §3): сегодня элемент
 * будет один, но контракт не даёт молча сложить два.
 *
 * Пустой `totals` означает «продаж за это окно нет» — это валидный
 * ответ, а не 404: карточка своя, просто ничего не продалось.
 */
final readonly class SkuSalesSummaryResponse
{
    /**
     * @param list<SkuSalesTotalResponse> $totals
     */
    public function __construct(
        public string $marketplaceSku,
        public int $days,
        public array $totals,
    ) {
    }
}
