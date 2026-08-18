<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use App\Shared\Domain\ValueObject\Money;

/**
 * Цена карточки, как её отдал `/v3/product/info/list` (ADR-015):
 * то, что выставил продавец, и зачёркнутая цена до скидки.
 *
 * Отдельный readonly DTO, а не массив: парсер и писатель говорят
 * о нескольких величинах сразу, и массив с ключами по строкам
 * разъезжается при первой же правке.
 */
final readonly class OzonListingPrice
{
    public function __construct(
        public string $marketplaceSku,
        public Money $price,
        public ?Money $oldPrice,
    ) {
    }
}
