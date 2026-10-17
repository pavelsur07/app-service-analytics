<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

/**
 * Карточка каталога подключения для выбора в план продаж (ADR-040 п. 4).
 * Артикул продавца и название — подсказка человеку; `null`, пока
 * каталог их не принёс.
 */
final readonly class CatalogSku
{
    public function __construct(
        public string $marketplaceSku,
        public ?string $offerId,
        public ?string $name,
    ) {
    }
}
