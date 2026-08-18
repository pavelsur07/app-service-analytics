<?php

declare(strict_types=1);

namespace App\PriceMonitoring\Application;

use App\Shared\Domain\ValueObject\Money;

/**
 * Строка экрана СПП: артикул, две цены и разница между ними.
 *
 * Разницу считает сервер, а не экран: арифметика над денежными
 * величинами в компонентах запрещена (CLAUDE.md §10), и правило это
 * не про стиль — на клиенте нет ни `Money`, ни проверки валют.
 */
final readonly class PriceOverviewRow
{
    public function __construct(
        public string $marketplaceSku,
        public ?string $name,
        /** Цена продавца в кабинете, действовавшая на момент снимка. */
        public ?Money $sellerPrice,
        /** Витринная цена — то, что видит покупатель. */
        public ?Money $displayedPrice,
        /**
         * Соинвест: сколько Ozon доплачивает поверх скидки продавца.
         * null, если не из чего считать — нет наблюдения либо нет цены
         * кабинета на тот момент.
         */
        public ?Money $coInvestment,
        public ?\DateTimeImmutable $observedAt,
    ) {
    }
}
