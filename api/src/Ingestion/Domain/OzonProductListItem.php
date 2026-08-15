<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Позиция каталога: то, что о товаре известно из /v3/product/list.
 *
 * Три идентификатора вместо одного, и каждый нужен по своему поводу:
 * `sku` — то, чем карточка опознаётся у нас в таблице и в фактах продаж;
 * `offerId` — то, как товар называет сам продавец, и то, что он увидит
 * на экране; `productId` — то, чем площадка принимает запрос деталей
 * (/v3/product/info/list), и больше ни для чего он не используется.
 *
 * Наименования здесь нет: /v3/product/list его не отдаёт вовсе,
 * оно приходит вторым запросом.
 */
final readonly class OzonProductListItem
{
    public function __construct(
        public string $sku,
        public string $offerId,
        public int $productId,
    ) {
    }
}
