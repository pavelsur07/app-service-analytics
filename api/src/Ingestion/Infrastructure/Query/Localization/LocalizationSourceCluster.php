<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Localization;

/**
 * Кластер отгрузки и его доля в штуках кластера доставки. Доли нет,
 * когда у кластера доставки мало данных (LocalizationSql::MIN_QUANTITY).
 */
final readonly class LocalizationSourceCluster
{
    public function __construct(
        public string $cluster,
        public int $quantity,
        public ?int $shareBps,
    ) {
    }
}
