<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Ingestion\Infrastructure\Query\ListingCostRow;

/**
 * Страница экрана ввода себестоимости.
 *
 * Покрытие (сколько карточек с ценой из скольких всего) считается
 * по каталогу, а не по этой странице: доля, посчитанная по видимой
 * части списка, вводила бы в заблуждение ровно там, где список длинный.
 */
final readonly class ListingCostsPage
{
    /**
     * @param list<ListingCostRow> $listings
     */
    public function __construct(
        public array $listings,
        public int $listingCount,
        public int $pricedCount,
        /** Курсор следующей страницы; null — страница последняя. */
        public ?string $nextCursor,
    ) {
    }
}
