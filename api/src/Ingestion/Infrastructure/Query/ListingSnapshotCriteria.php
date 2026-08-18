<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/**
 * Что искать: карточка конкретного кабинета на конкретный момент.
 *
 * Двойник `ListingSnapshotRequest` из Facade, и это не дублирование
 * по недосмотру. Запрос живёт в Infrastructure и вверх, в Application,
 * смотреть не может — зависимости строго вниз. Ровно та же причина,
 * по которой на обратном пути `ListingSnapshotRow` превращается
 * в `ListingSnapshot`: слой отдаёт свой тип, а перевод делает Facade.
 */
final readonly class ListingSnapshotCriteria
{
    public function __construct(
        public string $marketplaceSku,
        public string $marketplaceAccountId,
        public \DateTimeImmutable $at,
    ) {
    }
}
