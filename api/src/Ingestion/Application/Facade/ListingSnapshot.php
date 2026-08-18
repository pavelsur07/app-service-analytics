<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

use App\Shared\Domain\ValueObject\Money;

/**
 * Карточка каталога глазами другого модуля на конкретный момент:
 * как называется и по какой цене стояла тогда (ADR-016).
 *
 * `price` — null, если на тот момент цены ещё не знали: история
 * начинается с выкладки ADR-015, и у наблюдения, снятого раньше первой
 * синхронизации каталога, сравнивать не с чем. Ноль вместо null
 * означал бы «товар отдавали даром», и СПП вышел бы правдоподобным
 * и неверным.
 */
final readonly class ListingSnapshot
{
    public function __construct(
        public string $marketplaceSku,
        public ?string $name,
        public ?Money $price,
    ) {
    }
}
